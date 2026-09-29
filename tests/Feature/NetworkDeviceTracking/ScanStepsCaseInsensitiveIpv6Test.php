<?php

declare(strict_types=1);

namespace Tests\Feature\NetworkDeviceTracking;

use App\Events\IpMacLinked;
use App\Jobs\ScanNetworkDevices;
use App\Models\IpAddress;
use App\Models\MacAddress;
use App\Services\Dhcp\DhcpSyncService;
use App\Services\Interfaces\DhcpInterface;
use App\Services\Interfaces\IpMacResolverInterface;
use App\Services\Interfaces\PortMacInterface;
use App\Services\ValueObjects\DhcpLease as DhcpLeaseVO;
use App\Services\ValueObjects\DhcpSnapshot;
use App\Services\ValueObjects\IpMacEntry;
use Illuminate\Foundation\Testing\LazilyRefreshDatabase;
use Illuminate\Support\Facades\Event;
use Mockery\MockInterface;
use Tests\Feature\Concerns\CreatesAdminUsers;
use Tests\TestCase;

class ScanStepsCaseInsensitiveIpv6Test extends TestCase
{
    use CreatesAdminUsers;
    use LazilyRefreshDatabase;

    private const LOWER = '2a0f:85c1:d91:2100:7485:ac59:8bc9:72e6';

    private const UPPER = '2A0F:85C1:D91:2100:7485:AC59:8BC9:72E6';

    /**
     * @param  list<DhcpLeaseVO>  $leases
     * @param  list<IpMacEntry>  $entries
     */
    private function scan(array $leases = [], array $entries = []): void
    {
        $this->mock(DhcpInterface::class, fn (MockInterface $m) => $m->allows(['snapshot' => DhcpSnapshot::create(collect($leases), collect())]));
        $this->mock(IpMacResolverInterface::class, fn (MockInterface $m) => $m->allows(['getIpMacTable' => collect($entries)]));
        $this->mock(PortMacInterface::class, fn (MockInterface $m) => $m->allows(['getForwardingDatabase' => collect()]));

        app()->call([new ScanNetworkDevices, 'handle']);
    }

    public function test_persist_ips_matches_existing_lowercase_row_for_uppercase_dhcp_lease(): void
    {
        $ip = IpAddress::factory()->create([
            'address' => self::LOWER,
            'last_seen_at' => now()->subDays(7),
        ]);

        $this->scan([new DhcpLeaseVO(self::UPPER, 'AA:BB:CC:DD:EE:01', 'host', '2026-06-11')]);

        $this->assertSame(1, IpAddress::count());

        $freshIp = $ip->fresh();
        $this->assertNotNull($freshIp);
        $this->assertTrue($freshIp->last_seen_at->isToday());

        $this->assertDatabaseMissing('audit_logs', ['action' => 'ip.created']);
    }

    public function test_persist_ips_matches_existing_lowercase_row_for_uppercase_arp_entry(): void
    {
        $ip = IpAddress::factory()->create([
            'address' => self::LOWER,
            'last_seen_at' => now()->subDays(7),
        ]);

        $this->scan(entries: [new IpMacEntry(self::UPPER, 'AA:BB:CC:DD:EE:01')]);

        $this->assertSame(1, IpAddress::count());

        $freshIp = $ip->fresh();
        $this->assertNotNull($freshIp);
        $this->assertTrue($freshIp->last_seen_at->isToday());

        $this->assertDatabaseMissing('audit_logs', ['action' => 'ip.created']);
    }

    public function test_link_ip_mac_links_uppercase_lease_to_lowercase_row_and_dispatches_event(): void
    {
        Event::fake([IpMacLinked::class]);

        $ip = IpAddress::factory()->create(['address' => self::LOWER]);
        $mac = MacAddress::factory()->create(['mac_address' => 'AA:BB:CC:DD:EE:01']);

        $this->scan([new DhcpLeaseVO(self::UPPER, 'AA:BB:CC:DD:EE:01', 'host', '2026-06-11')]);

        $this->assertSame(1, IpAddress::count());
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

    public function test_link_ip_mac_dispatches_event_on_update_existing_pivot_branch(): void
    {
        Event::fake([IpMacLinked::class]);

        $ip = IpAddress::factory()->create(['address' => self::LOWER]);
        $mac = MacAddress::factory()->create(['mac_address' => 'AA:BB:CC:DD:EE:01']);
        $ip->macAddresses()->attach($mac, ['source' => 'dhcp', 'last_seen_at' => now()->subHour()]);

        $this->scan([new DhcpLeaseVO(self::UPPER, 'AA:BB:CC:DD:EE:01', 'host', '2026-06-11')]);

        $this->assertSame(1, $ip->macAddresses()->count());

        Event::assertDispatched(IpMacLinked::class, fn (IpMacLinked $event): bool => $event->ip->is($ip)
            && $event->mac->is($mac)
            && $event->source === 'dhcp'
            && $event->process === 'scan_network');
    }

    public function test_link_ip_mac_skips_lease_with_null_mac(): void
    {
        Event::fake([IpMacLinked::class]);

        IpAddress::factory()->create(['address' => self::LOWER]);

        $this->scan([new DhcpLeaseVO(self::UPPER, null, 'host', '2026-06-11')]);

        $this->assertDatabaseCount('ip_address_mac_address', 0);
        Event::assertNotDispatched(IpMacLinked::class);
    }

    public function test_sync_dhcp_data_matches_existing_lowercase_row_for_uppercase_lease(): void
    {
        $ip = IpAddress::factory()->create(['address' => self::LOWER]);
        $mac = MacAddress::factory()->create(['mac_address' => 'AA:BB:CC:DD:EE:01']);

        (new DhcpSyncService)->syncLeases(
            'cisco',
            DhcpSnapshot::create(collect([new DhcpLeaseVO(self::UPPER, 'AA:BB:CC:DD:EE:01', 'my-device', '2026-06-11 12:00:00')]), collect()),
        );

        $this->assertSame(1, IpAddress::count());
        $this->assertDatabaseHas('dhcp_leases', [
            'ip_address_id' => $ip->id,
            'mac_address_id' => $mac->id,
            'hostname' => 'my-device',
        ]);
    }
}
