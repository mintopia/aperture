<?php

declare(strict_types=1);

namespace Tests\Feature\Dhcp;

use App\Jobs\SyncDhcpData;
use App\Models\CapabilityAssignment;
use App\Models\DhcpLease;
use App\Models\DhcpPoolStatusRecord;
use App\Models\DhcpRangeRecord;
use App\Models\DhcpSyncState;
use App\Models\IntegrationConfig;
use App\Models\IpAddress;
use App\Models\MacAddress;
use GuzzleHttp\Promise\PromiseInterface;
use Illuminate\Foundation\Testing\LazilyRefreshDatabase;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Tests\TestCase;

class KeaDhcpIntegrationTest extends TestCase
{
    use LazilyRefreshDatabase;

    private function configureKeaIntegration(?string $endpointV4 = null, ?string $endpointV6 = null): void
    {
        CapabilityAssignment::assign('dhcp', 'kea');

        if ($endpointV4 !== null) {
            IntegrationConfig::setValue('kea', 'endpoint_v4', $endpointV4);
        }

        if ($endpointV6 !== null) {
            IntegrationConfig::setValue('kea', 'endpoint_v6', $endpointV6);
        }
    }

    /**
     * @param  array<int, array<string, mixed>>  $leases
     */
    private function fakeSinglePageLeaseResponse(Request $request, array $leases): PromiseInterface
    {
        $data = $request->data();
        $arguments = is_array($data['arguments'] ?? null) ? $data['arguments'] : [];
        $from = $arguments['from'] ?? null;

        if ($from !== 'start') {
            return Http::response([['result' => 3, 'arguments' => ['leases' => []]]]);
        }

        return Http::response([['result' => 0, 'arguments' => ['leases' => $leases]]]);
    }

    /**
     * @return array<string, mixed>
     */
    private function keaIpv4Lease(string $ip, string $hwAddress, string $hostname, int $cltt): array
    {
        return [
            'ip-address' => $ip,
            'hw-address' => $hwAddress,
            'state' => 0,
            'cltt' => $cltt,
            'valid-lft' => 3600,
            'hostname' => $hostname,
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function keaIpv6Lease(string $ip, string $duid, string $hostname, int $cltt): array
    {
        return [
            'ip-address' => $ip,
            'duid' => $duid,
            'state' => 0,
            'cltt' => $cltt,
            'valid-lft' => 3600,
            'hostname' => $hostname,
            'type' => 'IA_NA',
        ];
    }

    /**
     * DUID-LLT (RFC 8415): 2 bytes type (0001) + 2 bytes hw-type (0001,
     * Ethernet) + 4 bytes timestamp + 6 bytes MAC.
     */
    private function keaDuidLltHex(int $timestamp, string $mac): string
    {
        $timestampHex = str_pad(dechex($timestamp), 8, '0', STR_PAD_LEFT);
        $timestampBytes = implode(':', str_split($timestampHex, 2));

        return "00:01:00:01:{$timestampBytes}:{$mac}";
    }

    /**
     * @return array<string, mixed>
     */
    private function keaConfigGetEntry(string $subnet, string $interface, string $poolRange): array
    {
        return [
            'result' => 0,
            'arguments' => [
                'Dhcp4' => [
                    'subnet4' => [
                        [
                            'subnet' => $subnet,
                            'interface' => $interface,
                            'pools' => [
                                ['pool' => $poolRange],
                            ],
                        ],
                    ],
                ],
            ],
        ];
    }

    private function dispatchSyncJob(): void
    {
        $job = new SyncDhcpData;
        app()->call([$job, 'handle']);
    }

    public function test_dual_stack_sync_stores_ipv4_and_ipv6_leases_with_duid_derived_mac(): void
    {
        Queue::fake();

        $now = Carbon::now()->getTimestamp();

        $duid = $this->keaDuidLltHex(0xAABBCCDD, 'de:ad:be:ef:ca:fe');

        Http::fake([
            'kea4.local' => function (Request $request) use ($now): PromiseInterface {
                $data = $request->data();

                return match ($data['command'] ?? null) {
                    'lease4-get-page' => $this->fakeSinglePageLeaseResponse($request, [
                        $this->keaIpv4Lease('10.10.0.50', '00:aa:bb:cc:dd:ee', 'host-v4', $now),
                    ]),
                    'config-get' => Http::response([
                        $this->keaConfigGetEntry('10.10.0.0/24', 'eth0', '10.10.0.10 - 10.10.0.200'),
                    ]),
                    default => Http::response([['result' => 3]]),
                };
            },
            'kea6.local' => fn (Request $request): PromiseInterface => $this->fakeSinglePageLeaseResponse($request, [
                $this->keaIpv6Lease('2001:db8::50', $duid, 'host-v6', $now),
            ]),
        ]);

        $this->configureKeaIntegration(endpointV4: 'https://kea4.local', endpointV6: 'https://kea6.local');

        $this->dispatchSyncJob();

        $this->assertDatabaseCount('dhcp_leases', 2);

        $v4Ip = IpAddress::where('address', '10.10.0.50')->firstOrFail();
        $v4Mac = MacAddress::where('mac_address', '00:AA:BB:CC:DD:EE')->firstOrFail();
        $this->assertDatabaseHas('dhcp_leases', [
            'integration' => 'kea',
            'ip_address_id' => $v4Ip->id,
            'mac_address_id' => $v4Mac->id,
        ]);

        $v6Ip = IpAddress::where('address', '2001:db8::50')->firstOrFail();
        $v6Mac = MacAddress::where('mac_address', 'DE:AD:BE:EF:CA:FE')->firstOrFail();
        $this->assertDatabaseHas('dhcp_leases', [
            'integration' => 'kea',
            'ip_address_id' => $v6Ip->id,
            'mac_address_id' => $v6Mac->id,
        ]);
    }

    public function test_ipv6_fetch_failure_updates_ipv4_and_keeps_ipv6_last_known_data(): void
    {
        Queue::fake();

        $initial = Carbon::parse('2026-01-01 00:00:00');
        Carbon::setTestNow($initial);

        $existingIp = IpAddress::factory()->create(['address' => '2001:db8::99']);
        $existingMac = MacAddress::factory()->create(['mac_address' => 'AA:AA:AA:AA:AA:AA']);
        $existingLease = DhcpLease::factory()->create([
            'integration' => 'kea',
            'ip_address_id' => $existingIp->id,
            'mac_address_id' => $existingMac->id,
            'hostname' => 'existing-v6-host',
            'expires_at' => $initial->copy()->addDay(),
        ]);

        DhcpSyncState::factory()->create([
            'integration' => 'kea',
            'address_family' => 'ipv6',
            'dataset' => 'leases',
            'last_attempt_at' => $initial,
            'last_success_at' => $initial,
        ]);

        $later = $initial->copy()->addMinutes(10);
        Carbon::setTestNow($later);

        Http::fake([
            'kea4.local' => function (Request $request) use ($later): PromiseInterface {
                $data = $request->data();

                return match ($data['command'] ?? null) {
                    'lease4-get-page' => $this->fakeSinglePageLeaseResponse($request, [
                        $this->keaIpv4Lease('10.20.0.60', '00:11:22:33:44:55', 'host-v4-new', $later->getTimestamp()),
                    ]),
                    'config-get' => Http::response([
                        $this->keaConfigGetEntry('10.20.0.0/24', 'eth0', '10.20.0.10 - 10.20.0.200'),
                    ]),
                    default => Http::response([['result' => 3]]),
                };
            },
            'kea6.local' => Http::response(['error' => 'Unauthorized'], 401),
        ]);

        $this->configureKeaIntegration(endpointV4: 'https://kea4.local', endpointV6: 'https://kea6.local');

        $this->dispatchSyncJob();

        $this->assertDatabaseHas('ip_addresses', ['address' => '10.20.0.60']);

        $this->assertDatabaseHas('dhcp_leases', [
            'id' => $existingLease->id,
            'integration' => 'kea',
            'ip_address_id' => $existingIp->id,
            'mac_address_id' => $existingMac->id,
            'hostname' => 'existing-v6-host',
        ]);
        $this->assertSame(1, DhcpLease::where('integration', 'kea')
            ->where('ip_address_id', $existingIp->id)
            ->count());

        $ipv6State = DhcpSyncState::where('integration', 'kea')
            ->where('address_family', 'ipv6')
            ->where('dataset', 'leases')
            ->firstOrFail();

        $this->assertNotNull($ipv6State->last_attempt_at);
        $this->assertTrue($ipv6State->last_attempt_at->equalTo($later));
        $this->assertNotNull($ipv6State->last_success_at);
        $this->assertTrue($ipv6State->last_success_at->equalTo($initial));
    }

    public function test_ipv4_failure_updates_ipv6_and_keeps_ipv4_ranges_and_pool_status_untouched(): void
    {
        Queue::fake();

        $initial = Carbon::parse('2026-01-01 00:00:00');
        Carbon::setTestNow($initial);

        $existingIp = IpAddress::factory()->create(['address' => '10.30.0.77']);
        $existingMac = MacAddress::factory()->create(['mac_address' => 'BB:BB:BB:BB:BB:BB']);
        $existingLease = DhcpLease::factory()->create([
            'integration' => 'kea',
            'ip_address_id' => $existingIp->id,
            'mac_address_id' => $existingMac->id,
            'hostname' => 'existing-v4-host',
            'expires_at' => $initial->copy()->addDay(),
        ]);

        $existingRange = DhcpRangeRecord::factory()->create([
            'integration' => 'kea',
            'type' => 'ipv4',
            'interface' => 'eth0',
            'subnet' => '10.30.0.0/24',
            'range_from' => '10.30.0.10',
            'range_to' => '10.30.0.200',
            'total_addresses' => '191',
            'used_addresses' => '1',
            'utilisation' => '0.0052',
        ]);

        $existingPoolStatus = DhcpPoolStatusRecord::factory()->create([
            'integration' => 'kea',
            'address_family' => 'ipv4',
            'total' => '254',
            'used' => '50',
            'available' => '204',
            'utilisation' => '0.1969',
            'synced_at' => $initial,
        ]);

        foreach (['leases', 'ranges', 'pool_status'] as $dataset) {
            DhcpSyncState::factory()->create([
                'integration' => 'kea',
                'address_family' => 'ipv4',
                'dataset' => $dataset,
                'last_attempt_at' => $initial,
                'last_success_at' => $initial,
            ]);
        }

        $later = $initial->copy()->addMinutes(10);
        Carbon::setTestNow($later);

        Http::fake([
            'kea4.local' => Http::response(['error' => 'Unauthorized'], 401),
            'kea6.local' => fn (Request $request): PromiseInterface => $this->fakeSinglePageLeaseResponse($request, [
                $this->keaIpv6Lease(
                    '2001:db8::200',
                    '00:01:00:01:11:22:33:44:11:22:33:44:55:66',
                    'host-v6-new',
                    $later->getTimestamp(),
                ),
            ]),
        ]);

        $this->configureKeaIntegration(endpointV4: 'https://kea4.local', endpointV6: 'https://kea6.local');

        $this->dispatchSyncJob();

        $this->assertDatabaseHas('ip_addresses', ['address' => '2001:db8::200']);

        $this->assertDatabaseHas('dhcp_leases', [
            'id' => $existingLease->id,
            'integration' => 'kea',
            'ip_address_id' => $existingIp->id,
            'mac_address_id' => $existingMac->id,
            'hostname' => 'existing-v4-host',
        ]);
        $this->assertSame(1, DhcpLease::where('integration', 'kea')
            ->where('ip_address_id', $existingIp->id)
            ->count());

        $existingRange->refresh();
        $this->assertEquals('191', $existingRange->total_addresses);
        $this->assertEquals(1, $existingRange->used_addresses);
        $this->assertEquals(0.0052, $existingRange->utilisation);

        $existingPoolStatus->refresh();
        $this->assertEquals('254', $existingPoolStatus->total);
        $this->assertEquals('50', $existingPoolStatus->used);
        $this->assertEquals('204', $existingPoolStatus->available);
        $this->assertNotNull($existingPoolStatus->synced_at);
        $this->assertTrue($existingPoolStatus->synced_at->equalTo($initial));

        foreach (['leases', 'ranges', 'pool_status'] as $dataset) {
            $state = DhcpSyncState::where('integration', 'kea')
                ->where('address_family', 'ipv4')
                ->where('dataset', $dataset)
                ->firstOrFail();

            $this->assertNotNull($state->last_attempt_at);
            $this->assertTrue($state->last_attempt_at->equalTo($later));
            $this->assertNotNull($state->last_success_at);
            $this->assertTrue($state->last_success_at->equalTo($initial));
        }
    }
}
