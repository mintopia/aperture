<?php

declare(strict_types=1);

namespace Tests\Feature\Admin;

use App\Models\Role;
use App\Models\User;
use App\Services\Interfaces\NetworkSwitchInterface;
use App\Services\ValueObjects\PortStatistics;
use App\Services\ValueObjects\PortStatus;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Mockery;
use Tests\TestCase;

class PortControllerTest extends TestCase
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

    public function test_admin_can_view_port_index(): void
    {
        $mock = Mockery::mock(NetworkSwitchInterface::class);
        $mock->shouldReceive('getAllPorts')->once()->andReturn(collect([]));
        $this->app->instance(NetworkSwitchInterface::class, $mock);

        $admin = $this->createAdminUser();
        $response = $this->actingAs($admin)->get('/admin/ports');

        $response->assertOk();
        $response->assertInertia(fn ($page) => $page->component('Admin/Ports/Index')->has('ports'));
    }

    public function test_admin_can_view_port_show(): void
    {
        $mock = Mockery::mock(NetworkSwitchInterface::class);
        $mock->shouldReceive('getPortStatus')->with('eth0')->once()->andReturn(new PortStatus(interface: 'eth0', status: 'up', speed: ''));
        $mock->shouldReceive('getPortStatistics')->with('eth0')->once()->andReturn(new PortStatistics(inBytes: 0, outBytes: 0, inErrors: 0, outErrors: 0));
        $this->app->instance(NetworkSwitchInterface::class, $mock);

        $admin = $this->createAdminUser();
        $response = $this->actingAs($admin)->get('/admin/ports/eth0');

        $response->assertOk();
        $response->assertInertia(fn ($page) => $page
            ->component('Admin/Ports/Show')
            ->has('port')
            ->has('statistics')
            ->where('portId', 'eth0')
        );
    }

    public function test_admin_can_shutdown_port(): void
    {
        $mock = Mockery::mock(NetworkSwitchInterface::class);
        $mock->shouldReceive('shutdownPort')->with('eth0')->once()->andReturn(true);
        $this->app->instance(NetworkSwitchInterface::class, $mock);

        $admin = $this->createAdminUser();
        $response = $this->actingAs($admin)->post('/admin/ports/eth0/shutdown');

        $response->assertRedirect();
    }

    public function test_admin_can_enable_port(): void
    {
        $mock = Mockery::mock(NetworkSwitchInterface::class);
        $mock->shouldReceive('enablePort')->with('eth0')->once()->andReturn(true);
        $this->app->instance(NetworkSwitchInterface::class, $mock);

        $admin = $this->createAdminUser();
        $response = $this->actingAs($admin)->post('/admin/ports/eth0/enable');

        $response->assertRedirect();
    }
}
