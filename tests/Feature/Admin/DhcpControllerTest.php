<?php

declare(strict_types=1);

namespace Tests\Feature\Admin;

use App\Models\Role;
use App\Models\User;
use App\Services\Interfaces\DhcpInterface;
use App\Services\ValueObjects\DhcpPoolStatus;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Mockery;
use Tests\TestCase;

class DhcpControllerTest extends TestCase
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

    public function test_admin_can_view_dhcp_index(): void
    {
        $mock = Mockery::mock(DhcpInterface::class);
        $mock->shouldReceive('getPoolStatus')->once()->andReturn(new DhcpPoolStatus(total: 254, used: 100, available: 154, utilisation: 100 / 254));
        $this->app->instance(DhcpInterface::class, $mock);

        $admin = $this->createAdminUser();
        $response = $this->actingAs($admin)->get('/admin/dhcp');

        $response->assertOk();
        $response->assertInertia(fn ($page) => $page->component('Admin/Dhcp/Index')->has('pool'));
    }

    public function test_admin_can_view_dhcp_leases(): void
    {
        $mock = Mockery::mock(DhcpInterface::class);
        $mock->shouldReceive('getLeases')->once()->andReturn(collect([]));
        $this->app->instance(DhcpInterface::class, $mock);

        $admin = $this->createAdminUser();
        $response = $this->actingAs($admin)->get('/admin/dhcp/leases');

        $response->assertOk();
        $response->assertInertia(fn ($page) => $page->component('Admin/Dhcp/Leases')->has('leases'));
    }

    public function test_unauthenticated_cannot_access_dhcp(): void
    {
        $response = $this->get('/admin/dhcp');
        $response->assertRedirect('/captive');
    }
}
