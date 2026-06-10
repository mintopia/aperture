<?php

namespace Tests\Feature\Admin;

use App\Models\AuditLog;
use App\Models\IpAddress;
use App\Models\Role;
use App\Models\User;
use App\Models\UserIpAddress;
use App\Services\Interfaces\IpBandwidthInterface;
use App\Services\ValueObjects\IpBandwidthResult;
use Illuminate\Foundation\Testing\LazilyRefreshDatabase;
use Illuminate\Support\Facades\Queue;
use Mockery\MockInterface;
use Tests\TestCase;

class UserControllerTest extends TestCase
{
    use LazilyRefreshDatabase;

    protected function createAdminUser(): User
    {
        $user = User::factory()->create();
        $role = new Role;
        $role->code = 'admin';
        $role->name = 'Admin';
        $role->save();
        $user->roles()->attach($role);

        return $user;
    }

    protected function linkIpToUser(User $user, IpAddress $ip): UserIpAddress
    {
        $userIp = new UserIpAddress;
        $userIp->user()->associate($user);
        $userIp->ip()->associate($ip);
        $userIp->last_seen_at = now();
        $userIp->save();

        return $userIp;
    }

    public function test_admin_can_view_users_index(): void
    {
        Queue::fake();
        $admin = $this->createAdminUser();
        User::factory()->count(3)->create();

        $response = $this->actingAs($admin)->get('/admin/users');

        $response->assertOk();
        $response->assertInertia(fn ($page) => $page
            ->component('Admin/Users/Index')
            ->has('users')
        );
    }

    public function test_admin_can_view_user_detail(): void
    {
        Queue::fake();
        $admin = $this->createAdminUser();
        $user = User::factory()->create();

        $response = $this->actingAs($admin)->get('/admin/users/'.$user->id);

        $response->assertOk();
        $response->assertInertia(fn ($page) => $page
            ->component('Admin/Users/Show')
            ->has('user')
            ->has('networkDevices')
            ->has('roles')
        );
    }

    public function test_admin_can_block_user(): void
    {
        Queue::fake();
        $admin = $this->createAdminUser();
        $user = User::factory()->create(['internet_blocked' => false]);

        $response = $this->actingAs($admin)->post(sprintf('/admin/users/%d/block', $user->id), [
            'block' => true,
        ]);

        $response->assertRedirect();
        $this->assertTrue((bool) $user->fresh()->internet_blocked);
    }

    public function test_admin_can_unblock_user(): void
    {
        Queue::fake();
        $admin = $this->createAdminUser();
        $user = User::factory()->internetBlocked()->create();

        $response = $this->actingAs($admin)->post(sprintf('/admin/users/%d/block', $user->id), [
            'block' => false,
        ]);

        $response->assertRedirect();
        $this->assertFalse((bool) $user->fresh()->internet_blocked);
    }

    public function test_non_admin_cannot_view_users(): void
    {
        Queue::fake();
        $user = User::factory()->create();

        $response = $this->actingAs($user)->get('/admin/users');

        $response->assertForbidden();
    }

    public function test_admin_can_filter_users_by_nickname(): void
    {
        Queue::fake();
        $admin = $this->createAdminUser();
        User::factory()->create(['nickname' => 'TargetUser']);
        User::factory()->create(['nickname' => 'OtherUser']);

        $response = $this->actingAs($admin)->get('/admin/users?nickname=TargetUser');

        $response->assertOk();
        $response->assertInertia(fn ($page) => $page->has('users.data', 1));
    }

    public function test_admin_can_filter_users_by_ip(): void
    {
        Queue::fake();
        $admin = $this->createAdminUser();
        $user = User::factory()->create(['nickname' => 'IpUser']);

        $ip = new IpAddress;
        $ip->address = '10.0.0.99';
        $ip->last_seen_at = now();
        $ip->save();

        $user->addIp('10.0.0.99');

        $response = $this->actingAs($admin)->get('/admin/users?ip=10.0.0.99');

        $response->assertOk();
        $response->assertInertia(fn ($page) => $page->has('users.data', 1));
    }

    public function test_admin_can_filter_users_by_ipv6_case_insensitively(): void
    {
        Queue::fake();
        $admin = $this->createAdminUser();
        $user = User::factory()->create(['nickname' => 'Ipv6User']);

        $ip = new IpAddress;
        $ip->address = '2001:db8::1';
        $ip->last_seen_at = now();
        $ip->save();

        $user->addIp('2001:db8::1');

        $response = $this->actingAs($admin)->get('/admin/users?ip='.urlencode('2001:DB8::1'));

        $response->assertOk();
        $response->assertInertia(fn ($page) => $page->has('users.data', 1));
    }

    public function test_admin_can_enable_internet_for_all_user_ips(): void
    {
        Queue::fake();
        $admin = $this->createAdminUser();
        $user = User::factory()->create();

        $ip1 = IpAddress::factory()->create(['internet_enabled' => false]);
        $ip2 = IpAddress::factory()->create(['internet_enabled' => false]);
        $user->addIp($ip1->address);
        $user->addIp($ip2->address);

        $response = $this->actingAs($admin)->post(route('admin.users.internet', $user), ['enable' => true]);

        $response->assertRedirect();
        $this->assertTrue($ip1->fresh()->internet_enabled);
        $this->assertTrue($ip2->fresh()->internet_enabled);
    }

    public function test_admin_can_disable_internet_for_all_user_ips(): void
    {
        Queue::fake();
        $admin = $this->createAdminUser();
        $user = User::factory()->create();

        $ip = IpAddress::factory()->create(['internet_enabled' => true]);
        $user->addIp($ip->address);

        $response = $this->actingAs($admin)->post(route('admin.users.internet', $user), ['enable' => false]);

        $response->assertRedirect();
        $this->assertFalse($ip->fresh()->internet_enabled);
    }

    public function test_admin_can_enable_rate_limit_for_all_user_ips(): void
    {
        Queue::fake();
        $admin = $this->createAdminUser();
        $user = User::factory()->create();

        $ip1 = IpAddress::factory()->create(['rate_limit_enabled' => false]);
        $ip2 = IpAddress::factory()->create(['rate_limit_enabled' => false]);
        $user->addIp($ip1->address);
        $user->addIp($ip2->address);

        $response = $this->actingAs($admin)->post(route('admin.users.limit', $user), ['limit' => true]);

        $response->assertRedirect();
        $this->assertTrue($ip1->fresh()->rate_limit_enabled);
        $this->assertTrue($ip2->fresh()->rate_limit_enabled);
    }

    public function test_admin_can_remove_rate_limit_for_all_user_ips(): void
    {
        Queue::fake();
        $admin = $this->createAdminUser();
        $user = User::factory()->create();

        $ip = IpAddress::factory()->create(['rate_limit_enabled' => true]);
        $user->addIp($ip->address);

        $response = $this->actingAs($admin)->post(route('admin.users.limit', $user), ['limit' => false]);

        $response->assertRedirect();
        $this->assertFalse($ip->fresh()->rate_limit_enabled);
    }

    public function test_admin_can_update_user_roles(): void
    {
        Queue::fake();
        $admin = $this->createAdminUser();
        $user = User::factory()->create();
        $moderator = new Role;
        $moderator->code = 'moderator';
        $moderator->name = 'Moderator';
        $moderator->save();

        $response = $this->actingAs($admin)->put(route('admin.users.update', $user), [
            'nickname' => $user->nickname,
            'email' => $user->email,
            'roles' => ['admin', 'moderator'],
        ]);

        $response->assertRedirect();
        $this->assertTrue($user->fresh()->roles->contains('code', 'admin'));
        $this->assertTrue($user->fresh()->roles->contains('code', 'moderator'));
    }

    public function test_admin_can_remove_all_roles(): void
    {
        Queue::fake();
        $admin = $this->createAdminUser();
        $user = User::factory()->create();
        $role = Role::where('code', 'admin')->first();
        $user->roles()->attach($role);

        $response = $this->actingAs($admin)->put(route('admin.users.update', $user), [
            'nickname' => $user->nickname,
            'email' => $user->email,
            'roles' => [],
        ]);

        $response->assertRedirect();
        $this->assertEmpty($user->fresh()->roles);
    }

    public function test_show_includes_network_devices_and_action_flags(): void
    {
        Queue::fake();
        $admin = $this->createAdminUser();
        $user = User::factory()->create();

        $ip = IpAddress::factory()->create(['internet_enabled' => true, 'rate_limit_enabled' => false]);
        $this->linkIpToUser($user, $ip);

        $response = $this->actingAs($admin)->get(route('admin.users.show', $user));

        $response->assertOk();
        $response->assertInertia(fn ($page) => $page
            ->component('Admin/Users/Show')
            ->has('networkDevices')
            ->where('allInternetEnabled', true)
            ->where('allRateLimited', false)
            ->where('ipCount', 1)
        );
    }

    public function test_edit_includes_available_roles(): void
    {
        Queue::fake();
        $admin = $this->createAdminUser();
        $user = User::factory()->create();

        $response = $this->actingAs($admin)->get(route('admin.users.edit', $user));

        $response->assertOk();
        $response->assertInertia(fn ($page) => $page
            ->component('Admin/Users/Edit')
            ->has('availableRoles')
        );
    }

    public function test_admin_can_fetch_user_bandwidth(): void
    {
        Queue::fake();
        $admin = $this->createAdminUser();
        $user = User::factory()->create();

        $ip1 = IpAddress::factory()->create();
        $ip2 = IpAddress::factory()->create();
        $this->linkIpToUser($user, $ip1);
        $this->linkIpToUser($user, $ip2);

        $this->mock(IpBandwidthInterface::class, function (MockInterface $mock) use ($ip1, $ip2): void {
            $mock->shouldReceive('getIpBandwidth')
                ->withArgs(function (array $ips) use ($ip1, $ip2): bool {
                    return count($ips) === 2
                        && in_array($ip1->address, $ips, true)
                        && in_array($ip2->address, $ips, true);
                })
                ->once()
                ->andReturn(new IpBandwidthResult(
                    received: 2048000,
                    sent: 1024000,
                    timestamps: ['1700000000', '1700000060'],
                    download: [8192.0, 9000.0],
                    upload: [4096.0, 4500.0],
                ));
        });

        $response = $this->actingAs($admin)->getJson(route('admin.users.bandwidth', $user));

        $response->assertOk()
            ->assertJsonStructure(['timestamps', 'download', 'upload', 'totalReceived', 'totalSent']);
    }

    public function test_admin_user_bandwidth_accepts_range_parameter(): void
    {
        Queue::fake();
        $admin = $this->createAdminUser();
        $user = User::factory()->create();

        $ip = IpAddress::factory()->create();
        $this->linkIpToUser($user, $ip);

        $this->mock(IpBandwidthInterface::class, function (MockInterface $mock): void {
            $mock->shouldReceive('getIpBandwidth')
                ->withArgs(fn (array $ips, string $range): bool => $range === '4d')
                ->once()
                ->andReturn(new IpBandwidthResult(
                    received: 0,
                    sent: 0,
                    timestamps: [],
                    download: [],
                    upload: [],
                ));
        });

        $response = $this->actingAs($admin)->getJson(route('admin.users.bandwidth', $user).'?range=4d');

        $response->assertOk();
    }

    public function test_user_bandwidth_returns_empty_when_no_ips(): void
    {
        Queue::fake();
        $admin = $this->createAdminUser();
        $user = User::factory()->create();

        $response = $this->actingAs($admin)->getJson(route('admin.users.bandwidth', $user));

        $response->assertOk()
            ->assertJson([
                'timestamps' => [],
                'download' => [],
                'upload' => [],
                'totalReceived' => 0,
                'totalSent' => 0,
            ]);
    }

    public function test_show_network_devices_include_ip_address(): void
    {
        Queue::fake();
        $admin = $this->createAdminUser();
        $user = User::factory()->create();

        $ip = IpAddress::factory()->create([
            'internet_enabled' => true,
            'rate_limit_enabled' => false,
        ]);
        $this->linkIpToUser($user, $ip);

        $response = $this->actingAs($admin)->get(route('admin.users.show', $user));

        $response->assertOk();
        $response->assertInertia(fn ($page) => $page
            ->component('Admin/Users/Show')
            ->has('networkDevices', 1)
            ->where('networkDevices.0.ip_address', $ip->address)
            ->where('networkDevices.0.internet_enabled', true)
        );
    }

    public function test_non_admin_cannot_fetch_user_bandwidth(): void
    {
        Queue::fake();
        $user = User::factory()->create();

        $this->actingAs($user)->getJson('/admin/users/'.$user->id.'/bandwidth')
            ->assertForbidden();
    }

    public function test_block_toggle_creates_audit_log(): void
    {
        Queue::fake();
        $admin = $this->createAdminUser();
        $user = User::factory()->create(['internet_blocked' => false]);

        $this->actingAs($admin)->post(route('admin.users.block', $user), ['block' => 1]);

        $this->assertDatabaseHas('audit_logs', [
            'action' => 'user.block_toggled',
            'subject_type' => $user->getMorphClass(),
            'subject_id' => $user->id,
            'process' => 'admin',
        ]);
        $log = AuditLog::where('action', 'user.block_toggled')->first();
        $this->assertNotNull($log);
        $this->assertTrue((bool) $log->metadata['blocked']);
    }

    public function test_user_internet_bulk_toggle_creates_audit_logs_per_ip(): void
    {
        Queue::fake();
        $admin = $this->createAdminUser();
        $user = User::factory()->create();

        $ip1 = IpAddress::factory()->create(['internet_enabled' => false]);
        $ip2 = IpAddress::factory()->create(['internet_enabled' => false]);
        $user->addIp($ip1->address);
        $user->addIp($ip2->address);

        $this->actingAs($admin)->post(route('admin.users.internet', $user), ['enable' => 1]);

        $this->assertDatabaseHas('audit_logs', [
            'action' => 'ip.internet_toggled',
            'subject_type' => $ip1->getMorphClass(),
            'subject_id' => $ip1->id,
            'process' => 'admin',
        ]);
        $this->assertDatabaseHas('audit_logs', [
            'action' => 'ip.internet_toggled',
            'subject_type' => $ip2->getMorphClass(),
            'subject_id' => $ip2->id,
            'process' => 'admin',
        ]);
        $this->assertCount(2, AuditLog::where('action', 'ip.internet_toggled')->get());
    }

    public function test_user_limit_bulk_toggle_creates_audit_logs_per_ip(): void
    {
        Queue::fake();
        $admin = $this->createAdminUser();
        $user = User::factory()->create();

        $ip1 = IpAddress::factory()->create(['rate_limit_enabled' => false]);
        $ip2 = IpAddress::factory()->create(['rate_limit_enabled' => false]);
        $user->addIp($ip1->address);
        $user->addIp($ip2->address);

        $this->actingAs($admin)->post(route('admin.users.limit', $user), ['limit' => 1]);

        $this->assertDatabaseHas('audit_logs', [
            'action' => 'ip.rate_limit_toggled',
            'subject_type' => $ip1->getMorphClass(),
            'subject_id' => $ip1->id,
            'process' => 'admin',
        ]);
        $this->assertDatabaseHas('audit_logs', [
            'action' => 'ip.rate_limit_toggled',
            'subject_type' => $ip2->getMorphClass(),
            'subject_id' => $ip2->id,
            'process' => 'admin',
        ]);
        $this->assertCount(2, AuditLog::where('action', 'ip.rate_limit_toggled')->get());
    }

    public function test_user_internet_toggle_creates_user_audit_log(): void
    {
        Queue::fake();
        $admin = $this->createAdminUser();
        $user = User::factory()->create();

        $ip = IpAddress::factory()->create(['internet_enabled' => false]);
        $user->addIp($ip->address);

        $this->actingAs($admin)->post(route('admin.users.internet', $user), ['enable' => 1]);

        $this->assertTrue(AuditLog::where('action', 'user.internet_toggled')->exists());
        $log = AuditLog::where('action', 'user.internet_toggled')->first();
        $this->assertNotNull($log);
        $this->assertEquals('admin', $log->process);
        $this->assertEquals($user->getMorphClass(), $log->subject_type);
        $this->assertEquals($user->id, $log->subject_id);
        $this->assertTrue($log->metadata['enabled']);
        $this->assertArrayHasKey('ip', $log->metadata);
    }

    public function test_user_role_change_creates_audit_log(): void
    {
        Queue::fake();
        $admin = $this->createAdminUser();
        $user = User::factory()->create();

        $moderator = new Role;
        $moderator->code = 'moderator';
        $moderator->name = 'Moderator';
        $moderator->save();

        $this->actingAs($admin)->put(route('admin.users.update', $user), [
            'nickname' => $user->nickname,
            'email' => $user->email,
            'roles' => ['admin', 'moderator'],
        ]);

        $this->assertTrue(AuditLog::where('action', 'user.role_changed')->exists());
        $log = AuditLog::where('action', 'user.role_changed')->first();
        $this->assertNotNull($log);
        $this->assertEquals('admin', $log->process);
        $this->assertEquals($user->id, $log->subject_id);
        $this->assertArrayHasKey('roles', $log->metadata);
        $this->assertContains('admin', $log->metadata['roles']);
        $this->assertContains('moderator', $log->metadata['roles']);
    }
}
