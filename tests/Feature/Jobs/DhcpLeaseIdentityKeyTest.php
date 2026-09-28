<?php

declare(strict_types=1);

namespace Tests\Feature\Jobs;

use App\Jobs\ScanNetworkDevices;
use App\Jobs\SyncDhcpData;
use App\Models\CapabilityAssignment;
use App\Models\IpAddress;
use App\Models\MacAddress;
use App\Services\Interfaces\DhcpInterface;
use App\Services\Interfaces\IpMacResolverInterface;
use App\Services\Interfaces\PortMacInterface;
use App\Services\ValueObjects\DhcpLease as DhcpLeaseVO;
use Illuminate\Foundation\Testing\LazilyRefreshDatabase;
use Mockery\MockInterface;
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

    private function scan(DhcpLeaseVO $lease): void
    {
        $this->mock(DhcpInterface::class, fn (MockInterface $m) => $m->allows(['getLeases' => collect([$lease])]));
        $this->mock(IpMacResolverInterface::class, fn (MockInterface $m) => $m->allows(['getArpTable' => collect()]));
        $this->mock(PortMacInterface::class, fn (MockInterface $m) => $m->allows(['getForwardingDatabase' => collect()]));

        (new ScanNetworkDevices)->handle();
    }

    public function test_sync_dhcp_data_then_scan_persist_dhcp_leases_produce_single_row(): void
    {
        $lease = new DhcpLeaseVO(self::IP, self::MAC, 'host-a', '2026-06-09 00:00:00');

        (new SyncDhcpData)->performLeaseSync('cisco', collect([$lease]), ['ipv4' => true, 'ipv6' => true]);
        CapabilityAssignment::assign('dhcp', 'cisco');
        $this->scan($lease);

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

    public function test_scan_persist_dhcp_leases_then_sync_dhcp_data_produce_single_row(): void
    {
        $lease = new DhcpLeaseVO(self::IP, self::MAC, 'host-b', '2026-06-09 00:00:00');

        // The scan reuses the existing ip/mac rows.
        IpAddress::factory()->create(['address' => self::IP]);
        MacAddress::factory()->create(['mac_address' => self::MAC]);

        CapabilityAssignment::assign('dhcp', 'cisco');
        $this->scan($lease);
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

    public function test_scan_persist_dhcp_leases_sets_integration(): void
    {
        IpAddress::factory()->create(['address' => self::IP]);
        MacAddress::factory()->create(['mac_address' => self::MAC]);

        $lease = new DhcpLeaseVO(self::IP, self::MAC, 'host-c', '2026-06-09 00:00:00');

        CapabilityAssignment::assign('dhcp', 'kea');
        $this->scan($lease);

        $this->assertDatabaseHas('dhcp_leases', ['integration' => 'kea']);
    }

    public function test_scan_persist_dhcp_leases_leaves_integration_null_when_none_given(): void
    {
        IpAddress::factory()->create(['address' => self::IP]);
        MacAddress::factory()->create(['mac_address' => self::MAC]);

        $lease = new DhcpLeaseVO(self::IP, self::MAC, 'host-d', '2026-06-09 00:00:00');

        $this->scan($lease);

        $this->assertDatabaseHas('dhcp_leases', ['integration' => null]);
    }
}
