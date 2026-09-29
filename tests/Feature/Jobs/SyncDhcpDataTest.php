<?php

declare(strict_types=1);

namespace Tests\Feature\Jobs;

use App\Enums\AddressFamily;
use App\Enums\Capability;
use App\Events\DhcpPoolThresholdReached;
use App\Jobs\SyncDhcpData;
use App\Models\CapabilityAssignment;
use App\Models\DhcpPoolStatusRecord;
use App\Models\DhcpSyncState;
use App\Models\IpAddress;
use App\Models\MacAddress;
use App\Services\Dhcp\DhcpSyncService;
use App\Services\Interfaces\DhcpInterface;
use App\Services\Null\NullDhcpService;
use App\Services\ValueObjects\DhcpFetchStatus;
use App\Services\ValueObjects\DhcpLease as DhcpLeaseVO;
use App\Services\ValueObjects\DhcpPoolStatus;
use App\Services\ValueObjects\DhcpRange;
use App\Services\ValueObjects\DhcpSnapshot;
use Illuminate\Contracts\Queue\ShouldBeUnique;
use Illuminate\Foundation\Testing\LazilyRefreshDatabase;
use Illuminate\Support\Facades\Date;
use Illuminate\Support\Facades\Event;
use Mockery\MockInterface;
use Tests\TestCase;

class SyncDhcpDataTest extends TestCase
{
    use LazilyRefreshDatabase;

    protected function tearDown(): void
    {
        Date::setTestNow();

        parent::tearDown();
    }

    private function assignDhcpProvider(string $integration = 'cisco'): void
    {
        CapabilityAssignment::assign(Capability::Dhcp, $integration);
    }

    /**
     * @param  list<DhcpLeaseVO>  $leases
     * @param  list<DhcpRange>  $ranges
     * @param  array{ipv4: bool, ipv6: bool, ipv4_ranges?: bool, ipv6_ranges?: bool}  $fetchStatus
     */
    private function bindDhcpServiceWithFetchStatus(
        array $leases,
        array $ranges,
        DhcpPoolStatus $poolStatus,
        array $fetchStatus,
        ?DhcpPoolStatus $ipv6PoolStatus = null,
    ): void {
        $ipv6PoolStatus ??= new DhcpPoolStatus(total: 0, used: 0, available: 0, utilisation: 0.0);

        $snapshot = DhcpSnapshot::create(
            collect($leases),
            collect($ranges),
            new DhcpFetchStatus($fetchStatus['ipv4'], $fetchStatus['ipv4_ranges'] ?? true),
            new DhcpFetchStatus($fetchStatus['ipv6'], $fetchStatus['ipv6_ranges'] ?? true),
            [AddressFamily::IPv4->value => $poolStatus, AddressFamily::IPv6->value => $ipv6PoolStatus],
        );

        $this->mock(DhcpInterface::class, function (MockInterface $mock) use ($snapshot): void {
            $mock->allows('snapshot')->andReturn($snapshot);
        });
    }

    /**
     * @param  list<DhcpLeaseVO>  $leases
     * @param  list<DhcpRange>  $ranges
     */
    private function mockDhcpService(
        array $leases = [],
        array $ranges = [],
        ?DhcpPoolStatus $poolStatus = null,
        ?DhcpPoolStatus $ipv6PoolStatus = null,
    ): void {
        $poolStatus ??= new DhcpPoolStatus(total: 0, used: 0, available: 0, utilisation: 0.0);
        $ipv6PoolStatus ??= new DhcpPoolStatus(total: 0, used: 0, available: 0, utilisation: 0.0);

        $snapshot = DhcpSnapshot::create(
            collect($leases),
            collect($ranges),
            poolOverrides: [AddressFamily::IPv4->value => $poolStatus, AddressFamily::IPv6->value => $ipv6PoolStatus],
        );

        $this->mock(DhcpInterface::class, function (MockInterface $mock) use ($snapshot): void {
            $mock->allows('snapshot')->andReturn($snapshot);
        });
    }

    private function dispatchSyncJob(): void
    {
        $job = new SyncDhcpData;
        app()->call([$job, 'handle']);
    }

    public function test_implements_should_be_unique(): void
    {
        $job = new SyncDhcpData;

        $this->assertInstanceOf(ShouldBeUnique::class, $job);
    }

    public function test_does_nothing_when_no_dhcp_capability_assigned(): void
    {
        $this->app->bind(DhcpInterface::class, NullDhcpService::class);

        $this->dispatchSyncJob();

        $this->assertDatabaseCount('dhcp_leases', 0);
        $this->assertDatabaseCount('dhcp_range_records', 0);
        $this->assertDatabaseCount('dhcp_pool_statuses', 0);
    }

    public function test_does_nothing_when_provider_is_null_service(): void
    {
        $this->assignDhcpProvider('cisco');
        $this->app->bind(DhcpInterface::class, NullDhcpService::class);

        $this->dispatchSyncJob();

        $this->assertDatabaseCount('dhcp_leases', 0);
        $this->assertDatabaseCount('dhcp_range_records', 0);
        $this->assertDatabaseCount('dhcp_pool_statuses', 0);
    }

    public function test_syncs_leases_from_provider_to_db(): void
    {
        $this->assignDhcpProvider('cisco');
        $this->mockDhcpService(
            leases: [
                new DhcpLeaseVO('10.0.0.1', 'AA:BB:CC:DD:EE:01', 'host1', '2026-06-09 00:00:00'),
                new DhcpLeaseVO('10.0.0.2', 'AA:BB:CC:DD:EE:02', 'host2', '2026-06-09 12:00:00'),
            ],
        );

        $this->dispatchSyncJob();

        $this->assertDatabaseHas('ip_addresses', ['address' => '10.0.0.1']);
        $this->assertDatabaseHas('ip_addresses', ['address' => '10.0.0.2']);
        $this->assertDatabaseHas('mac_addresses', ['mac_address' => 'AA:BB:CC:DD:EE:01']);
        $this->assertDatabaseHas('mac_addresses', ['mac_address' => 'AA:BB:CC:DD:EE:02']);
        $this->assertDatabaseCount('dhcp_leases', 2);

        $ip = IpAddress::where('address', '10.0.0.1')->firstOrFail();
        $mac = MacAddress::where('mac_address', 'AA:BB:CC:DD:EE:01')->firstOrFail();
        $this->assertDatabaseHas('dhcp_leases', [
            'integration' => 'cisco',
            'ip_address_id' => $ip->id,
            'mac_address_id' => $mac->id,
            'hostname' => 'host1',
        ]);
    }

    public function test_syncs_ranges_from_provider_to_db(): void
    {
        $this->assignDhcpProvider('cisco');
        $this->mockDhcpService(
            ranges: [
                new DhcpRange(
                    interface: 'Vlan100',
                    type: AddressFamily::IPv4,
                    subnet: '10.0.0.0/24',
                    rangeFrom: '10.0.0.10',
                    rangeTo: '10.0.0.200',
                    prefix: null,
                    gateway: '10.0.0.1',
                    description: 'Main LAN',
                    totalAddresses: '191',
                    usedAddresses: 50,
                    utilisation: 0.2618,
                ),
            ],
        );

        $this->dispatchSyncJob();

        $this->assertDatabaseCount('dhcp_range_records', 1);
        $this->assertDatabaseHas('dhcp_range_records', [
            'integration' => 'cisco',
            'interface' => 'Vlan100',
            'type' => 'ipv4',
            'subnet' => '10.0.0.0/24',
            'range_from' => '10.0.0.10',
            'range_to' => '10.0.0.200',
            'total_addresses' => '191',
        ]);
    }

    public function test_persists_huge_ipv6_range_totals_exactly(): void
    {
        $this->assignDhcpProvider('cisco');
        $this->mockDhcpService(
            ranges: [
                new DhcpRange(
                    interface: 'VLAN400_DHCPV6',
                    type: AddressFamily::IPv6,
                    subnet: null,
                    rangeFrom: null,
                    rangeTo: null,
                    prefix: '2a0f:85c1:d91:2100::/64',
                    gateway: null,
                    description: 'V6 LAN',
                    totalAddresses: '18446744073709551616',
                    usedAddresses: 3,
                    utilisation: 0.0,
                ),
            ],
        );

        $this->dispatchSyncJob();

        $this->assertDatabaseCount('dhcp_range_records', 1);
        $this->assertDatabaseHas('dhcp_range_records', [
            'integration' => 'cisco',
            'interface' => 'VLAN400_DHCPV6',
            'type' => 'ipv6',
            'prefix' => '2a0f:85c1:d91:2100::/64',
            'total_addresses' => '18446744073709551616',
            'used_addresses' => '3',
        ]);
    }

    public function test_syncs_pool_status_to_db(): void
    {
        $this->assignDhcpProvider('cisco');
        $this->mockDhcpService(
            poolStatus: new DhcpPoolStatus(total: 254, used: 100, available: 154, utilisation: 0.3937),
        );

        $this->dispatchSyncJob();

        $this->assertDatabaseCount('dhcp_pool_statuses', 1);
        $this->assertDatabaseHas('dhcp_pool_statuses', [
            'integration' => 'cisco',
            'address_family' => 'ipv4',
        ]);
    }

    public function test_deletes_stale_leases(): void
    {
        $this->assignDhcpProvider('cisco');

        $this->mockDhcpService(
            leases: [
                new DhcpLeaseVO('10.0.0.1', 'AA:BB:CC:DD:EE:01', 'host1', '2026-06-09 00:00:00'),
                new DhcpLeaseVO('10.0.0.2', 'AA:BB:CC:DD:EE:02', 'host2', '2026-06-09 12:00:00'),
            ],
        );

        $this->dispatchSyncJob();
        $this->assertDatabaseCount('dhcp_leases', 2);

        $this->mockDhcpService(
            leases: [
                new DhcpLeaseVO('10.0.0.1', 'AA:BB:CC:DD:EE:01', 'host1', '2026-06-09 00:00:00'),
            ],
        );

        $this->dispatchSyncJob();

        $this->assertDatabaseCount('dhcp_leases', 1);
        $ip = IpAddress::where('address', '10.0.0.1')->firstOrFail();
        $this->assertDatabaseHas('dhcp_leases', [
            'integration' => 'cisco',
            'ip_address_id' => $ip->id,
        ]);
    }

    public function test_deletes_stale_ranges(): void
    {
        $this->assignDhcpProvider('cisco');

        $range1 = new DhcpRange(
            interface: 'Vlan100',
            type: AddressFamily::IPv4,
            subnet: '10.0.0.0/24',
            rangeFrom: '10.0.0.10',
            rangeTo: '10.0.0.200',
            prefix: null,
            gateway: '10.0.0.1',
            description: 'Main LAN',
        );
        $range2 = new DhcpRange(
            interface: 'Vlan200',
            type: AddressFamily::IPv4,
            subnet: '10.0.1.0/24',
            rangeFrom: '10.0.1.10',
            rangeTo: '10.0.1.200',
            prefix: null,
            gateway: '10.0.1.1',
            description: 'Secondary LAN',
        );

        $this->mockDhcpService(ranges: [$range1, $range2]);
        $this->dispatchSyncJob();
        $this->assertDatabaseCount('dhcp_range_records', 2);

        $this->mockDhcpService(ranges: [$range1]);
        $this->dispatchSyncJob();

        $this->assertDatabaseCount('dhcp_range_records', 1);
        $this->assertDatabaseHas('dhcp_range_records', [
            'integration' => 'cisco',
            'subnet' => '10.0.0.0/24',
        ]);
    }

    public function test_syncs_multiple_ipv6_ranges_with_null_subnet_and_bounds(): void
    {
        $this->assignDhcpProvider('cisco');

        $ranges = [
            new DhcpRange(
                interface: 'VLAN440_DHCPV6',
                type: AddressFamily::IPv6,
                subnet: null,
                rangeFrom: null,
                rangeTo: null,
                prefix: '2A0F:85C1:D91:2100::/64',
                gateway: null,
                description: null,
            ),
            new DhcpRange(
                interface: 'VLAN400_DHCPV6',
                type: AddressFamily::IPv6,
                subnet: null,
                rangeFrom: null,
                rangeTo: null,
                prefix: '2A0F:85C1:D91:2000::/64',
                gateway: null,
                description: null,
            ),
        ];

        $this->mockDhcpService(ranges: $ranges);
        $this->dispatchSyncJob();

        $this->assertDatabaseCount('dhcp_range_records', 2);
        $this->assertDatabaseHas('dhcp_range_records', [
            'integration' => 'cisco',
            'type' => 'ipv6',
            'interface' => 'VLAN440_DHCPV6',
            'prefix' => '2A0F:85C1:D91:2100::/64',
        ]);
        $this->assertDatabaseHas('dhcp_range_records', [
            'integration' => 'cisco',
            'type' => 'ipv6',
            'interface' => 'VLAN400_DHCPV6',
            'prefix' => '2A0F:85C1:D91:2000::/64',
        ]);

        $this->mockDhcpService(ranges: $ranges);
        $this->dispatchSyncJob();

        $this->assertDatabaseCount('dhcp_range_records', 2);
    }

    public function test_handles_nullable_mac_for_dhcpv6(): void
    {
        $this->assignDhcpProvider('cisco');
        $this->mockDhcpService(
            leases: [
                new DhcpLeaseVO('2001:db8::1', null, 'host-v6', '2026-06-09 00:00:00'),
            ],
        );

        $this->dispatchSyncJob();

        $this->assertDatabaseCount('dhcp_leases', 1);
        $ip = IpAddress::where('address', '2001:db8::1')->firstOrFail();
        $this->assertDatabaseHas('dhcp_leases', [
            'integration' => 'cisco',
            'ip_address_id' => $ip->id,
            'mac_address_id' => null,
            'hostname' => 'host-v6',
        ]);
    }

    public function test_empty_result_guard_skips_deletion_on_first_empty(): void
    {
        $this->assignDhcpProvider('cisco');

        $this->mockDhcpService(
            leases: [
                new DhcpLeaseVO('10.0.0.1', 'AA:BB:CC:DD:EE:01', 'host1', '2026-06-09 00:00:00'),
            ],
        );
        $this->dispatchSyncJob();
        $this->assertDatabaseCount('dhcp_leases', 1);

        $this->mockDhcpService(leases: []);
        $this->dispatchSyncJob();

        $this->assertDatabaseCount('dhcp_leases', 1);

        $syncState = DhcpSyncState::where([
            'integration' => 'cisco',
            'dataset' => 'leases',
            'address_family' => 'ipv4',
        ])->first();
        $this->assertNotNull($syncState);
        $this->assertSame(1, $syncState->empty_count);
    }

    public function test_empty_result_guard_deletes_after_three_consecutive_empties(): void
    {
        $this->assignDhcpProvider('cisco');

        $this->mockDhcpService(
            leases: [
                new DhcpLeaseVO('10.0.0.1', 'AA:BB:CC:DD:EE:01', 'host1', '2026-06-09 00:00:00'),
            ],
        );
        $this->dispatchSyncJob();
        $this->assertDatabaseCount('dhcp_leases', 1);

        for ($i = 0; $i < 3; $i++) {
            $this->mockDhcpService(leases: []);
            $this->dispatchSyncJob();
        }

        $this->assertDatabaseCount('dhcp_leases', 0);

        $syncState = DhcpSyncState::where([
            'integration' => 'cisco',
            'dataset' => 'leases',
            'address_family' => 'ipv4',
        ])->first();
        $this->assertNotNull($syncState);
        $this->assertSame(0, $syncState->empty_count);
    }

    public function test_empty_range_guard_deletes_after_three_consecutive_empties(): void
    {
        $this->assignDhcpProvider('cisco');

        $this->mockDhcpService(
            ranges: [
                new DhcpRange(
                    interface: 'Vlan100',
                    type: AddressFamily::IPv4,
                    subnet: '10.0.0.0/24',
                    rangeFrom: '10.0.0.10',
                    rangeTo: '10.0.0.200',
                    prefix: null,
                    gateway: '10.0.0.1',
                    description: 'Main LAN',
                ),
            ],
        );
        $this->dispatchSyncJob();
        $this->assertDatabaseCount('dhcp_range_records', 1);

        for ($i = 0; $i < 3; $i++) {
            $this->mockDhcpService(ranges: []);
            $this->dispatchSyncJob();
        }

        $this->assertDatabaseCount('dhcp_range_records', 0);

        $syncState = DhcpSyncState::where([
            'integration' => 'cisco',
            'dataset' => 'ranges',
            'address_family' => 'ipv4',
        ])->first();
        $this->assertNotNull($syncState);
        $this->assertSame(0, $syncState->empty_count);
    }

    public function test_pool_status_clamps_when_used_exceeds_total(): void
    {
        $this->assignDhcpProvider('cisco');
        $this->mockDhcpService(
            poolStatus: new DhcpPoolStatus(total: 100, used: 150, available: 0, utilisation: 1.5),
        );

        $this->dispatchSyncJob();

        $record = DhcpPoolStatusRecord::firstOrFail();
        $this->assertSame(0.0, (float) $record->available);
        $this->assertSame(1.0, (float) $record->utilisation);
    }

    public function test_pool_status_clamps_negative_utilisation_to_zero(): void
    {
        $this->assignDhcpProvider('cisco');
        $this->mockDhcpService(
            poolStatus: new DhcpPoolStatus(total: 100, used: -50, available: 150, utilisation: -0.5),
        );

        $this->dispatchSyncJob();

        $record = DhcpPoolStatusRecord::firstOrFail();
        $this->assertSame(150.0, (float) $record->available);
        $this->assertSame(0.0, (float) $record->utilisation);
    }

    public function test_sync_state_timestamps_updated_correctly(): void
    {
        $this->assignDhcpProvider('cisco');
        $this->mockDhcpService(
            leases: [
                new DhcpLeaseVO('10.0.0.1', 'AA:BB:CC:DD:EE:01', 'host1', '2026-06-09 00:00:00'),
            ],
        );

        $this->dispatchSyncJob();

        $syncState = DhcpSyncState::where([
            'integration' => 'cisco',
            'dataset' => 'leases',
            'address_family' => 'ipv4',
        ])->first();

        $this->assertNotNull($syncState);
        $this->assertNotNull($syncState->last_attempt_at);
        $this->assertNotNull($syncState->last_success_at);
        $this->assertSame(0, $syncState->empty_count);
    }

    public function test_threshold_event_fired_on_crossing(): void
    {
        Event::fake([DhcpPoolThresholdReached::class]);

        $this->assignDhcpProvider('cisco');

        $this->mockDhcpService(
            poolStatus: new DhcpPoolStatus(total: 100, used: 50, available: 50, utilisation: 0.5),
        );
        $this->dispatchSyncJob();

        Event::assertNotDispatched(DhcpPoolThresholdReached::class);

        $this->mockDhcpService(
            poolStatus: new DhcpPoolStatus(total: 100, used: 85, available: 15, utilisation: 0.85),
        );
        $this->dispatchSyncJob();

        Event::assertDispatched(DhcpPoolThresholdReached::class, function (DhcpPoolThresholdReached $event): bool {
            return $event->usage === 0.85 && $event->threshold === 0.8;
        });
    }

    public function test_threshold_event_not_fired_when_sustained_above(): void
    {
        Event::fake([DhcpPoolThresholdReached::class]);

        $this->assignDhcpProvider('cisco');

        $this->mockDhcpService(
            poolStatus: new DhcpPoolStatus(total: 100, used: 90, available: 10, utilisation: 0.9),
        );
        $this->dispatchSyncJob();

        Event::fake([DhcpPoolThresholdReached::class]);

        $this->mockDhcpService(
            poolStatus: new DhcpPoolStatus(total: 100, used: 95, available: 5, utilisation: 0.95),
        );
        $this->dispatchSyncJob();

        Event::assertNotDispatched(DhcpPoolThresholdReached::class);
    }

    public function test_ipv6_fetch_failure_leaves_ipv6_leases_untouched_while_ipv4_updates(): void
    {
        $this->assignDhcpProvider('cisco');

        Date::setTestNow(Date::parse('2026-01-01 00:00:00'));

        $this->bindDhcpServiceWithFetchStatus(
            leases: [
                new DhcpLeaseVO('10.0.0.1', 'AA:BB:CC:DD:EE:01', 'host1', '2026-06-09 00:00:00'),
                new DhcpLeaseVO('2001:db8::1', 'AA:BB:CC:DD:EE:02', 'host-v6', '2026-06-09 00:00:00'),
            ],
            ranges: [],
            poolStatus: new DhcpPoolStatus(total: 0, used: 0, available: 0, utilisation: 0.0),
            fetchStatus: ['ipv4' => true, 'ipv6' => true],
        );
        $this->dispatchSyncJob();

        $this->assertDatabaseCount('dhcp_leases', 2);
        $ipv6SyncStateAfterFirstRun = DhcpSyncState::where([
            'integration' => 'cisco',
            'dataset' => 'leases',
            'address_family' => 'ipv6',
        ])->firstOrFail();
        $firstRunSuccessAt = $ipv6SyncStateAfterFirstRun->last_success_at;
        $this->assertNotNull($firstRunSuccessAt);

        Date::setTestNow(Date::parse('2026-01-01 00:05:00'));

        $this->bindDhcpServiceWithFetchStatus(
            leases: [
                new DhcpLeaseVO('10.0.0.1', 'AA:BB:CC:DD:EE:01', 'host1-renamed', '2026-06-10 00:00:00'),
            ],
            ranges: [],
            poolStatus: new DhcpPoolStatus(total: 0, used: 0, available: 0, utilisation: 0.0),
            fetchStatus: ['ipv4' => true, 'ipv6' => false],
        );
        $this->dispatchSyncJob();

        $this->assertDatabaseHas('dhcp_leases', [
            'integration' => 'cisco',
            'hostname' => 'host1-renamed',
        ]);

        $this->assertDatabaseCount('dhcp_leases', 2);
        $ipv6Ip = IpAddress::where('address', '2001:db8::1')->firstOrFail();
        $this->assertDatabaseHas('dhcp_leases', [
            'integration' => 'cisco',
            'ip_address_id' => $ipv6Ip->id,
            'hostname' => 'host-v6',
        ]);

        $ipv6SyncStateAfterSecondRun = DhcpSyncState::where([
            'integration' => 'cisco',
            'dataset' => 'leases',
            'address_family' => 'ipv6',
        ])->firstOrFail();
        $this->assertTrue($ipv6SyncStateAfterSecondRun->last_attempt_at->equalTo(Date::parse('2026-01-01 00:05:00')));
        $this->assertTrue($ipv6SyncStateAfterSecondRun->last_success_at->equalTo($firstRunSuccessAt));
    }

    public function test_ipv4_fetch_failure_leaves_ipv4_leases_ranges_and_pool_status_untouched_while_ipv6_updates(): void
    {
        $this->assignDhcpProvider('cisco');

        Date::setTestNow(Date::parse('2026-01-01 00:00:00'));

        $this->bindDhcpServiceWithFetchStatus(
            leases: [
                new DhcpLeaseVO('10.0.0.1', 'AA:BB:CC:DD:EE:01', 'host1', '2026-06-09 00:00:00'),
                new DhcpLeaseVO('2001:db8::1', 'AA:BB:CC:DD:EE:02', 'host-v6', '2026-06-09 00:00:00'),
            ],
            ranges: [
                new DhcpRange(
                    interface: 'Vlan100',
                    type: AddressFamily::IPv4,
                    subnet: '10.0.0.0/24',
                    rangeFrom: '10.0.0.10',
                    rangeTo: '10.0.0.200',
                    prefix: null,
                    gateway: '10.0.0.1',
                    description: 'Main LAN',
                ),
            ],
            poolStatus: new DhcpPoolStatus(total: 254, used: 100, available: 154, utilisation: 0.3937),
            fetchStatus: ['ipv4' => true, 'ipv6' => true],
        );
        $this->dispatchSyncJob();

        $this->assertDatabaseCount('dhcp_leases', 2);
        $this->assertDatabaseCount('dhcp_range_records', 1);
        $poolStatusRecordBefore = DhcpPoolStatusRecord::where('integration', 'cisco')->firstOrFail();
        $ipv4LeaseSyncStateBefore = DhcpSyncState::where([
            'integration' => 'cisco', 'dataset' => 'leases', 'address_family' => 'ipv4',
        ])->firstOrFail();
        $rangeSyncStateBefore = DhcpSyncState::where([
            'integration' => 'cisco', 'dataset' => 'ranges', 'address_family' => 'ipv4',
        ])->firstOrFail();
        $poolStatusSyncStateBefore = DhcpSyncState::where([
            'integration' => 'cisco', 'dataset' => 'pool_status', 'address_family' => 'ipv4',
        ])->firstOrFail();
        $ipv4SuccessAt = $ipv4LeaseSyncStateBefore->last_success_at;
        $rangeSuccessAt = $rangeSyncStateBefore->last_success_at;
        $poolStatusSuccessAt = $poolStatusSyncStateBefore->last_success_at;

        Date::setTestNow(Date::parse('2026-01-01 00:05:00'));

        $this->bindDhcpServiceWithFetchStatus(
            leases: [
                new DhcpLeaseVO('2001:db8::1', 'AA:BB:CC:DD:EE:02', 'host-v6-renamed', '2026-06-10 00:00:00'),
            ],
            ranges: [],
            poolStatus: new DhcpPoolStatus(total: 999, used: 999, available: 0, utilisation: 1.0),
            fetchStatus: ['ipv4' => false, 'ipv6' => true],
        );
        $this->dispatchSyncJob();

        $this->assertDatabaseHas('dhcp_leases', [
            'integration' => 'cisco',
            'hostname' => 'host-v6-renamed',
        ]);

        $this->assertDatabaseCount('dhcp_leases', 2);
        $ipv4Ip = IpAddress::where('address', '10.0.0.1')->firstOrFail();
        $this->assertDatabaseHas('dhcp_leases', [
            'integration' => 'cisco',
            'ip_address_id' => $ipv4Ip->id,
            'hostname' => 'host1',
        ]);

        $this->assertDatabaseCount('dhcp_range_records', 1);
        $this->assertDatabaseHas('dhcp_range_records', ['integration' => 'cisco', 'subnet' => '10.0.0.0/24']);
        $poolStatusRecordAfter = DhcpPoolStatusRecord::where('integration', 'cisco')->firstOrFail();
        $this->assertSame($poolStatusRecordBefore->total, $poolStatusRecordAfter->total);
        $this->assertSame($poolStatusRecordBefore->used, $poolStatusRecordAfter->used);

        $ipv4LeaseSyncStateAfter = DhcpSyncState::where([
            'integration' => 'cisco', 'dataset' => 'leases', 'address_family' => 'ipv4',
        ])->firstOrFail();
        $rangeSyncStateAfter = DhcpSyncState::where([
            'integration' => 'cisco', 'dataset' => 'ranges', 'address_family' => 'ipv4',
        ])->firstOrFail();
        $poolStatusSyncStateAfter = DhcpSyncState::where([
            'integration' => 'cisco', 'dataset' => 'pool_status', 'address_family' => 'ipv4',
        ])->firstOrFail();

        $this->assertTrue($ipv4LeaseSyncStateAfter->last_attempt_at->equalTo(Date::parse('2026-01-01 00:05:00')));
        $this->assertTrue($ipv4LeaseSyncStateAfter->last_success_at->equalTo($ipv4SuccessAt));
        $this->assertTrue($rangeSyncStateAfter->last_attempt_at->equalTo(Date::parse('2026-01-01 00:05:00')));
        $this->assertTrue($rangeSyncStateAfter->last_success_at->equalTo($rangeSuccessAt));
        $this->assertTrue($poolStatusSyncStateAfter->last_attempt_at->equalTo(Date::parse('2026-01-01 00:05:00')));
        $this->assertTrue($poolStatusSyncStateAfter->last_success_at->equalTo($poolStatusSuccessAt));
    }

    public function test_ipv4_ranges_fetch_failure_leaves_ipv4_leases_synced_while_ranges_and_pool_status_report_failure(): void
    {
        $this->assignDhcpProvider('cisco');

        Date::setTestNow(Date::parse('2026-01-01 00:00:00'));

        $this->bindDhcpServiceWithFetchStatus(
            leases: [
                new DhcpLeaseVO('10.0.0.1', 'AA:BB:CC:DD:EE:01', 'host1', '2026-06-09 00:00:00'),
            ],
            ranges: [],
            poolStatus: new DhcpPoolStatus(total: 0, used: 0, available: 0, utilisation: 0.0),
            fetchStatus: ['ipv4' => true, 'ipv6' => true, 'ipv4_ranges' => false],
        );
        $this->dispatchSyncJob();

        $this->assertDatabaseCount('dhcp_leases', 1);
        $this->assertDatabaseHas('dhcp_leases', [
            'integration' => 'cisco',
            'hostname' => 'host1',
        ]);
        $this->assertDatabaseCount('dhcp_range_records', 0);

        $leaseSyncState = DhcpSyncState::where([
            'integration' => 'cisco', 'dataset' => 'leases', 'address_family' => 'ipv4',
        ])->firstOrFail();
        $rangeSyncState = DhcpSyncState::where([
            'integration' => 'cisco', 'dataset' => 'ranges', 'address_family' => 'ipv4',
        ])->firstOrFail();
        $poolStatusSyncState = DhcpSyncState::where([
            'integration' => 'cisco', 'dataset' => 'pool_status', 'address_family' => 'ipv4',
        ])->firstOrFail();

        $this->assertNotNull($leaseSyncState->last_success_at);
        $this->assertNull($rangeSyncState->last_success_at);
        $this->assertNull($poolStatusSyncState->last_success_at);
    }

    public function test_stale_lease_deletion_is_scoped_to_its_own_address_family(): void
    {
        $this->assignDhcpProvider('cisco');

        $this->bindDhcpServiceWithFetchStatus(
            leases: [
                new DhcpLeaseVO('10.0.0.1', 'AA:BB:CC:DD:EE:01', 'host1', '2026-06-09 00:00:00'),
                new DhcpLeaseVO('2001:db8::1', 'AA:BB:CC:DD:EE:02', 'host-v6-1', '2026-06-09 00:00:00'),
            ],
            ranges: [],
            poolStatus: new DhcpPoolStatus(total: 0, used: 0, available: 0, utilisation: 0.0),
            fetchStatus: ['ipv4' => true, 'ipv6' => true],
        );
        $this->dispatchSyncJob();
        $this->assertDatabaseCount('dhcp_leases', 2);

        $this->bindDhcpServiceWithFetchStatus(
            leases: [
                new DhcpLeaseVO('10.0.0.1', 'AA:BB:CC:DD:EE:01', 'host1', '2026-06-09 00:00:00'),
                new DhcpLeaseVO('2001:db8::2', 'AA:BB:CC:DD:EE:03', 'host-v6-2', '2026-06-09 00:00:00'),
            ],
            ranges: [],
            poolStatus: new DhcpPoolStatus(total: 0, used: 0, available: 0, utilisation: 0.0),
            fetchStatus: ['ipv4' => true, 'ipv6' => true],
        );
        $this->dispatchSyncJob();

        $this->assertDatabaseCount('dhcp_leases', 2);
        $this->assertDatabaseHas('ip_addresses', ['address' => '10.0.0.1']);
        $this->assertDatabaseHas('ip_addresses', ['address' => '2001:db8::2']);
        $staleIp = IpAddress::where('address', '2001:db8::1')->first();
        if ($staleIp !== null) {
            $this->assertDatabaseMissing('dhcp_leases', ['ip_address_id' => $staleIp->id]);
        }

        $this->bindDhcpServiceWithFetchStatus(
            leases: [
                new DhcpLeaseVO('10.0.0.2', 'AA:BB:CC:DD:EE:04', 'host1-new', '2026-06-09 00:00:00'),
                new DhcpLeaseVO('2001:db8::2', 'AA:BB:CC:DD:EE:03', 'host-v6-2', '2026-06-09 00:00:00'),
            ],
            ranges: [],
            poolStatus: new DhcpPoolStatus(total: 0, used: 0, available: 0, utilisation: 0.0),
            fetchStatus: ['ipv4' => true, 'ipv6' => true],
        );
        $this->dispatchSyncJob();

        $this->assertDatabaseCount('dhcp_leases', 2);
        $this->assertDatabaseHas('ip_addresses', ['address' => '10.0.0.2']);
        $this->assertDatabaseHas('ip_addresses', ['address' => '2001:db8::2']);
        $staleIpv4 = IpAddress::where('address', '10.0.0.1')->first();
        if ($staleIpv4 !== null) {
            $this->assertDatabaseMissing('dhcp_leases', ['ip_address_id' => $staleIpv4->id]);
        }

        $currentIpv6 = IpAddress::where('address', '2001:db8::2')->firstOrFail();
        $this->assertDatabaseHas('dhcp_leases', ['ip_address_id' => $currentIpv6->id, 'hostname' => 'host-v6-2']);
    }

    public function test_ipv6_range_fetch_failure_preserves_ipv4_and_previously_synced_ipv6_ranges(): void
    {
        $this->assignDhcpProvider('cisco');

        Date::setTestNow(Date::parse('2026-01-01 00:00:00'));

        $ipv4Range = new DhcpRange(
            interface: 'Vlan100',
            type: AddressFamily::IPv4,
            subnet: '10.0.0.0/24',
            rangeFrom: '10.0.0.10',
            rangeTo: '10.0.0.200',
            prefix: null,
            gateway: '10.0.0.1',
            description: 'Main LAN',
            totalAddresses: '191',
            usedAddresses: 50,
            utilisation: 0.2618,
        );
        $ipv6Range = new DhcpRange(
            interface: 'VLAN400_DHCPV6',
            type: AddressFamily::IPv6,
            subnet: null,
            rangeFrom: null,
            rangeTo: null,
            prefix: '2001:db8::/64',
            gateway: null,
            description: 'V6 LAN',
            totalAddresses: '18446744073709551616',
            usedAddresses: 3,
            utilisation: 0.0,
        );

        $this->bindDhcpServiceWithFetchStatus(
            leases: [],
            ranges: [$ipv4Range, $ipv6Range],
            poolStatus: new DhcpPoolStatus(total: 191, used: 50, available: 141, utilisation: 0.2618),
            fetchStatus: ['ipv4' => true, 'ipv6' => true],
            ipv6PoolStatus: new DhcpPoolStatus(total: PHP_INT_MAX, used: 3, available: PHP_INT_MAX - 3, utilisation: 0.0),
        );
        $this->dispatchSyncJob();

        $this->assertDatabaseCount('dhcp_range_records', 2);
        $ipv6RangeSyncStateBefore = DhcpSyncState::where([
            'integration' => 'cisco', 'dataset' => 'ranges', 'address_family' => 'ipv6',
        ])->firstOrFail();
        $ipv6SuccessAt = $ipv6RangeSyncStateBefore->last_success_at;
        $this->assertNotNull($ipv6SuccessAt);

        Date::setTestNow(Date::parse('2026-01-01 00:05:00'));

        $fetchStatusWithIpv6RangesFailing = ['ipv4' => true, 'ipv6' => true, 'ipv6_ranges' => false];
        $this->bindDhcpServiceWithFetchStatus(
            leases: [],
            ranges: [$ipv4Range],
            poolStatus: new DhcpPoolStatus(total: 191, used: 50, available: 141, utilisation: 0.2618),
            fetchStatus: $fetchStatusWithIpv6RangesFailing,
        );
        $this->dispatchSyncJob();

        $this->assertDatabaseCount('dhcp_range_records', 2);
        $this->assertDatabaseHas('dhcp_range_records', [
            'integration' => 'cisco', 'type' => 'ipv6', 'interface' => 'VLAN400_DHCPV6',
        ]);
        $this->assertDatabaseHas('dhcp_range_records', [
            'integration' => 'cisco', 'type' => 'ipv4', 'subnet' => '10.0.0.0/24',
        ]);
        $this->assertDatabaseHas('dhcp_pool_statuses', [
            'integration' => 'cisco', 'address_family' => 'ipv6', 'used' => '3',
        ]);

        $ipv6RangeSyncStateAfter = DhcpSyncState::where([
            'integration' => 'cisco', 'dataset' => 'ranges', 'address_family' => 'ipv6',
        ])->firstOrFail();
        $this->assertTrue($ipv6RangeSyncStateAfter->last_attempt_at->equalTo(Date::parse('2026-01-01 00:05:00')));
        $this->assertTrue($ipv6RangeSyncStateAfter->last_success_at->equalTo($ipv6SuccessAt));
    }

    public function test_ipv4_lease_fetch_failure_does_not_hide_ipv6_range_sync(): void
    {
        $this->assignDhcpProvider('cisco');

        $ipv6Range = new DhcpRange(
            interface: 'VLAN400_DHCPV6',
            type: AddressFamily::IPv6,
            subnet: null,
            rangeFrom: null,
            rangeTo: null,
            prefix: '2001:db8::/64',
            gateway: null,
            description: 'V6 LAN',
            totalAddresses: '100',
            usedAddresses: 10,
            utilisation: 0.1,
        );

        $this->bindDhcpServiceWithFetchStatus(
            leases: [],
            ranges: [$ipv6Range],
            poolStatus: new DhcpPoolStatus(total: 0, used: 0, available: 0, utilisation: 0.0),
            fetchStatus: ['ipv4' => false, 'ipv6' => true],
        );
        $this->dispatchSyncJob();

        $this->assertDatabaseCount('dhcp_range_records', 1);
        $this->assertDatabaseHas('dhcp_range_records', [
            'integration' => 'cisco',
            'type' => 'ipv6',
            'interface' => 'VLAN400_DHCPV6',
            'total_addresses' => '100',
        ]);

        $ipv4RangeSyncState = DhcpSyncState::where([
            'integration' => 'cisco', 'dataset' => 'ranges', 'address_family' => 'ipv4',
        ])->firstOrFail();
        $this->assertNull($ipv4RangeSyncState->last_success_at);
    }

    public function test_pool_status_sync_produces_independent_rows_per_address_family(): void
    {
        $this->assignDhcpProvider('cisco');

        $ipv6Range = new DhcpRange(
            interface: 'VLAN400_DHCPV6',
            type: AddressFamily::IPv6,
            subnet: null,
            rangeFrom: null,
            rangeTo: null,
            prefix: '2001:db8::/64',
            gateway: null,
            description: 'V6 LAN',
        );

        $this->bindDhcpServiceWithFetchStatus(
            leases: [],
            ranges: [$ipv6Range],
            poolStatus: new DhcpPoolStatus(total: 254, used: 100, available: 154, utilisation: 0.3937),
            fetchStatus: ['ipv4' => true, 'ipv6' => true],
            ipv6PoolStatus: new DhcpPoolStatus(total: PHP_INT_MAX, used: 3, available: PHP_INT_MAX - 3, utilisation: 0.0),
        );
        $this->dispatchSyncJob();

        $this->assertDatabaseCount('dhcp_pool_statuses', 2);
        $this->assertDatabaseHas('dhcp_pool_statuses', [
            'integration' => 'cisco',
            'address_family' => 'ipv4',
            'total' => '254',
            'used' => '100',
        ]);

        $ipv4Record = DhcpPoolStatusRecord::where(['integration' => 'cisco', 'address_family' => 'ipv4'])->firstOrFail();
        $this->assertSame(0.3937, (float) $ipv4Record->utilisation);

        $ipv6Record = DhcpPoolStatusRecord::where(['integration' => 'cisco', 'address_family' => 'ipv6'])->firstOrFail();
        $this->assertSame(PHP_INT_MAX, (int) $ipv6Record->total);
        $this->assertSame(0.0, (float) $ipv6Record->utilisation);
    }

    public function test_threshold_event_fires_for_ipv4_only(): void
    {
        Event::fake([DhcpPoolThresholdReached::class]);

        $this->assignDhcpProvider('cisco');

        $ipv6Range = new DhcpRange(
            interface: 'VLAN400_DHCPV6',
            type: AddressFamily::IPv6,
            subnet: null,
            rangeFrom: null,
            rangeTo: null,
            prefix: '2001:db8::/64',
            gateway: null,
            description: 'V6 LAN',
        );

        $this->bindDhcpServiceWithFetchStatus(
            leases: [],
            ranges: [$ipv6Range],
            poolStatus: new DhcpPoolStatus(total: 100, used: 50, available: 50, utilisation: 0.5),
            fetchStatus: ['ipv4' => true, 'ipv6' => true],
            ipv6PoolStatus: new DhcpPoolStatus(total: 100, used: 50, available: 50, utilisation: 0.5),
        );
        $this->dispatchSyncJob();

        Event::assertNotDispatched(DhcpPoolThresholdReached::class);

        $this->bindDhcpServiceWithFetchStatus(
            leases: [],
            ranges: [$ipv6Range],
            poolStatus: new DhcpPoolStatus(total: 100, used: 85, available: 15, utilisation: 0.85),
            fetchStatus: ['ipv4' => true, 'ipv6' => true],
            ipv6PoolStatus: new DhcpPoolStatus(total: 100, used: 50, available: 50, utilisation: 0.5),
        );
        $this->dispatchSyncJob();

        Event::assertDispatched(DhcpPoolThresholdReached::class, 1);
        Event::assertDispatched(DhcpPoolThresholdReached::class, function (DhcpPoolThresholdReached $event): bool {
            return $event->addressFamily === 'ipv4' && $event->usage === 0.85;
        });
    }

    public function test_threshold_event_does_not_refire_once_already_crossed(): void
    {
        Event::fake([DhcpPoolThresholdReached::class]);

        $this->assignDhcpProvider('cisco');

        $ipv6Range = new DhcpRange(
            interface: 'VLAN400_DHCPV6',
            type: AddressFamily::IPv6,
            subnet: null,
            rangeFrom: null,
            rangeTo: null,
            prefix: '2001:db8::/64',
            gateway: null,
            description: 'V6 LAN',
        );

        $this->bindDhcpServiceWithFetchStatus(
            leases: [],
            ranges: [$ipv6Range],
            poolStatus: new DhcpPoolStatus(total: 100, used: 50, available: 50, utilisation: 0.5),
            fetchStatus: ['ipv4' => true, 'ipv6' => true],
            ipv6PoolStatus: new DhcpPoolStatus(total: 100, used: 50, available: 50, utilisation: 0.5),
        );
        $this->dispatchSyncJob();

        $this->bindDhcpServiceWithFetchStatus(
            leases: [],
            ranges: [$ipv6Range],
            poolStatus: new DhcpPoolStatus(total: 100, used: 85, available: 15, utilisation: 0.85),
            fetchStatus: ['ipv4' => true, 'ipv6' => true],
            ipv6PoolStatus: new DhcpPoolStatus(total: 100, used: 50, available: 50, utilisation: 0.5),
        );
        $this->dispatchSyncJob();

        Event::assertDispatched(DhcpPoolThresholdReached::class, 1);

        Event::fake([DhcpPoolThresholdReached::class]);

        $this->bindDhcpServiceWithFetchStatus(
            leases: [],
            ranges: [$ipv6Range],
            poolStatus: new DhcpPoolStatus(total: 100, used: 85, available: 15, utilisation: 0.85),
            fetchStatus: ['ipv4' => true, 'ipv6' => true],
            ipv6PoolStatus: new DhcpPoolStatus(total: 100, used: 50, available: 50, utilisation: 0.5),
        );
        $this->dispatchSyncJob();

        Event::assertNotDispatched(DhcpPoolThresholdReached::class);
    }

    public function test_threshold_event_fires_for_ipv6_after_ipv4_already_crossed(): void
    {
        Event::fake([DhcpPoolThresholdReached::class]);

        $this->assignDhcpProvider('cisco');

        $ipv6Range = new DhcpRange(
            interface: 'VLAN400_DHCPV6',
            type: AddressFamily::IPv6,
            subnet: null,
            rangeFrom: null,
            rangeTo: null,
            prefix: '2001:db8::/64',
            gateway: null,
            description: 'V6 LAN',
        );

        $this->bindDhcpServiceWithFetchStatus(
            leases: [],
            ranges: [$ipv6Range],
            poolStatus: new DhcpPoolStatus(total: 100, used: 50, available: 50, utilisation: 0.5),
            fetchStatus: ['ipv4' => true, 'ipv6' => true],
            ipv6PoolStatus: new DhcpPoolStatus(total: 100, used: 50, available: 50, utilisation: 0.5),
        );
        $this->dispatchSyncJob();

        $this->bindDhcpServiceWithFetchStatus(
            leases: [],
            ranges: [$ipv6Range],
            poolStatus: new DhcpPoolStatus(total: 100, used: 85, available: 15, utilisation: 0.85),
            fetchStatus: ['ipv4' => true, 'ipv6' => true],
            ipv6PoolStatus: new DhcpPoolStatus(total: 100, used: 50, available: 50, utilisation: 0.5),
        );
        $this->dispatchSyncJob();

        Event::fake([DhcpPoolThresholdReached::class]);

        $this->bindDhcpServiceWithFetchStatus(
            leases: [],
            ranges: [$ipv6Range],
            poolStatus: new DhcpPoolStatus(total: 100, used: 85, available: 15, utilisation: 0.85),
            fetchStatus: ['ipv4' => true, 'ipv6' => true],
            ipv6PoolStatus: new DhcpPoolStatus(total: 100, used: 85, available: 15, utilisation: 0.85),
        );
        $this->dispatchSyncJob();

        Event::assertDispatched(DhcpPoolThresholdReached::class, 1);
        Event::assertDispatched(DhcpPoolThresholdReached::class, function (DhcpPoolThresholdReached $event): bool {
            return $event->addressFamily === 'ipv6' && $event->usage === 0.85;
        });
    }

    public function test_removes_ipv6_pool_status_when_ipv6_fetch_succeeds_with_no_pools(): void
    {
        CapabilityAssignment::assign(Capability::Dhcp, 'kea');
        DhcpPoolStatusRecord::create([
            'integration' => 'kea', 'address_family' => 'ipv6', 'total' => '100', 'used' => '10',
            'available' => '90', 'utilisation' => '0.1000', 'synced_at' => now(),
        ]);
        $this->bindDhcpServiceWithFetchStatus([], [], new DhcpPoolStatus(total: 0, used: 0, available: 0, utilisation: 0.0), ['ipv4' => true, 'ipv6' => true]);

        (new SyncDhcpData)->handle(resolve(DhcpInterface::class), resolve(DhcpSyncService::class));

        $this->assertDatabaseMissing('dhcp_pool_statuses', ['integration' => 'kea', 'address_family' => 'ipv6']);
    }

    public function test_keeps_ipv6_pool_status_when_ipv6_fetch_failed(): void
    {
        CapabilityAssignment::assign(Capability::Dhcp, 'kea');
        DhcpPoolStatusRecord::create([
            'integration' => 'kea', 'address_family' => 'ipv6', 'total' => '100', 'used' => '10',
            'available' => '90', 'utilisation' => '0.1000', 'synced_at' => now(),
        ]);
        $this->bindDhcpServiceWithFetchStatus([], [], new DhcpPoolStatus(total: 0, used: 0, available: 0, utilisation: 0.0), ['ipv4' => true, 'ipv6' => false]);

        (new SyncDhcpData)->handle(resolve(DhcpInterface::class), resolve(DhcpSyncService::class));

        $this->assertDatabaseHas('dhcp_pool_statuses', ['integration' => 'kea', 'address_family' => 'ipv6']);
    }
}
