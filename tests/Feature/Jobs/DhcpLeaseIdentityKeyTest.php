<?php

declare(strict_types=1);

namespace Tests\Feature\Jobs;

use App\Jobs\SyncDhcpData;
use App\Models\DhcpLease;
use App\Models\IpAddress;
use App\Models\MacAddress;
use App\Services\ValueObjects\DhcpLease as DhcpLeaseVO;
use Illuminate\Foundation\Testing\LazilyRefreshDatabase;
use Tests\TestCase;

class DhcpLeaseIdentityKeyTest extends TestCase
{
    use LazilyRefreshDatabase;

    private const IP = '10.0.0.5';

    private const MAC = 'AA:BB:CC:DD:EE:01';

    private function sync(array $leases, string $integration = 'cisco'): void
    {
        (new SyncDhcpData)->performLeaseSync($integration, collect($leases), ['ipv4' => true, 'ipv6' => true]);
    }

    public function test_repeated_sync_produces_single_row(): void
    {
        $lease = new DhcpLeaseVO(self::IP, self::MAC, 'host-a', '2026-06-09 00:00:00');

        $this->sync([$lease]);
        $this->sync([$lease]);

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

    public function test_sync_records_reporting_integration(): void
    {
        $this->sync([new DhcpLeaseVO(self::IP, self::MAC, 'host-c', '2026-06-09 00:00:00')], 'kea');

        $this->assertDatabaseHas('dhcp_leases', ['integration' => 'kea']);
    }

    public function test_blank_mac_lease_creates_no_mac_row_and_stores_null_mac_id(): void
    {
        $this->sync([new DhcpLeaseVO(self::IP, '', 'host-x', '2026-06-09 00:00:00')]);

        $this->assertDatabaseCount('mac_addresses', 0);
        $this->assertDatabaseCount('dhcp_leases', 1);
        $this->assertNull(DhcpLease::firstOrFail()->mac_address_id);
    }

    public function test_blank_mac_leases_on_different_ips_are_distinct(): void
    {
        $this->sync([
            new DhcpLeaseVO('10.0.0.5', '', 'host-a', '2026-06-09 00:00:00'),
            new DhcpLeaseVO('10.0.0.6', '  ', 'host-b', '2026-06-09 00:00:00'),
        ]);

        $this->assertDatabaseCount('mac_addresses', 0);
        $this->assertDatabaseCount('dhcp_leases', 2);
        $this->assertSame(2, DhcpLease::whereNull('mac_address_id')->distinct()->count('ip_address_id'));
    }

    public function test_blank_hostname_is_stored_as_null(): void
    {
        $this->sync([
            new DhcpLeaseVO('10.0.0.5', self::MAC, '', '2026-06-09 00:00:00'),
            new DhcpLeaseVO('10.0.0.6', 'AA:BB:CC:DD:EE:02', '   ', '2026-06-09 00:00:00'),
        ]);

        $this->assertDatabaseCount('dhcp_leases', 2);
        $this->assertSame(2, DhcpLease::whereNull('hostname')->count());
        $this->assertDatabaseMissing('dhcp_leases', ['hostname' => '']);
    }
}
