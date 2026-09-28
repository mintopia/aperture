<?php

declare(strict_types=1);

namespace Tests\Feature\Jobs;

use App\Jobs\NetworkScan\PersistDhcpLeasesStep;
use App\Jobs\SyncDhcpData;
use App\Models\IpAddress;
use App\Models\MacAddress;
use App\Services\NetworkRangeService;
use App\Services\ValueObjects\DhcpLease as DhcpLeaseVO;
use Illuminate\Foundation\Testing\LazilyRefreshDatabase;
use Tests\TestCase;

/**
 * A dhcp_leases row's identity is (ip_address_id, mac_address_id); integration
 * only records which integration reported it. Both writers must agree on that
 * key so the same ip+mac never produces two rows regardless of write order.
 */
class DhcpLeaseIdentityKeyTest extends TestCase
{
    use LazilyRefreshDatabase;

    private const IP = '10.0.0.5';

    private const MAC = 'AA:BB:CC:DD:EE:01';

    public function test_sync_dhcp_data_then_persist_dhcp_leases_step_produce_single_row(): void
    {
        $lease = new DhcpLeaseVO(self::IP, self::MAC, 'host-a', '2026-06-09 00:00:00');

        (new SyncDhcpData)->performLeaseSync('cisco', collect([$lease]), ['ipv4' => true, 'ipv6' => true]);
        (new PersistDhcpLeasesStep)(collect([$lease]), app(NetworkRangeService::class), 'cisco');

        $this->assertDatabaseCount('dhcp_leases', 1);

        $ip = IpAddress::where('address', self::IP)->firstOrFail();
        $mac = MacAddress::where('mac_address', self::MAC)->firstOrFail();

        $this->assertDatabaseHas('dhcp_leases', [
            'ip_address_id' => $ip->id,
            'mac_address_id' => $mac->id,
            'integration' => 'cisco',
            'hostname' => 'host-a',
        ]);
    }

    public function test_persist_dhcp_leases_step_then_sync_dhcp_data_produce_single_row(): void
    {
        $lease = new DhcpLeaseVO(self::IP, self::MAC, 'host-b', '2026-06-09 00:00:00');

        // PersistDhcpLeasesStep requires the ip/mac rows to already exist.
        IpAddress::factory()->create(['address' => self::IP]);
        MacAddress::factory()->create(['mac_address' => self::MAC]);

        (new PersistDhcpLeasesStep)(collect([$lease]), app(NetworkRangeService::class), 'cisco');
        (new SyncDhcpData)->performLeaseSync('cisco', collect([$lease]), ['ipv4' => true, 'ipv6' => true]);

        $this->assertDatabaseCount('dhcp_leases', 1);

        $ip = IpAddress::where('address', self::IP)->firstOrFail();
        $mac = MacAddress::where('mac_address', self::MAC)->firstOrFail();

        $this->assertDatabaseHas('dhcp_leases', [
            'ip_address_id' => $ip->id,
            'mac_address_id' => $mac->id,
            'integration' => 'cisco',
            'hostname' => 'host-b',
        ]);
    }

    public function test_persist_dhcp_leases_step_sets_integration(): void
    {
        IpAddress::factory()->create(['address' => self::IP]);
        MacAddress::factory()->create(['mac_address' => self::MAC]);

        $lease = new DhcpLeaseVO(self::IP, self::MAC, 'host-c', '2026-06-09 00:00:00');

        (new PersistDhcpLeasesStep)(collect([$lease]), app(NetworkRangeService::class), 'kea');

        $this->assertDatabaseHas('dhcp_leases', ['integration' => 'kea']);
    }

    public function test_persist_dhcp_leases_step_leaves_integration_null_when_none_given(): void
    {
        IpAddress::factory()->create(['address' => self::IP]);
        MacAddress::factory()->create(['mac_address' => self::MAC]);

        $lease = new DhcpLeaseVO(self::IP, self::MAC, 'host-d', '2026-06-09 00:00:00');

        (new PersistDhcpLeasesStep)(collect([$lease]), app(NetworkRangeService::class));

        $this->assertDatabaseHas('dhcp_leases', ['integration' => null]);
    }
}
