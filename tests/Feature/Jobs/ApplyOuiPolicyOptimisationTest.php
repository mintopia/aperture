<?php

declare(strict_types=1);

namespace Tests\Feature\Jobs;

use App\Jobs\ScanNetworkDevices;
use App\Models\AuditLog;
use App\Models\IpAddress;
use App\Models\MacAddress;
use App\Models\Setting;
use App\Services\Interfaces\DhcpInterface;
use App\Services\Interfaces\IpMacResolverInterface;
use App\Services\Interfaces\PortMacInterface;
use App\Services\ValueObjects\DhcpLease as DhcpLeaseVO;
use Illuminate\Foundation\Testing\LazilyRefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Queue;
use Mockery\MockInterface;
use Tests\TestCase;

class ApplyOuiPolicyOptimisationTest extends TestCase
{
    use LazilyRefreshDatabase;

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

    private function mockInventory(): void
    {
        $this->mock(IpMacResolverInterface::class, function (MockInterface $mock): void {
            $mock->allows([
                'getArpTable' => collect([]),
            ]);
        });

        $this->mock(PortMacInterface::class, function (MockInterface $mock): void {
            $mock->allows([
                'getForwardingDatabase' => collect([]),
            ]);
        });
    }

    public function test_oui_policy_only_queries_matching_prefixes_not_all_records(): void
    {
        Setting::set('network.oui_auto_allow', 'OUI Auto-Allow Prefixes', json_encode(['AA:BB:CC']));

        $this->mockDhcp([
            new DhcpLeaseVO('127.0.0.1', 'AA:BB:CC:DD:EE:01', 'matching', '2026-05-01'),
            new DhcpLeaseVO('127.0.0.2', '11:22:33:44:55:66', 'non-matching', '2026-05-01'),
        ]);
        $this->mockInventory();

        $queries = collect();
        DB::listen(function ($query) use ($queries): void {
            $queries->push($query->sql);
        });

        (new ScanNetworkDevices)->handle();

        // Verify we never load ALL mac_addresses without a WHERE filter
        $unfilteredSelects = $queries->filter(function (string $sql): bool {
            return str_contains($sql, 'mac_addresses')
                && str_contains($sql, 'select')
                && ! str_contains($sql, 'where')
                && ! str_contains($sql, 'insert')
                && ! str_contains($sql, 'update');
        });

        $this->assertCount(0, $unfilteredSelects, 'applyOuiPolicy should not load all MAC addresses without filtering');

        // Verify the matching MAC's IP was enabled
        $ip1 = IpAddress::where('address', '127.0.0.1')->first();
        $this->assertNotNull($ip1);
        $this->assertTrue($ip1->internet_enabled);

        // Verify the non-matching MAC's IP was NOT enabled
        $ip2 = IpAddress::where('address', '127.0.0.2')->first();
        $this->assertNotNull($ip2);
        $this->assertFalse($ip2->internet_enabled);
    }

    public function test_oui_policy_handles_multiple_prefixes(): void
    {
        Setting::set('network.oui_auto_allow', 'OUI Auto-Allow Prefixes', json_encode(['AA:BB:CC', 'DD:EE:FF']));

        $this->mockDhcp([
            new DhcpLeaseVO('127.0.0.1', 'AA:BB:CC:11:22:33', 'device1', '2026-05-01'),
            new DhcpLeaseVO('127.0.0.2', 'DD:EE:FF:44:55:66', 'device2', '2026-05-01'),
            new DhcpLeaseVO('127.0.0.3', '99:88:77:66:55:44', 'device3', '2026-05-01'),
        ]);
        $this->mockInventory();

        (new ScanNetworkDevices)->handle();

        $ip1 = IpAddress::where('address', '127.0.0.1')->first();
        $this->assertNotNull($ip1);
        $this->assertTrue($ip1->internet_enabled);

        $ip2 = IpAddress::where('address', '127.0.0.2')->first();
        $this->assertNotNull($ip2);
        $this->assertTrue($ip2->internet_enabled);

        $ip3 = IpAddress::where('address', '127.0.0.3')->first();
        $this->assertNotNull($ip3);
        $this->assertFalse($ip3->internet_enabled);
    }

    public function test_oui_policy_creates_audit_log_for_enabled_ips(): void
    {
        Setting::set('network.oui_auto_allow', 'OUI Auto-Allow Prefixes', json_encode(['AA:BB:CC']));

        $this->mockDhcp([new DhcpLeaseVO('127.0.0.1', 'AA:BB:CC:DD:EE:01', 'xbox', '2026-05-01')]);
        $this->mockInventory();

        (new ScanNetworkDevices)->handle();

        $this->assertDatabaseHas('audit_logs', [
            'action' => 'oui.auto_allowed',
            'process' => 'oui_policy',
        ]);

        $log = AuditLog::where('action', 'oui.auto_allowed')->first();
        $this->assertNotNull($log);
        $this->assertIsArray($log->metadata);
        $this->assertEquals('AA:BB:CC:DD:EE:01', $log->metadata['mac']);
    }

    public function test_oui_policy_skips_already_enabled_ips(): void
    {
        Setting::set('network.oui_auto_allow', 'OUI Auto-Allow Prefixes', json_encode(['AA:BB:CC']));

        // Pre-create a MAC and IP that's already enabled
        $mac = MacAddress::factory()->create(['mac_address' => 'AA:BB:CC:DD:EE:01']);
        $ip = IpAddress::factory()->create(['address' => '127.0.0.1', 'internet_enabled' => true]);
        $ip->macAddresses()->attach($mac, ['source' => 'dhcp', 'last_seen_at' => now()]);

        $this->mockDhcp([new DhcpLeaseVO('127.0.0.1', 'AA:BB:CC:DD:EE:01', 'xbox', '2026-05-01')]);
        $this->mockInventory();

        (new ScanNetworkDevices)->handle();

        // Should NOT create an audit log since IP was already enabled
        $this->assertDatabaseMissing('audit_logs', [
            'action' => 'oui.auto_allowed',
        ]);
    }

    public function test_oui_policy_handles_case_insensitive_prefix_matching(): void
    {
        Setting::set('network.oui_auto_allow', 'OUI Auto-Allow Prefixes', json_encode(['aa:bb:cc']));

        $this->mockDhcp([new DhcpLeaseVO('127.0.0.1', 'AA:BB:CC:DD:EE:01', 'device', '2026-05-01')]);
        $this->mockInventory();

        (new ScanNetworkDevices)->handle();

        $ip = IpAddress::where('address', '127.0.0.1')->first();
        $this->assertNotNull($ip);
        $this->assertTrue($ip->internet_enabled);
    }

    public function test_oui_policy_returns_early_when_setting_is_null(): void
    {
        // Don't set the oui_auto_allow setting at all
        $this->mockDhcp([new DhcpLeaseVO('127.0.0.1', 'AA:BB:CC:DD:EE:01', 'device', '2026-05-01')]);
        $this->mockInventory();

        (new ScanNetworkDevices)->handle();

        $ip = IpAddress::where('address', '127.0.0.1')->first();
        $this->assertNotNull($ip);
        $this->assertFalse($ip->internet_enabled);
    }

    public function test_oui_policy_returns_early_when_prefixes_are_empty_array(): void
    {
        Setting::set('network.oui_auto_allow', 'OUI Auto-Allow Prefixes', json_encode([]));

        $this->mockDhcp([new DhcpLeaseVO('127.0.0.1', 'AA:BB:CC:DD:EE:01', 'device', '2026-05-01')]);
        $this->mockInventory();

        (new ScanNetworkDevices)->handle();

        $ip = IpAddress::where('address', '127.0.0.1')->first();
        $this->assertNotNull($ip);
        $this->assertFalse($ip->internet_enabled);
    }

    public function test_oui_policy_enables_multiple_ips_for_same_mac(): void
    {
        Setting::set('network.oui_auto_allow', 'OUI Auto-Allow Prefixes', json_encode(['AA:BB:CC']));

        $mac = MacAddress::factory()->create(['mac_address' => 'AA:BB:CC:DD:EE:01']);
        $ip1 = IpAddress::factory()->create(['address' => '127.0.0.1', 'internet_enabled' => false]);
        $ip2 = IpAddress::factory()->create(['address' => '127.0.0.2', 'internet_enabled' => false]);
        $ip1->macAddresses()->attach($mac, ['source' => 'dhcp', 'last_seen_at' => now()]);
        $ip2->macAddresses()->attach($mac, ['source' => 'arp', 'last_seen_at' => now()]);

        $this->mockDhcp();
        $this->mockInventory();

        (new ScanNetworkDevices)->handle();

        $ip1->refresh();
        $ip2->refresh();
        $this->assertTrue($ip1->internet_enabled);
        $this->assertTrue($ip2->internet_enabled);

        $this->assertDatabaseCount('audit_logs', 2);
    }

    public function test_oui_policy_processes_macs_in_chunks(): void
    {
        Setting::set('network.oui_auto_allow', 'OUI Auto-Allow Prefixes', json_encode(['AA:BB:CC']));

        // Create enough MACs to require chunking (more than typical chunk size)
        $macs = [];
        $ips = [];
        for ($i = 0; $i < 5; $i++) {
            $suffix = str_pad(dechex($i), 2, '0', STR_PAD_LEFT);
            $macAddress = "AA:BB:CC:DD:EE:{$suffix}";
            $mac = MacAddress::factory()->create(['mac_address' => $macAddress]);
            $ip = IpAddress::factory()->create([
                'address' => "127.0.0.{$i}",
                'internet_enabled' => false,
            ]);
            $ip->macAddresses()->attach($mac, ['source' => 'dhcp', 'last_seen_at' => now()]);
            $macs[] = $mac;
            $ips[] = $ip;
        }

        $this->mockDhcp();
        $this->mockInventory();

        (new ScanNetworkDevices)->handle();

        // Verify all matching IPs were enabled
        foreach ($ips as $ip) {
            $ip->refresh();
            $this->assertTrue($ip->internet_enabled, "IP {$ip->address} should be internet_enabled");
        }
    }
}
