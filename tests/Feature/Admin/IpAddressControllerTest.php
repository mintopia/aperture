<?php

namespace Tests\Feature\Admin;

use App\Http\Controllers\Admin\IpAddressController;
use App\Models\IpAddress;
use App\Models\Role;
use App\Models\User;
use App\Services\CiscoService;
use App\Services\Interfaces\NetworkInventoryInterface;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Queue;
use Mockery;
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

    public function test_admin_can_filter_ips_by_nickname(): void
    {
        Queue::fake();
        $admin = $this->createAdminUser();

        $ip = new IpAddress;
        $ip->address = '10.0.0.5';
        $ip->last_seen_at = Carbon::now();
        $ip->save();

        $user = User::factory()->create(['nickname' => 'TargetUser']);
        $user->addIp('10.0.0.5');

        $response = $this->actingAs($admin)->get('/admin/ips?nickname=TargetUser');

        $response->assertOk();
    }

    public function test_admin_can_sort_ips_by_received(): void
    {
        Queue::fake();
        $admin = $this->createAdminUser();

        $ip = new IpAddress;
        $ip->address = '10.0.0.10';
        $ip->last_seen_at = Carbon::now();
        $ip->received = 1000;
        $ip->save();

        $response = $this->actingAs($admin)->get('/admin/ips?order=received');
        $response->assertOk();
    }

    public function test_admin_can_sort_ips_with_custom_direction(): void
    {
        Queue::fake();
        $admin = $this->createAdminUser();

        $ip = new IpAddress;
        $ip->address = '10.0.0.11';
        $ip->last_seen_at = Carbon::now();
        $ip->save();

        $response = $this->actingAs($admin)->get('/admin/ips?order=address&direction=desc');
        $response->assertOk();
    }

    public function test_admin_can_view_ip_show_without_port(): void
    {
        Queue::fake();
        $admin = $this->createAdminUser();

        $ip = new IpAddress;
        $ip->address = '10.0.0.20';
        $ip->last_seen_at = Carbon::now();
        $ip->save();

        $response = $this->actingAs($admin)->get('/admin/ips/'.$ip->id);
        $response->assertOk();
        $response->assertInertia(fn ($page) => $page
            ->component('Admin/Ips/Show')
            ->where('port', null)
            ->where('status', null)
        );
    }

    public function test_admin_can_shutdown_port(): void
    {
        Queue::fake();
        $admin = $this->createAdminUser();

        $ip = new IpAddress;
        $ip->address = '10.0.0.30';
        $ip->last_seen_at = Carbon::now();
        $ip->save();

        $response = $this->actingAs($admin)->post('/admin/ips/'.$ip->id.'/port', [
            'shutdown' => 1,
        ]);
        $response->assertRedirect();
    }

    public function test_admin_can_enable_port(): void
    {
        Queue::fake();
        $admin = $this->createAdminUser();

        $ip = new IpAddress;
        $ip->address = '10.0.0.31';
        $ip->last_seen_at = Carbon::now();
        $ip->save();

        $response = $this->actingAs($admin)->post('/admin/ips/'.$ip->id.'/port', [
            'shutdown' => 0,
        ]);
        $response->assertRedirect();
    }

    public function test_admin_can_limit_ip(): void
    {
        Queue::fake();
        $admin = $this->createAdminUser();

        $ip = new IpAddress;
        $ip->address = '10.0.0.32';
        $ip->last_seen_at = Carbon::now();
        $ip->save();

        $response = $this->actingAs($admin)->post('/admin/ips/'.$ip->id.'/limit', [
            'limit' => 1,
        ]);
        $response->assertRedirect();
    }

    public function test_admin_can_unlimit_ip(): void
    {
        Queue::fake();
        $admin = $this->createAdminUser();

        $ip = new IpAddress;
        $ip->address = '10.0.0.33';
        $ip->last_seen_at = Carbon::now();
        $ip->save();

        $response = $this->actingAs($admin)->post('/admin/ips/'.$ip->id.'/limit', [
            'limit' => 0,
        ]);
        $response->assertRedirect();
    }

    public function test_admin_can_allow_internet(): void
    {
        Queue::fake();
        $admin = $this->createAdminUser();

        $ip = new IpAddress;
        $ip->address = '10.0.0.34';
        $ip->last_seen_at = Carbon::now();
        $ip->save();

        $response = $this->actingAs($admin)->post('/admin/ips/'.$ip->id.'/internet', [
            'allow' => 1,
        ]);
        $response->assertRedirect();
    }

    public function test_admin_can_deny_internet(): void
    {
        Queue::fake();
        $admin = $this->createAdminUser();

        $ip = new IpAddress;
        $ip->address = '10.0.0.35';
        $ip->last_seen_at = Carbon::now();
        $ip->save();

        $response = $this->actingAs($admin)->post('/admin/ips/'.$ip->id.'/internet', [
            'allow' => 0,
        ]);
        $response->assertRedirect();
    }

    public function test_admin_can_store_new_ip(): void
    {
        Queue::fake();
        $admin = $this->createAdminUser();

        $response = $this->actingAs($admin)->post('/admin/ips', [
            'address' => '10.0.0.100',
            'comment' => 'Test IP',
            'allow' => false,
            'limit' => false,
        ]);
        $response->assertRedirect();
        $this->assertDatabaseHas('ip_addresses', ['address' => '10.0.0.100']);
    }

    public function test_admin_can_store_new_ip_with_allow_and_limit(): void
    {
        Queue::fake();
        $admin = $this->createAdminUser();

        $response = $this->actingAs($admin)->post('/admin/ips', [
            'address' => '10.0.0.101',
            'comment' => 'Test IP Allowed',
            'allow' => true,
            'limit' => true,
        ]);
        $response->assertRedirect();
        $this->assertDatabaseHas('ip_addresses', ['address' => '10.0.0.101']);
    }

    public function test_admin_can_view_ip_show_with_port_data(): void
    {
        Queue::fake();
        $admin = $this->createAdminUser();

        $inventory = Mockery::mock(NetworkInventoryInterface::class);
        $inventory->shouldReceive('resolveIpToPort')
            ->andReturn(['ip' => '10.0.0.200', 'mac' => 'AA:BB:CC:DD:EE:FF', 'port' => '1', 'switch' => '']);
        $inventory->shouldReceive('getPortDetail')
            ->andReturn(['hostname' => 'switch01', 'interface' => 'Gi0/1', 'status' => 'up', 'adminStatus' => 'up', 'speed' => 1000]);
        $this->app->instance(NetworkInventoryInterface::class, $inventory);

        $ip = new IpAddress;
        $ip->address = '10.0.0.200';
        $ip->last_seen_at = Carbon::now();
        $ip->save();

        $response = $this->actingAs($admin)->get('/admin/ips/'.$ip->id);

        $response->assertOk();
        $response->assertInertia(fn ($page) => $page
            ->component('Admin/Ips/Show')
            ->has('ip')
            ->has('port')
            ->where('status', 'Unable to connect to switch')
            ->where('config', 'Unable to connect to switch')
        );
    }

    public function test_admin_can_view_ip_show_with_successful_cisco_connection(): void
    {
        Queue::fake();
        $admin = $this->createAdminUser();

        $inventory = Mockery::mock(NetworkInventoryInterface::class);
        $inventory->shouldReceive('resolveIpToPort')
            ->andReturn(['ip' => '10.0.0.201', 'mac' => 'AA:BB:CC:DD:EE:01', 'port' => '1', 'switch' => '']);
        $inventory->shouldReceive('getPortDetail')
            ->andReturn(['hostname' => 'switch01', 'interface' => 'Gi0/1', 'status' => 'up', 'adminStatus' => 'up', 'speed' => 1000]);
        $this->app->instance(NetworkInventoryInterface::class, $inventory);

        $ip = new IpAddress;
        $ip->address = '10.0.0.201';
        $ip->last_seen_at = Carbon::now();
        $ip->save();

        $ciscoMock = Mockery::mock(CiscoService::class);
        $ciscoMock->shouldReceive('showInterface')->with('Gi0/1')->andReturn('Gi0/1 is up');
        $ciscoMock->shouldReceive('showInterfaceConfig')->with('Gi0/1')->andReturn("interface Gi0/1\n shutdown\nend");

        $controllerMock = Mockery::mock(IpAddressController::class)->makePartial()->shouldAllowMockingProtectedMethods();
        $controllerMock->shouldReceive('createCiscoService')->with('switch01')->andReturn($ciscoMock);
        $this->app->instance(IpAddressController::class, $controllerMock);

        $response = $this->actingAs($admin)->get('/admin/ips/'.$ip->id);

        $response->assertOk();
        $response->assertInertia(fn ($page) => $page
            ->component('Admin/Ips/Show')
            ->has('ip')
            ->has('port')
            ->where('status', 'Gi0/1 is up')
            ->where('shutdown', true)
        );
    }
}
