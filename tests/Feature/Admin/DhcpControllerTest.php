<?php

declare(strict_types=1);

namespace Tests\Feature\Admin;

use App\Models\Role;
use App\Models\User;
use App\Services\Interfaces\DhcpInterface;
use App\Services\ValueObjects\DhcpLease;
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
        $mock->shouldReceive('getRanges')->once()->andReturn(collect([]));
        $this->app->instance(DhcpInterface::class, $mock);

        $admin = $this->createAdminUser();
        $response = $this->actingAs($admin)->get('/admin/dhcp');

        $response->assertOk();
        $response->assertInertia(fn ($page) => $page->component('Admin/Dhcp/Index')->has('ranges'));
    }

    public function test_admin_can_view_dhcp_leases(): void
    {
        $mock = Mockery::mock(DhcpInterface::class);
        $mock->shouldReceive('getLeases')->once()->andReturn(collect([]));
        $mock->shouldReceive('getRanges')->once()->andReturn(collect([]));
        $this->app->instance(DhcpInterface::class, $mock);

        $admin = $this->createAdminUser();
        $response = $this->actingAs($admin)->get('/admin/dhcp/leases');

        $response->assertOk();
        $response->assertInertia(fn ($page) => $page->component('Admin/Dhcp/Leases')->has('leases')->has('ranges'));
    }

    public function test_leases_are_serialized_as_arrays(): void
    {
        $lease = new DhcpLease(
            ip: '10.0.0.50',
            mac: 'aa:bb:cc:dd:ee:ff',
            hostname: 'test-host',
            expires: '2026-01-01 12:00:00'
        );

        $mock = Mockery::mock(DhcpInterface::class);
        $mock->shouldReceive('getLeases')->once()->andReturn(collect([$lease]));
        $mock->shouldReceive('getRanges')->once()->andReturn(collect([]));
        $this->app->instance(DhcpInterface::class, $mock);

        $admin = $this->createAdminUser();
        $response = $this->actingAs($admin)->get('/admin/dhcp/leases');

        $response->assertOk();
        $response->assertInertia(fn ($page) => $page
            ->component('Admin/Dhcp/Leases')
            ->has('leases', 1)
            ->where('leases.0.ip', '10.0.0.50')
            ->where('leases.0.mac', 'aa:bb:cc:dd:ee:ff')
            ->where('leases.0.hostname', 'test-host')
            ->where('leases.0.expires', '2026-01-01 12:00:00')
        );
    }

    public function test_unauthenticated_cannot_access_dhcp(): void
    {
        $response = $this->get('/admin/dhcp');
        $response->assertRedirect('/captive');
    }
}
