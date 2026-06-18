<?php

declare(strict_types=1);

namespace Tests\Feature\NetworkDeviceTracking;

use App\Jobs\ScanNetworkDevices;
use App\Models\IpAddress;
use App\Models\MacAddress;
use App\Models\User;
use App\Models\UserIpAddress;
use App\Services\Interfaces\DhcpInterface;
use App\Services\Interfaces\IpMacResolverInterface;
use App\Services\Interfaces\PortMacInterface;
use App\Services\ValueObjects\DhcpLease as DhcpLeaseVO;
use Illuminate\Foundation\Testing\LazilyRefreshDatabase;
use Illuminate\Support\Facades\Queue;
use Mockery\MockInterface;
use Tests\TestCase;

/**
 * End-to-end regression for the production bug: an uppercase DHCPv6 lease for
 * an already-known (lowercase) IPv6 must not create a duplicate row, must link
 * the MAC, and must cascade the MAC's owner onto the IP via the real (unfaked)
 * IpMacLinked event and listener.
 */
class ScanNetworkDevicesOwnerCascadeTest extends TestCase
{
    use LazilyRefreshDatabase;

    private const LOWER = '2a0f:85c1:d91:2100:7485:ac59:8bc9:72e6';

    private const UPPER = '2A0F:85C1:D91:2100:7485:AC59:8BC9:72E6';

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
                'getArpTable' => collect(),
            ]);
        });

        $this->mock(PortMacInterface::class, function (MockInterface $mock): void {
            $mock->allows([
                'getForwardingDatabase' => collect(),
            ]);
        });
    }

    public function test_uppercase_lease_for_owned_mac_links_and_cascades_owner_without_duplicating_ip(): void
    {
        $owner = User::factory()->create(['internet_blocked' => false, 'internet_enabled' => false]);
        $mac = MacAddress::factory()->create(['mac_address' => 'AA:BB:CC:DD:EE:10', 'user_id' => $owner->id]);
        $ip = IpAddress::factory()->create(['address' => self::LOWER, 'last_seen_at' => now()->subDay()]);

        $this->mockDhcp([new DhcpLeaseVO(self::UPPER, 'AA:BB:CC:DD:EE:10', 'owned-device', '2026-06-11')]);
        $this->mockInventory();

        (new ScanNetworkDevices)->handle();

        // Exactly one row for the address: no case-variant duplicate created
        $this->assertSame(1, IpAddress::count());
        $this->assertSame(1, IpAddress::where('address', self::LOWER)->count());

        // The scan linked the lease MAC to the existing row
        $this->assertDatabaseHas('ip_address_mac_address', [
            'ip_address_id' => $ip->id,
            'mac_address_id' => $mac->id,
            'source' => 'dhcp',
        ]);

        // The real IpMacLinked event fired and the listener cascaded ownership
        $this->assertTrue(
            UserIpAddress::where('user_id', $owner->id)->where('ip_address_id', $ip->id)->exists()
        );
        $this->assertDatabaseHas('audit_logs', [
            'action' => 'ip.user_cascaded',
            'subject_type' => (new IpAddress)->getMorphClass(),
            'subject_id' => $ip->id,
            'process' => 'scan_network',
        ]);
    }
}
