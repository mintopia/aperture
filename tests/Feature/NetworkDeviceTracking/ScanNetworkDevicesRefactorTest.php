<?php

declare(strict_types=1);

namespace Tests\Feature\NetworkDeviceTracking;

use App\Jobs\ScanNetworkDevices;
use App\Models\AuditLog;
use App\Models\IpAddress;
use App\Models\MacAddress;
use App\Models\Setting;
use App\Models\SwitchPort;
use App\Models\SwitchPortMac;
use App\Services\Interfaces\DhcpInterface;
use App\Services\Interfaces\IpMacResolverInterface;
use App\Services\Interfaces\PortMacInterface;
use App\Services\ValueObjects\ArpEntry;
use App\Services\ValueObjects\DhcpLease as DhcpLeaseVO;
use App\Services\ValueObjects\ForwardingEntry;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Queue;
use Mockery\MockInterface;
use Tests\TestCase;

class ScanNetworkDevicesRefactorTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        Queue::fake();
    }

    /**
     * @param  list<DhcpLeaseVO>  $leases
     */
    private function mockDhcp(array $leases = []): void
    {
        $this->mock(DhcpInterface::class, function (MockInterface $mock) use ($leases): void {
            $mock->allows([
                'getLeases' => collect($leases),
            ]);
        });
    }

    /**
     * @param  list<ArpEntry>  $arp
     * @param  list<ForwardingEntry>  $fdb
     */
    private function mockInventory(array $arp = [], array $fdb = []): void
    {
        $this->mock(IpMacResolverInterface::class, function (MockInterface $mock) use ($arp): void {
            $mock->allows([
                'getArpTable' => collect($arp),
            ]);
        });

        $this->mock(PortMacInterface::class, function (MockInterface $mock) use ($fdb): void {
            $mock->allows([
                'getForwardingDatabase' => collect($fdb),
            ]);
        });
    }

    public function test_discovery_persists_all_dhcp_macs(): void
    {
        $this->mockDhcp([new DhcpLeaseVO('127.0.0.1', 'AA:BB:CC:DD:EE:01', 'host1', '2026-05-01')]);
        $this->mockInventory();

        (new ScanNetworkDevices)->handle();

        $this->assertDatabaseHas('mac_addresses', ['mac_address' => 'AA:BB:CC:DD:EE:01']);
    }

    public function test_discovery_persists_all_arp_macs(): void
    {
        $this->mockDhcp();
        $this->mockInventory([new ArpEntry('127.0.0.1', 'AA:BB:CC:DD:EE:02')]);

        (new ScanNetworkDevices)->handle();

        $this->assertDatabaseHas('mac_addresses', ['mac_address' => 'AA:BB:CC:DD:EE:02']);
    }

    public function test_discovery_persists_in_range_ips(): void
    {
        $this->mockDhcp([new DhcpLeaseVO('127.0.0.1', 'AA:BB:CC:DD:EE:01', 'host1', '2026-05-01')]);
        $this->mockInventory();

        (new ScanNetworkDevices)->handle();

        $this->assertDatabaseHas('ip_addresses', ['address' => '127.0.0.1']);
    }

    public function test_discovery_skips_out_of_range_ips(): void
    {
        Setting::set('network.managed_ranges_v4', 'Managed IPv4 Ranges', json_encode(['10.0.0.0/8']));

        $this->mockDhcp([new DhcpLeaseVO('192.168.1.1', 'AA:BB:CC:DD:EE:01', 'host1', '2026-05-01')]);
        $this->mockInventory();

        (new ScanNetworkDevices)->handle();

        $this->assertDatabaseMissing('ip_addresses', ['address' => '192.168.1.1']);
        // MAC should still be stored regardless
        $this->assertDatabaseHas('mac_addresses', ['mac_address' => 'AA:BB:CC:DD:EE:01']);
    }

    public function test_discovery_creates_ip_mac_pivot_with_correct_source(): void
    {
        $this->mockDhcp([new DhcpLeaseVO('127.0.0.1', 'AA:BB:CC:DD:EE:01', 'host1', '2026-05-01')]);
        $this->mockInventory([new ArpEntry('127.0.0.2', 'AA:BB:CC:DD:EE:02')]);

        (new ScanNetworkDevices)->handle();

        $ip1 = IpAddress::where('address', '127.0.0.1')->first();
        $mac1 = MacAddress::where('mac_address', 'AA:BB:CC:DD:EE:01')->first();
        $this->assertNotNull($ip1);
        $this->assertNotNull($mac1);
        $this->assertDatabaseHas('ip_address_mac_address', [
            'ip_address_id' => $ip1->id,
            'mac_address_id' => $mac1->id,
            'source' => 'dhcp',
        ]);

        $ip2 = IpAddress::where('address', '127.0.0.2')->first();
        $mac2 = MacAddress::where('mac_address', 'AA:BB:CC:DD:EE:02')->first();
        $this->assertNotNull($ip2);
        $this->assertNotNull($mac2);
        $this->assertDatabaseHas('ip_address_mac_address', [
            'ip_address_id' => $ip2->id,
            'mac_address_id' => $mac2->id,
            'source' => 'arp',
        ]);
    }

    public function test_discovery_persists_dhcp_leases_with_hostname(): void
    {
        $this->mockDhcp([new DhcpLeaseVO('127.0.0.1', 'AA:BB:CC:DD:EE:01', 'my-laptop', '2026-05-01 12:00:00')]);
        $this->mockInventory();

        (new ScanNetworkDevices)->handle();

        $ip = IpAddress::where('address', '127.0.0.1')->first();
        $mac = MacAddress::where('mac_address', 'AA:BB:CC:DD:EE:01')->first();

        $this->assertNotNull($ip);
        $this->assertNotNull($mac);
        $this->assertDatabaseHas('dhcp_leases', [
            'ip_address_id' => $ip->id,
            'mac_address_id' => $mac->id,
            'hostname' => 'my-laptop',
        ]);
    }

    public function test_discovery_links_switch_port_mac_fk(): void
    {
        $mac = MacAddress::factory()->create(['mac_address' => 'AA:BB:CC:DD:EE:03']);
        $switchPort = SwitchPort::factory()->create();
        SwitchPortMac::factory()->create([
            'switch_port_id' => $switchPort->id,
            'mac_address' => 'AA:BB:CC:DD:EE:03',
        ]);

        $this->mockDhcp();
        $this->mockInventory([], [new ForwardingEntry('aabb.ccdd.ee03', $switchPort->port_name, 100)]);

        (new ScanNetworkDevices)->handle();

        $spm = SwitchPortMac::where('mac_address', 'AA:BB:CC:DD:EE:03')->first();
        $this->assertNotNull($spm);
        $this->assertEquals($mac->id, $spm->mac_address_id);
    }

    public function test_discovery_touches_last_seen_at_on_existing_records(): void
    {
        $ip = IpAddress::factory()->create(['address' => '127.0.0.1', 'last_seen_at' => now()->subDays(7)]);

        $this->mockDhcp([new DhcpLeaseVO('127.0.0.1', 'AA:BB:CC:DD:EE:01', 'host', '2026-05-01')]);
        $this->mockInventory();

        (new ScanNetworkDevices)->handle();

        $freshIp = $ip->fresh();
        $this->assertNotNull($freshIp);
        $this->assertTrue($freshIp->last_seen_at->isToday());
    }

    public function test_oui_policy_enables_internet_for_matching_mac(): void
    {
        Setting::set('network.oui_auto_allow', 'OUI Auto-Allow Prefixes', json_encode(['AA:BB:CC']));

        $this->mockDhcp([new DhcpLeaseVO('127.0.0.1', 'AA:BB:CC:DD:EE:01', 'xbox', '2026-05-01')]);
        $this->mockInventory();

        (new ScanNetworkDevices)->handle();

        $ip = IpAddress::where('address', '127.0.0.1')->first();
        $this->assertNotNull($ip);
        $this->assertTrue($ip->internet_enabled);
    }

    public function test_oui_policy_does_not_enable_for_non_matching_mac(): void
    {
        Setting::set('network.oui_auto_allow', 'OUI Auto-Allow Prefixes', json_encode(['AA:BB:CC']));

        $this->mockDhcp([new DhcpLeaseVO('127.0.0.1', '11:22:33:44:55:66', 'laptop', '2026-05-01')]);
        $this->mockInventory();

        (new ScanNetworkDevices)->handle();

        $ip = IpAddress::where('address', '127.0.0.1')->first();
        $this->assertNotNull($ip);
        $this->assertFalse($ip->internet_enabled);
    }

    public function test_oui_policy_skipped_when_no_prefixes_configured(): void
    {
        $this->mockDhcp([new DhcpLeaseVO('127.0.0.1', 'AA:BB:CC:DD:EE:01', 'host', '2026-05-01')]);
        $this->mockInventory();

        (new ScanNetworkDevices)->handle();

        $ip = IpAddress::where('address', '127.0.0.1')->first();
        $this->assertNotNull($ip);
        $this->assertFalse($ip->internet_enabled);
    }

    public function test_discovery_creates_audit_logs(): void
    {
        $this->mockDhcp([new DhcpLeaseVO('127.0.0.1', 'AA:BB:CC:DD:EE:01', 'host', '2026-05-01')]);
        $this->mockInventory();

        (new ScanNetworkDevices)->handle();

        $this->assertDatabaseHas('audit_logs', ['action' => 'ip.created', 'process' => 'scan_network']);
        $this->assertDatabaseHas('audit_logs', ['action' => 'mac.created', 'process' => 'scan_network']);
        $this->assertDatabaseHas('audit_logs', ['action' => 'ip_mac.linked', 'process' => 'scan_network']);
    }

    public function test_discovery_runs_without_enabled_toggle(): void
    {
        $this->mockDhcp([new DhcpLeaseVO('127.0.0.1', 'AA:BB:CC:DD:EE:01', 'host', '2026-05-01')]);
        $this->mockInventory();

        (new ScanNetworkDevices)->handle();

        $this->assertDatabaseHas('mac_addresses', ['mac_address' => 'AA:BB:CC:DD:EE:01']);
    }

    public function test_link_switch_port_macs_creates_audit_log(): void
    {
        $mac = MacAddress::factory()->create(['mac_address' => 'AA:BB:CC:DD:EE:04']);
        $switchPort = SwitchPort::factory()->create();
        SwitchPortMac::factory()->create([
            'switch_port_id' => $switchPort->id,
            'mac_address' => 'AA:BB:CC:DD:EE:04',
            'mac_address_id' => null,
        ]);

        $this->mockDhcp();
        $this->mockInventory([], [new ForwardingEntry('aabb.ccdd.ee04', $switchPort->port_name, 100)]);

        (new ScanNetworkDevices)->handle();

        $spm = SwitchPortMac::where('mac_address', 'AA:BB:CC:DD:EE:04')->first();
        $this->assertNotNull($spm);

        $this->assertDatabaseHas('audit_logs', [
            'action' => 'port_mac.linked',
            'subject_type' => $spm->getMorphClass(),
            'subject_id' => $spm->id,
            'process' => 'scan_network',
        ]);
        $log = AuditLog::where('action', 'port_mac.linked')->first();
        $this->assertNotNull($log);
        $this->assertEquals('AA:BB:CC:DD:EE:04', $log->metadata['mac']);
    }
}
