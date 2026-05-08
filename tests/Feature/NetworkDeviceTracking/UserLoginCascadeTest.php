<?php

declare(strict_types=1);

namespace Tests\Feature\NetworkDeviceTracking;

use App\Models\IpAddress;
use App\Models\MacAddress;
use App\Models\Setting;
use App\Models\User;
use App\Models\UserIpAddress;
use App\Services\Interfaces\CaptivePortalInterface;
use Illuminate\Foundation\Testing\LazilyRefreshDatabase;
use Illuminate\Support\Facades\Queue;
use Mockery;
use Tests\TestCase;

class UserLoginCascadeTest extends TestCase
{
    use LazilyRefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        Queue::fake();
    }

    public function test_login_assigns_mac_ownership_when_unowned(): void
    {
        $user = User::factory()->create(['internet_blocked' => false]);
        $ip = IpAddress::factory()->create(['address' => '127.0.0.1']);
        $mac = MacAddress::factory()->create(['user_id' => null]);
        $ip->macAddresses()->attach($mac, ['source' => 'arp', 'last_seen_at' => now()]);

        $user->addIp('127.0.0.1');

        $this->assertEquals($user->id, $mac->refresh()->user_id);
    }

    public function test_login_does_not_steal_mac_from_another_user(): void
    {
        $otherUser = User::factory()->create();
        $user = User::factory()->create(['internet_blocked' => false]);
        $ip = IpAddress::factory()->create(['address' => '127.0.0.1']);
        $mac = MacAddress::factory()->create(['user_id' => $otherUser->id]);
        $ip->macAddresses()->attach($mac, ['source' => 'arp', 'last_seen_at' => now()]);

        $user->addIp('127.0.0.1');

        $this->assertEquals($otherUser->id, $mac->refresh()->user_id);
    }

    public function test_login_cascades_ip_via_shared_mac(): void
    {
        $user = User::factory()->create(['internet_blocked' => false]);
        $mac = MacAddress::factory()->create(['user_id' => null]);
        $ipv4 = IpAddress::factory()->create(['address' => '127.0.0.1']);
        $ipv6 = IpAddress::factory()->create(['address' => '::1']);

        $ipv4->macAddresses()->attach($mac, ['source' => 'arp', 'last_seen_at' => now()]);
        $ipv6->macAddresses()->attach($mac, ['source' => 'arp', 'last_seen_at' => now()]);

        $user->addIp('127.0.0.1');

        $this->assertTrue(
            UserIpAddress::where('user_id', $user->id)
                ->where('ip_address_id', $ipv6->id)
                ->exists()
        );
    }

    public function test_login_does_not_cascade_ip_owned_by_different_user(): void
    {
        $otherUser = User::factory()->create();
        $user = User::factory()->create(['internet_blocked' => false]);
        $mac = MacAddress::factory()->create(['user_id' => null]);
        $ipv4 = IpAddress::factory()->create(['address' => '127.0.0.1']);
        $ipv6 = IpAddress::factory()->create(['address' => '::1']);

        $ipv4->macAddresses()->attach($mac, ['source' => 'arp', 'last_seen_at' => now()]);
        $ipv6->macAddresses()->attach($mac, ['source' => 'arp', 'last_seen_at' => now()]);

        // Other user already owns the IPv6
        $otherUserIp = new UserIpAddress;
        $otherUserIp->user()->associate($otherUser);
        $otherUserIp->ip()->associate($ipv6);
        $otherUserIp->last_seen_at = now();
        $otherUserIp->save();

        $user->addIp('127.0.0.1');

        // IPv6 should NOT be associated with this user
        $this->assertFalse(
            UserIpAddress::where('user_id', $user->id)
                ->where('ip_address_id', $ipv6->id)
                ->exists()
        );
    }

    public function test_login_cascade_is_depth_limited(): void
    {
        $user = User::factory()->create(['internet_blocked' => false]);
        $mac1 = MacAddress::factory()->create(['user_id' => null]);
        $mac2 = MacAddress::factory()->create(['user_id' => null]);
        $ip1 = IpAddress::factory()->create(['address' => '127.0.0.1']);
        $ip2 = IpAddress::factory()->create(['address' => '127.0.0.2']);
        $ip3 = IpAddress::factory()->create(['address' => '127.0.0.3']);

        // ip1 -> mac1 -> ip2 -> mac2 -> ip3 (two hops)
        $ip1->macAddresses()->attach($mac1, ['source' => 'arp', 'last_seen_at' => now()]);
        $ip2->macAddresses()->attach($mac1, ['source' => 'arp', 'last_seen_at' => now()]);
        $ip2->macAddresses()->attach($mac2, ['source' => 'arp', 'last_seen_at' => now()]);
        $ip3->macAddresses()->attach($mac2, ['source' => 'arp', 'last_seen_at' => now()]);

        $user->addIp('127.0.0.1');

        // ip2 should be cascaded (one hop)
        $this->assertTrue(
            UserIpAddress::where('user_id', $user->id)->where('ip_address_id', $ip2->id)->exists()
        );
        // ip3 should NOT be cascaded (two hops)
        $this->assertFalse(
            UserIpAddress::where('user_id', $user->id)->where('ip_address_id', $ip3->id)->exists()
        );
    }

    public function test_login_cascade_creates_audit_logs(): void
    {
        $user = User::factory()->create(['internet_blocked' => false]);
        $ip = IpAddress::factory()->create(['address' => '127.0.0.1']);
        $siblingIp = IpAddress::factory()->create(['address' => '127.0.0.2']);
        $mac = MacAddress::factory()->create(['user_id' => null]);
        $ip->macAddresses()->attach($mac, ['source' => 'arp', 'last_seen_at' => now()]);
        $siblingIp->macAddresses()->attach($mac, ['source' => 'arp', 'last_seen_at' => now()]);

        $user->addIp('127.0.0.1');

        $this->assertDatabaseHas('audit_logs', [
            'action' => 'mac.user_assigned',
            'subject_type' => (new MacAddress)->getMorphClass(),
            'subject_id' => $mac->id,
            'process' => 'portal_login',
        ]);

        $this->assertDatabaseHas('audit_logs', [
            'action' => 'ip.user_cascaded',
            'subject_type' => (new IpAddress)->getMorphClass(),
            'subject_id' => $siblingIp->id,
            'process' => 'portal_login',
        ]);
    }

    public function test_login_cascade_respects_managed_ranges(): void
    {
        // Set managed range to 127.0.0.0/8 only
        Setting::set('network.managed_ranges_v4', 'Managed IPv4 Ranges', json_encode(['127.0.0.0/8']));

        $user = User::factory()->create(['internet_blocked' => false]);
        $mac = MacAddress::factory()->create(['user_id' => null]);
        $ipManaged = IpAddress::factory()->create(['address' => '127.0.0.1']);
        $ipUnmanaged = IpAddress::factory()->create(['address' => '192.168.1.1']);

        $ipManaged->macAddresses()->attach($mac, ['source' => 'arp', 'last_seen_at' => now()]);
        $ipUnmanaged->macAddresses()->attach($mac, ['source' => 'arp', 'last_seen_at' => now()]);

        $user->addIp('127.0.0.1');

        // The unmanaged IP should not be cascaded because addIp checks managed ranges
        $this->assertFalse(
            UserIpAddress::where('user_id', $user->id)->where('ip_address_id', $ipUnmanaged->id)->exists()
        );
    }

    public function test_login_cascade_calls_firewall_for_sibling_ips(): void
    {
        $user = User::factory()->create(['internet_enabled' => true]);
        $primaryIp = IpAddress::factory()->create(['address' => '10.0.0.1', 'internet_enabled' => true]);
        $siblingIp = IpAddress::factory()->create(['address' => '10.0.0.2']);

        $mac = MacAddress::factory()->create(['user_id' => null]);
        $primaryIp->macAddresses()->attach($mac, ['source' => 'arp', 'last_seen_at' => now()]);
        $siblingIp->macAddresses()->attach($mac, ['source' => 'arp', 'last_seen_at' => now()]);

        $captivePortal = Mockery::mock(CaptivePortalInterface::class);
        $captivePortal->shouldReceive('addIp')->with('10.0.0.1', Mockery::any())->once();
        $captivePortal->shouldReceive('addIp')->with('10.0.0.2', Mockery::any())->once();
        $this->app->instance(CaptivePortalInterface::class, $captivePortal);

        $user->addIp('10.0.0.1');
    }

    public function test_login_cascade_does_not_call_firewall_when_user_blocked(): void
    {
        $user = User::factory()->create([
            'internet_enabled' => true,
            'internet_blocked' => true,
        ]);
        $primaryIp = IpAddress::factory()->create(['address' => '10.0.0.1']);
        $siblingIp = IpAddress::factory()->create(['address' => '10.0.0.2']);

        $mac = MacAddress::factory()->create(['user_id' => null]);
        $primaryIp->macAddresses()->attach($mac, ['source' => 'arp', 'last_seen_at' => now()]);
        $siblingIp->macAddresses()->attach($mac, ['source' => 'arp', 'last_seen_at' => now()]);

        $captivePortal = Mockery::mock(CaptivePortalInterface::class);
        $captivePortal->shouldNotReceive('addIp');

        $this->app->instance(CaptivePortalInterface::class, $captivePortal);

        $user->addIp('10.0.0.1');
    }
}
