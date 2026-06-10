<?php

declare(strict_types=1);

namespace Tests\Feature\NetworkDeviceTracking;

use App\Events\IpMacLinked;
use App\Listeners\CascadeMacOwnershipOnLink;
use App\Models\AuditLog;
use App\Models\IpAddress;
use App\Models\MacAddress;
use App\Models\User;
use App\Models\UserIpAddress;
use App\Services\Interfaces\CaptivePortalInterface;
use App\Services\Interfaces\MacAddressResolverInterface;
use App\Services\IpAddressActionService;
use App\Services\NetworkRangeService;
use Illuminate\Foundation\Testing\LazilyRefreshDatabase;
use Illuminate\Support\Facades\Queue;
use Mockery;
use Tests\TestCase;

class CascadeMacOwnershipOnLinkTest extends TestCase
{
    use LazilyRefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        Queue::fake();
    }

    private function handleEvent(IpAddress $ip, MacAddress $mac, string $source = 'dhcp', string $process = 'scan_network'): void
    {
        /** @var CascadeMacOwnershipOnLink $listener */
        $listener = app(CascadeMacOwnershipOnLink::class);
        $listener->handle(new IpMacLinked($ip, $mac, $source, $process));
    }

    public function test_cascades_mac_owner_onto_unassociated_ip(): void
    {
        $owner = User::factory()->create(['internet_blocked' => false]);
        $ip = IpAddress::factory()->create(['address' => '2a0f:85c1:d91:2100::10']);
        $mac = MacAddress::factory()->create(['mac_address' => 'AA:BB:CC:DD:EE:01', 'user_id' => $owner->id]);

        $this->handleEvent($ip, $mac);

        $this->assertTrue(
            UserIpAddress::where('user_id', $owner->id)->where('ip_address_id', $ip->id)->exists()
        );

        $this->assertDatabaseHas('audit_logs', [
            'action' => 'ip.user_cascaded',
            'subject_type' => (new IpAddress)->getMorphClass(),
            'subject_id' => $ip->id,
            'process' => 'scan_network',
        ]);

        $log = AuditLog::where('action', 'ip.user_cascaded')->first();
        $this->assertNotNull($log);
        $this->assertSame('dhcp', $log->metadata['source']);
        $this->assertSame('AA:BB:CC:DD:EE:01', $log->metadata['mac']);
    }

    public function test_audit_process_comes_from_the_event(): void
    {
        $owner = User::factory()->create(['internet_blocked' => false]);
        $ip = IpAddress::factory()->create(['address' => '2a0f:85c1:d91:2100::11']);
        $mac = MacAddress::factory()->create(['mac_address' => 'AA:BB:CC:DD:EE:02', 'user_id' => $owner->id]);

        $this->handleEvent($ip, $mac, source: 'auth', process: 'auth');

        $this->assertDatabaseHas('audit_logs', [
            'action' => 'ip.user_cascaded',
            'subject_id' => $ip->id,
            'process' => 'auth',
        ]);
    }

    public function test_does_nothing_when_mac_is_unowned(): void
    {
        $ip = IpAddress::factory()->create(['address' => '2a0f:85c1:d91:2100::12']);
        $mac = MacAddress::factory()->create(['mac_address' => 'AA:BB:CC:DD:EE:03', 'user_id' => null]);

        $this->handleEvent($ip, $mac);

        $this->assertSame(0, UserIpAddress::count());
        $this->assertDatabaseMissing('audit_logs', ['action' => 'ip.user_cascaded']);
    }

    public function test_no_association_or_audit_when_ip_is_unmanaged(): void
    {
        $owner = User::factory()->create(['internet_blocked' => false]);
        $ip = IpAddress::factory()->create(['address' => '192.0.2.50']);
        $mac = MacAddress::factory()->create(['mac_address' => 'AA:BB:CC:DD:EE:07', 'user_id' => $owner->id]);

        // addIp returns null for unmanaged addresses: no association, no audit.
        $rangeService = Mockery::mock(NetworkRangeService::class);
        $rangeService->allows(['isManaged' => false]);

        $this->app->instance(NetworkRangeService::class, $rangeService);

        $this->handleEvent($ip, $mac);

        $this->assertSame(0, UserIpAddress::count());
        $this->assertDatabaseMissing('audit_logs', ['action' => 'ip.user_cascaded']);
    }

    public function test_does_nothing_when_ip_is_associated_with_a_different_user(): void
    {
        $owner = User::factory()->create(['internet_blocked' => false]);
        $otherUser = User::factory()->create();
        $ip = IpAddress::factory()->create(['address' => '2a0f:85c1:d91:2100::13']);
        $mac = MacAddress::factory()->create(['mac_address' => 'AA:BB:CC:DD:EE:04', 'user_id' => $owner->id]);

        $existing = new UserIpAddress;
        $existing->user()->associate($otherUser);
        $existing->ip()->associate($ip);
        $existing->last_seen_at = now();
        $existing->save();

        $this->handleEvent($ip, $mac);

        $this->assertFalse(
            UserIpAddress::where('user_id', $owner->id)->where('ip_address_id', $ip->id)->exists()
        );
        $this->assertTrue(
            UserIpAddress::where('user_id', $otherUser->id)->where('ip_address_id', $ip->id)->exists()
        );
        $this->assertDatabaseMissing('audit_logs', ['action' => 'ip.user_cascaded']);
    }

    public function test_does_not_duplicate_association_or_audit_when_owner_already_associated(): void
    {
        $owner = User::factory()->create(['internet_blocked' => false]);
        $ip = IpAddress::factory()->create(['address' => '2a0f:85c1:d91:2100::14']);
        $mac = MacAddress::factory()->create(['mac_address' => 'AA:BB:CC:DD:EE:05', 'user_id' => $owner->id]);

        $existing = new UserIpAddress;
        $existing->user()->associate($owner);
        $existing->ip()->associate($ip);
        $existing->last_seen_at = now();
        $existing->save();

        $this->handleEvent($ip, $mac);

        $this->assertSame(1, UserIpAddress::where('user_id', $owner->id)->where('ip_address_id', $ip->id)->count());
        $this->assertSame(0, AuditLog::where('action', 'ip.user_cascaded')->count());
    }

    public function test_enable_internet_heals_missing_owner_association_end_to_end(): void
    {
        // Production scenario: pivot was created via an admin internet toggle
        // (source 'auth') but the MAC owner never got associated with the IP.
        // The event is NOT faked: dispatch -> listener -> association.
        $owner = User::factory()->create(['internet_blocked' => false, 'internet_enabled' => false]);
        $ip = IpAddress::factory()->create(['address' => '2a0f:85c1:d91:2100::15', 'internet_enabled' => false]);
        MacAddress::factory()->create(['mac_address' => 'AA:BB:CC:DD:EE:06', 'user_id' => $owner->id]);

        $captivePortal = Mockery::mock(CaptivePortalInterface::class);
        $captivePortal->allows(['addIp' => null]);

        $this->app->instance(CaptivePortalInterface::class, $captivePortal);

        $macResolver = Mockery::mock(MacAddressResolverInterface::class);
        $macResolver->allows(['resolveIpToMac' => 'AA:BB:CC:DD:EE:06']);

        $this->app->instance(MacAddressResolverInterface::class, $macResolver);

        /** @var IpAddressActionService $service */
        $service = app(IpAddressActionService::class);
        $service->enableInternet($ip);

        $this->assertTrue(
            UserIpAddress::where('user_id', $owner->id)->where('ip_address_id', $ip->id)->exists()
        );
        $this->assertDatabaseHas('audit_logs', [
            'action' => 'ip.user_cascaded',
            'subject_type' => (new IpAddress)->getMorphClass(),
            'subject_id' => $ip->id,
            'process' => 'auth',
        ]);
    }
}
