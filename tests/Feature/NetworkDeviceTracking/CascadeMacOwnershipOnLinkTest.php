<?php

declare(strict_types=1);

namespace Tests\Feature\NetworkDeviceTracking;

use App\Events\IpMacLinked;
use App\Listeners\CascadeMacOwnershipOnLink;
use App\Models\AuditLog;
use App\Models\DhcpLease;
use App\Models\IpAddress;
use App\Models\MacAddress;
use App\Models\User;
use App\Models\UserIpAddress;
use App\Services\Interfaces\CaptivePortalInterface;
use App\Services\Interfaces\MacAddressResolverInterface;
use App\Services\IpAddressActionService;
use App\Services\NetworkRangeService;
use Illuminate\Foundation\Testing\LazilyRefreshDatabase;
use Mockery;
use Tests\Feature\Concerns\CreatesAdminUsers;
use Tests\TestCase;

class CascadeMacOwnershipOnLinkTest extends TestCase
{
    use CreatesAdminUsers;
    use LazilyRefreshDatabase;

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

    public function test_non_lease_evidence_does_not_reassign_ip_owned_by_a_different_user(): void
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

        $this->handleEvent($ip, $mac, source: 'arp');

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

    private function associate(User $user, IpAddress $ip): void
    {
        $link = new UserIpAddress;
        $link->user()->associate($user);
        $link->ip()->associate($ip);
        $link->last_seen_at = now();
        $link->save();
    }

    public function test_re_lease_to_another_users_mac_moves_ownership_and_applies_policy(): void
    {
        $previous = User::factory()->create();
        $newOwner = User::factory()->create(['internet_blocked' => false, 'internet_enabled' => true]);
        $ip = IpAddress::factory()->create(['address' => '2a0f:85c1:d91:2100::20', 'internet_enabled' => false]);
        $oldMac = MacAddress::factory()->create(['mac_address' => 'AA:BB:CC:DD:EE:10', 'user_id' => $previous->id]);
        $newMac = MacAddress::factory()->create(['mac_address' => 'AA:BB:CC:DD:EE:11', 'user_id' => $newOwner->id]);
        $this->associate($previous, $ip);
        DhcpLease::factory()->create(['ip_address_id' => $ip->id, 'mac_address_id' => $newMac->id]);

        $this->handleEvent($ip, $newMac);

        $this->assertFalse(UserIpAddress::where('user_id', $previous->id)->where('ip_address_id', $ip->id)->exists());
        $this->assertTrue(UserIpAddress::where('user_id', $newOwner->id)->where('ip_address_id', $ip->id)->exists());
        $this->assertTrue((bool) $ip->fresh()->internet_enabled);

        $log = AuditLog::where('action', 'ip.user_reassigned')->firstOrFail();
        $this->assertSame([$previous->id], $log->metadata['previous_user_ids']);
        $this->assertSame($newOwner->id, $log->metadata['new_user_id']);
        $this->assertNotNull($oldMac);
    }

    public function test_re_lease_to_unowned_mac_releases_ip_and_resets_policy(): void
    {
        $previous = User::factory()->create();
        $ip = IpAddress::factory()->create(['address' => '2a0f:85c1:d91:2100::21', 'internet_enabled' => true]);
        $newMac = MacAddress::factory()->create(['mac_address' => 'AA:BB:CC:DD:EE:12', 'user_id' => null]);
        $this->associate($previous, $ip);

        $this->handleEvent($ip, $newMac);

        $this->assertSame(0, UserIpAddress::where('ip_address_id', $ip->id)->count());
        $this->assertFalse((bool) $ip->fresh()->internet_enabled);
        $log = AuditLog::where('action', 'ip.user_reassigned')->firstOrFail();
        $this->assertNull($log->metadata['new_user_id']);
    }

    public function test_ip_is_kept_while_previous_owners_mac_still_holds_a_lease(): void
    {
        $previous = User::factory()->create();
        $newOwner = User::factory()->create();
        $ip = IpAddress::factory()->create(['address' => '2a0f:85c1:d91:2100::22']);
        $oldMac = MacAddress::factory()->create(['mac_address' => 'AA:BB:CC:DD:EE:13', 'user_id' => $previous->id]);
        $newMac = MacAddress::factory()->create(['mac_address' => 'AA:BB:CC:DD:EE:14', 'user_id' => $newOwner->id]);
        $this->associate($previous, $ip);
        DhcpLease::factory()->create(['ip_address_id' => $ip->id, 'mac_address_id' => $oldMac->id]);

        $this->handleEvent($ip, $newMac);

        $this->assertTrue(UserIpAddress::where('user_id', $previous->id)->where('ip_address_id', $ip->id)->exists());
        $this->assertDatabaseMissing('audit_logs', ['action' => 'ip.user_reassigned']);
    }

    public function test_duid_derived_mac_never_triggers_cascade_or_reassignment(): void
    {
        $previous = User::factory()->create();
        $cloneOwner = User::factory()->create();
        $ip = IpAddress::factory()->create(['address' => '2a0f:85c1:d91:2100::23']);
        $freeIp = IpAddress::factory()->create(['address' => '2a0f:85c1:d91:2100::24']);
        $duidMac = MacAddress::factory()->create([
            'mac_address' => 'AA:BB:CC:DD:EE:15',
            'user_id' => $cloneOwner->id,
            'source' => MacAddress::SOURCE_DHCP_DUID,
        ]);
        $this->associate($previous, $ip);

        $this->handleEvent($ip, $duidMac, source: MacAddress::SOURCE_DHCP_DUID);
        $this->handleEvent($freeIp, $duidMac, source: MacAddress::SOURCE_DHCP_DUID);

        $this->assertTrue(UserIpAddress::where('user_id', $previous->id)->where('ip_address_id', $ip->id)->exists());
        $this->assertSame(0, UserIpAddress::where('ip_address_id', $freeIp->id)->count());
        $this->assertDatabaseMissing('audit_logs', ['action' => 'ip.user_reassigned']);
    }
}
