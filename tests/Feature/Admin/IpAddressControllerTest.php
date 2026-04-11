<?php

namespace Tests\Feature\Admin;

use App\Models\IpAddress;
use App\Models\Role;
use App\Models\User;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Queue;
use Tests\TestCase;

class IpAddressControllerTest extends TestCase
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

    public function test_admin_can_view_ip_index(): void
    {
        Queue::fake();
        $admin = $this->createAdminUser();

        $ip = new IpAddress;
        $ip->address = '10.0.0.1';
        $ip->last_seen_at = Carbon::now();
        $ip->save();

        $response = $this->actingAs($admin)->get('/admin/ips');

        $response->assertOk();
        $response->assertInertia(fn ($page) => $page
            ->component('Admin/Ips/Index')
            ->has('ips')
        );
    }

    public function test_admin_can_view_ip_detail(): void
    {
        Queue::fake();
        $admin = $this->createAdminUser();

        $ip = new IpAddress;
        $ip->address = '10.0.0.2';
        $ip->last_seen_at = Carbon::now();
        $ip->save();

        $response = $this->actingAs($admin)->get('/admin/ips/'.$ip->id);

        $response->assertOk();
        $response->assertInertia(fn ($page) => $page
            ->component('Admin/Ips/Show')
            ->has('ip')
        );
    }

    public function test_admin_can_create_ip(): void
    {
        Queue::fake();
        $admin = $this->createAdminUser();

        $response = $this->actingAs($admin)->get('/admin/ips/create');

        $response->assertOk();
        $response->assertInertia(fn ($page) => $page->component('Admin/Ips/Create'));
    }

    public function test_non_admin_cannot_view_ips(): void
    {
        Queue::fake();
        $user = User::factory()->create();

        $response = $this->actingAs($user)->get('/admin/ips');

        $response->assertForbidden();
    }

    public function test_admin_can_filter_ips_by_address(): void
    {
        Queue::fake();
        $admin = $this->createAdminUser();

        $ip1 = new IpAddress;
        $ip1->address = '10.0.0.1';
        $ip1->last_seen_at = Carbon::now();
        $ip1->save();

        $ip2 = new IpAddress;
        $ip2->address = '192.168.1.1';
        $ip2->last_seen_at = Carbon::now();
        $ip2->save();

        $response = $this->actingAs($admin)->get('/admin/ips?address=10.0.0.1');

        $response->assertOk();
        $response->assertInertia(fn ($page) => $page->has('ips.data', 1));
    }
}
