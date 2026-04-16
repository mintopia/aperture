<?php

namespace Tests\Feature\Admin;

use App\Models\IpAddress;
use App\Models\Role;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Queue;
use Tests\TestCase;

class UserControllerTest extends TestCase
{
    use RefreshDatabase;

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
            ->has('ips')
            ->has('roles')
        );
    }

    public function test_admin_can_block_user(): void
    {
        Queue::fake();
        $admin = $this->createAdminUser();
        $user = User::factory()->create(['blocked' => false]);

        $response = $this->actingAs($admin)->post(sprintf('/admin/users/%d/block', $user->id), [
            'block' => true,
        ]);

        $response->assertRedirect();
        $this->assertTrue((bool) $user->fresh()->blocked);
    }

    public function test_admin_can_unblock_user(): void
    {
        Queue::fake();
        $admin = $this->createAdminUser();
        $user = User::factory()->create(['blocked' => true]);

        $response = $this->actingAs($admin)->post(sprintf('/admin/users/%d/block', $user->id), [
            'block' => false,
        ]);

        $response->assertRedirect();
        $this->assertFalse((bool) $user->fresh()->blocked);
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
}
