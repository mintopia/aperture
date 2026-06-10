<?php

declare(strict_types=1);

namespace Tests\Feature\NetworkDeviceTracking;

use App\Events\IpMacLinked;
use App\Jobs\NetworkScan\LinkIpMacStep;
use App\Jobs\NetworkScan\PersistDhcpLeasesStep;
use App\Jobs\NetworkScan\PersistIpsStep;
use App\Models\IpAddress;
use App\Models\MacAddress;
use App\Services\NetworkRangeService;
use App\Services\ValueObjects\ArpEntry;
use App\Services\ValueObjects\DhcpLease as DhcpLeaseVO;
use Illuminate\Foundation\Testing\LazilyRefreshDatabase;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Queue;
use Tests\TestCase;

class ScanStepsCaseInsensitiveIpv6Test extends TestCase
{
    use LazilyRefreshDatabase;

    private const LOWER = '2a0f:85c1:d91:2100:7485:ac59:8bc9:72e6';

    private const UPPER = '2A0F:85C1:D91:2100:7485:AC59:8BC9:72E6';

    protected function setUp(): void
    {
        parent::setUp();

        Queue::fake();
    }

    public function test_persist_ips_step_matches_existing_lowercase_row_for_uppercase_dhcp_lease(): void
    {
        $ip = IpAddress::factory()->create([
            'address' => self::LOWER,
            'last_seen_at' => now()->subDays(7),
        ]);

        $step = new PersistIpsStep;
        $step(
            collect([new DhcpLeaseVO(self::UPPER, 'AA:BB:CC:DD:EE:01', 'host', '2026-06-11')]),
            collect(),
            app(NetworkRangeService::class),
        );

        $this->assertSame(1, IpAddress::count());

        $freshIp = $ip->fresh();
        $this->assertNotNull($freshIp);
        $this->assertTrue($freshIp->last_seen_at->isToday());

        $this->assertDatabaseMissing('audit_logs', ['action' => 'ip.created']);
    }

    public function test_persist_ips_step_matches_existing_lowercase_row_for_uppercase_arp_entry(): void
    {
        $ip = IpAddress::factory()->create([
            'address' => self::LOWER,
            'last_seen_at' => now()->subDays(7),
        ]);

        $step = new PersistIpsStep;
        $step(
            collect(),
            collect([new ArpEntry(self::UPPER, 'AA:BB:CC:DD:EE:01')]),
            app(NetworkRangeService::class),
        );

        $this->assertSame(1, IpAddress::count());

        $freshIp = $ip->fresh();
        $this->assertNotNull($freshIp);
        $this->assertTrue($freshIp->last_seen_at->isToday());

        $this->assertDatabaseMissing('audit_logs', ['action' => 'ip.created']);
    }

    public function test_link_ip_mac_step_links_uppercase_lease_to_lowercase_row_and_dispatches_event(): void
    {
        Event::fake([IpMacLinked::class]);

        $ip = IpAddress::factory()->create(['address' => self::LOWER]);
        $mac = MacAddress::factory()->create(['mac_address' => 'AA:BB:CC:DD:EE:01']);

        $step = new LinkIpMacStep;
        $step(
            collect([new DhcpLeaseVO(self::UPPER, 'AA:BB:CC:DD:EE:01', 'host', '2026-06-11')]),
            collect(),
            app(NetworkRangeService::class),
        );

        $this->assertDatabaseHas('ip_address_mac_address', [
            'ip_address_id' => $ip->id,
            'mac_address_id' => $mac->id,
            'source' => 'dhcp',
        ]);

        $this->assertDatabaseHas('audit_logs', [
            'action' => 'ip_mac.linked',
            'subject_type' => (new IpAddress)->getMorphClass(),
            'subject_id' => $ip->id,
            'process' => 'scan_network',
        ]);

        Event::assertDispatched(IpMacLinked::class, fn (IpMacLinked $event): bool => $event->ip->is($ip)
            && $event->mac->is($mac)
            && $event->source === 'dhcp'
            && $event->process === 'scan_network');
    }

    public function test_link_ip_mac_step_dispatches_event_on_update_existing_pivot_branch(): void
    {
        Event::fake([IpMacLinked::class]);

        $ip = IpAddress::factory()->create(['address' => self::LOWER]);
        $mac = MacAddress::factory()->create(['mac_address' => 'AA:BB:CC:DD:EE:01']);
        $ip->macAddresses()->attach($mac, ['source' => 'dhcp', 'last_seen_at' => now()->subHour()]);

        $step = new LinkIpMacStep;
        $step(
            collect([new DhcpLeaseVO(self::UPPER, 'AA:BB:CC:DD:EE:01', 'host', '2026-06-11')]),
            collect(),
            app(NetworkRangeService::class),
        );

        // Still exactly one pivot row, but the event must fire on refresh too so
        // existing production links can heal user associations.
        $this->assertSame(1, $ip->macAddresses()->count());

        Event::assertDispatched(IpMacLinked::class, fn (IpMacLinked $event): bool => $event->ip->is($ip)
            && $event->mac->is($mac)
            && $event->source === 'dhcp'
            && $event->process === 'scan_network');
    }

    public function test_link_ip_mac_step_skips_lease_with_null_mac(): void
    {
        Event::fake([IpMacLinked::class]);

        IpAddress::factory()->create(['address' => self::LOWER]);

        $step = new LinkIpMacStep;
        $step(
            collect([new DhcpLeaseVO(self::UPPER, null, 'host', '2026-06-11')]),
            collect(),
            app(NetworkRangeService::class),
        );

        $this->assertDatabaseCount('ip_address_mac_address', 0);
        Event::assertNotDispatched(IpMacLinked::class);
    }

    public function test_link_ip_mac_step_skips_pair_when_ip_or_mac_row_missing(): void
    {
        Event::fake([IpMacLinked::class]);

        // IP row exists but the MAC row does not, and vice versa: both sides
        // of the guard leave nothing to link.
        IpAddress::factory()->create(['address' => self::LOWER]);
        MacAddress::factory()->create(['mac_address' => 'AA:BB:CC:DD:EE:02']);

        $step = new LinkIpMacStep;
        $step(
            collect([new DhcpLeaseVO(self::UPPER, 'AA:BB:CC:DD:EE:01', 'host', '2026-06-11')]),
            collect([new ArpEntry('2a0f:85c1:d91:2100::dead', 'AA:BB:CC:DD:EE:02')]),
            app(NetworkRangeService::class),
        );

        $this->assertDatabaseCount('ip_address_mac_address', 0);
        $this->assertDatabaseMissing('audit_logs', ['action' => 'ip_mac.linked']);
        Event::assertNotDispatched(IpMacLinked::class);
    }

    public function test_persist_dhcp_leases_step_skips_lease_with_null_mac(): void
    {
        IpAddress::factory()->create(['address' => self::LOWER]);

        $step = new PersistDhcpLeasesStep;
        $step(
            collect([new DhcpLeaseVO(self::UPPER, null, 'my-device', '2026-06-11 12:00:00')]),
            app(NetworkRangeService::class),
        );

        $this->assertDatabaseCount('dhcp_leases', 0);
    }

    public function test_persist_dhcp_leases_step_skips_lease_when_ip_or_mac_row_missing(): void
    {
        // IP row exists but the MAC row does not.
        IpAddress::factory()->create(['address' => self::LOWER]);

        $step = new PersistDhcpLeasesStep;
        $step(
            collect([new DhcpLeaseVO(self::UPPER, 'AA:BB:CC:DD:EE:01', 'my-device', '2026-06-11 12:00:00')]),
            app(NetworkRangeService::class),
        );

        $this->assertDatabaseCount('dhcp_leases', 0);
    }

    public function test_persist_dhcp_leases_step_matches_existing_lowercase_row_for_uppercase_lease(): void
    {
        $ip = IpAddress::factory()->create(['address' => self::LOWER]);
        $mac = MacAddress::factory()->create(['mac_address' => 'AA:BB:CC:DD:EE:01']);

        $step = new PersistDhcpLeasesStep;
        $step(
            collect([new DhcpLeaseVO(self::UPPER, 'AA:BB:CC:DD:EE:01', 'my-device', '2026-06-11 12:00:00')]),
            app(NetworkRangeService::class),
        );

        $this->assertDatabaseHas('dhcp_leases', [
            'ip_address_id' => $ip->id,
            'mac_address_id' => $mac->id,
            'hostname' => 'my-device',
        ]);
    }
}
