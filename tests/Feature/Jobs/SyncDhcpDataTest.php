<?php

declare(strict_types=1);

namespace Tests\Feature\Jobs;

use App\Events\DhcpPoolThresholdReached;
use App\Jobs\SyncDhcpData;
use App\Models\CapabilityAssignment;
use App\Models\DhcpPoolStatusRecord;
use App\Models\DhcpSyncState;
use App\Models\IpAddress;
use App\Models\MacAddress;
use App\Services\Interfaces\DhcpInterface;
use App\Services\Null\NullDhcpService;
use App\Services\ValueObjects\DhcpLease as DhcpLeaseVO;
use App\Services\ValueObjects\DhcpPoolStatus;
use App\Services\ValueObjects\DhcpRange;
use Illuminate\Contracts\Queue\ShouldBeUnique;
use Illuminate\Foundation\Testing\LazilyRefreshDatabase;
use Illuminate\Support\Facades\Event;
use Mockery\MockInterface;
use Tests\TestCase;

class SyncDhcpDataTest extends TestCase
{
    use LazilyRefreshDatabase;

    private function assignDhcpProvider(string $integration = 'cisco'): void
    {
        CapabilityAssignment::assign('dhcp', $integration);
    }

    /**
     * @param  list<DhcpLeaseVO>  $leases
     * @param  list<DhcpRange>  $ranges
     */
    private function mockDhcpService(
        array $leases = [],
        array $ranges = [],
        ?DhcpPoolStatus $poolStatus = null,
    ): void {
        $poolStatus ??= new DhcpPoolStatus(total: 0, used: 0, available: 0, utilisation: 0.0);

        $this->mock(DhcpInterface::class, function (MockInterface $mock) use ($leases, $ranges, $poolStatus): void {
            $mock->allows('getLeases')->andReturn(collect($leases));
            $mock->allows('getRanges')->andReturn(collect($ranges));
            $mock->allows('getPoolStatus')->andReturn($poolStatus);
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
                    type: 'ipv4',
                    subnet: '10.0.0.0/24',
                    rangeFrom: '10.0.0.10',
                    rangeTo: '10.0.0.200',
                    prefix: null,
                    gateway: '10.0.0.1',
                    description: 'Main LAN',
                    totalAddresses: 191,
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

        // First sync with two leases
        $this->mockDhcpService(
            leases: [
                new DhcpLeaseVO('10.0.0.1', 'AA:BB:CC:DD:EE:01', 'host1', '2026-06-09 00:00:00'),
                new DhcpLeaseVO('10.0.0.2', 'AA:BB:CC:DD:EE:02', 'host2', '2026-06-09 12:00:00'),
            ],
        );

        $this->dispatchSyncJob();
        $this->assertDatabaseCount('dhcp_leases', 2);

        // Second sync with only one lease — stale one should be deleted
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
            type: 'ipv4',
            subnet: '10.0.0.0/24',
            rangeFrom: '10.0.0.10',
            rangeTo: '10.0.0.200',
            prefix: null,
            gateway: '10.0.0.1',
            description: 'Main LAN',
        );
        $range2 = new DhcpRange(
            interface: 'Vlan200',
            type: 'ipv4',
            subnet: '10.0.1.0/24',
            rangeFrom: '10.0.1.10',
            rangeTo: '10.0.1.200',
            prefix: null,
            gateway: '10.0.1.1',
            description: 'Secondary LAN',
        );

        // First sync with two ranges
        $this->mockDhcpService(ranges: [$range1, $range2]);
        $this->dispatchSyncJob();
        $this->assertDatabaseCount('dhcp_range_records', 2);

        // Second sync with only one range
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
                type: 'ipv6',
                subnet: null,
                rangeFrom: null,
                rangeTo: null,
                prefix: '2A0F:85C1:D91:2100::/64',
                gateway: null,
                description: null,
            ),
            new DhcpRange(
                interface: 'VLAN400_DHCPV6',
                type: 'ipv6',
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

        // Re-running the sync must be idempotent — no duplicate rows
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

        // First sync with data
        $this->mockDhcpService(
            leases: [
                new DhcpLeaseVO('10.0.0.1', 'AA:BB:CC:DD:EE:01', 'host1', '2026-06-09 00:00:00'),
            ],
        );
        $this->dispatchSyncJob();
        $this->assertDatabaseCount('dhcp_leases', 1);

        // Second sync with empty data — should NOT delete
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

        // First sync with data
        $this->mockDhcpService(
            leases: [
                new DhcpLeaseVO('10.0.0.1', 'AA:BB:CC:DD:EE:01', 'host1', '2026-06-09 00:00:00'),
            ],
        );
        $this->dispatchSyncJob();
        $this->assertDatabaseCount('dhcp_leases', 1);

        // Run 3 empty syncs
        for ($i = 0; $i < 3; $i++) {
            $this->mockDhcpService(leases: []);
            $this->dispatchSyncJob();
        }

        // After 3 consecutive empties, leases should be deleted
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

        // First sync with data
        $this->mockDhcpService(
            ranges: [
                new DhcpRange(
                    interface: 'Vlan100',
                    type: 'ipv4',
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

        // Run 3 empty syncs
        for ($i = 0; $i < 3; $i++) {
            $this->mockDhcpService(ranges: []);
            $this->dispatchSyncJob();
        }

        // After 3 consecutive empties, ranges should be deleted
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

        // First sync below threshold
        $this->mockDhcpService(
            poolStatus: new DhcpPoolStatus(total: 100, used: 50, available: 50, utilisation: 0.5),
        );
        $this->dispatchSyncJob();

        Event::assertNotDispatched(DhcpPoolThresholdReached::class);

        // Second sync above threshold (crosses 0.8)
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

        // First sync already above threshold
        $this->mockDhcpService(
            poolStatus: new DhcpPoolStatus(total: 100, used: 90, available: 10, utilisation: 0.9),
        );
        $this->dispatchSyncJob();

        // Reset event tracking
        Event::fake([DhcpPoolThresholdReached::class]);

        // Second sync still above threshold (not a crossing)
        $this->mockDhcpService(
            poolStatus: new DhcpPoolStatus(total: 100, used: 95, available: 5, utilisation: 0.95),
        );
        $this->dispatchSyncJob();

        Event::assertNotDispatched(DhcpPoolThresholdReached::class);
    }
}
