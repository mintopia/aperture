<?php

namespace Tests\Feature\Admin;

use App\Models\IpAddress;
use App\Models\Role;
use App\Models\SwitchConfig;
use App\Models\User;
use App\Services\Interfaces\NetworkInventoryInterface;
use App\Services\Interfaces\NetworkSwitchInterface;
use App\Services\NetworkSwitch\SwitchServiceFactory;
use App\Services\ValueObjects\PortDetail;
use App\Services\ValueObjects\PortStatus;
use App\Services\ValueObjects\ResolvedPort;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Queue;
use Mockery;
use RuntimeException;
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

        $response = $this->actingAs($admin)->get('/admin/ips/'.$ip->address);

        $response->assertOk();
        $response->assertInertia(fn ($page) => $page
            ->component('Admin/Ips/Show')
            ->has('ip')
        );
    }

    public function test_ip_show_route_uses_ip_address_parameter(): void
    {
        $ip = IpAddress::factory()->create(['address' => '10.0.0.222']);

        $this->assertSame('/admin/ips/10.0.0.222', route('admin.ips.show', ['ip' => $ip], false));
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

        $response = $this->actingAs($admin)->get('/admin/ips/'.$ip->address);
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

        $response = $this->actingAs($admin)->post('/admin/ips/'.$ip->address.'/port', [
            'shutdown' => 1,
        ]);
        $response->assertRedirect(route('admin.ips.show', ['ip' => $ip], false));
    }

    public function test_admin_can_enable_port(): void
    {
        Queue::fake();
        $admin = $this->createAdminUser();

        $ip = new IpAddress;
        $ip->address = '10.0.0.31';
        $ip->last_seen_at = Carbon::now();
        $ip->save();

        $response = $this->actingAs($admin)->post('/admin/ips/'.$ip->address.'/port', [
            'shutdown' => 0,
        ]);
        $response->assertRedirect(route('admin.ips.show', ['ip' => $ip], false));
    }

    public function test_admin_can_limit_ip(): void
    {
        Queue::fake();
        $admin = $this->createAdminUser();

        $ip = new IpAddress;
        $ip->address = '10.0.0.32';
        $ip->last_seen_at = Carbon::now();
        $ip->save();

        $response = $this->actingAs($admin)->post('/admin/ips/'.$ip->address.'/limit', [
            'limit' => 1,
        ]);
        $response->assertRedirect(route('admin.ips.show', ['ip' => $ip], false));
    }

    public function test_admin_can_unlimit_ip(): void
    {
        Queue::fake();
        $admin = $this->createAdminUser();

        $ip = new IpAddress;
        $ip->address = '10.0.0.33';
        $ip->last_seen_at = Carbon::now();
        $ip->save();

        $response = $this->actingAs($admin)->post('/admin/ips/'.$ip->address.'/limit', [
            'limit' => 0,
        ]);
        $response->assertRedirect(route('admin.ips.show', ['ip' => $ip], false));
    }

    public function test_admin_can_allow_internet(): void
    {
        Queue::fake();
        $admin = $this->createAdminUser();

        $ip = new IpAddress;
        $ip->address = '10.0.0.34';
        $ip->last_seen_at = Carbon::now();
        $ip->save();

        $response = $this->actingAs($admin)->post('/admin/ips/'.$ip->address.'/internet', [
            'allow' => 1,
        ]);
        $response->assertRedirect(route('admin.ips.show', ['ip' => $ip], false));
    }

    public function test_admin_can_deny_internet(): void
    {
        Queue::fake();
        $admin = $this->createAdminUser();

        $ip = new IpAddress;
        $ip->address = '10.0.0.35';
        $ip->last_seen_at = Carbon::now();
        $ip->save();

        $response = $this->actingAs($admin)->post('/admin/ips/'.$ip->address.'/internet', [
            'allow' => 0,
        ]);
        $response->assertRedirect(route('admin.ips.show', ['ip' => $ip], false));
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
        $response->assertRedirect('/admin/ips/10.0.0.100');
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
        $response->assertRedirect('/admin/ips/10.0.0.101');
        $this->assertDatabaseHas('ip_addresses', ['address' => '10.0.0.101']);
    }

    public function test_admin_can_view_ip_show_with_port_data(): void
    {
        Queue::fake();
        $admin = $this->createAdminUser();

        $inventory = Mockery::mock(NetworkInventoryInterface::class);
        $inventory->shouldReceive('resolveIpToPort')
            ->andReturn(new ResolvedPort(ip: '10.0.0.200', mac: 'AA:BB:CC:DD:EE:FF', port: '1', switch: ''));
        $inventory->shouldReceive('getPortDetail')
            ->andReturn(new PortDetail(hostname: 'switch01', interface: 'Gi0/1', status: 'up', adminStatus: 'down', speed: 1000));
        $this->app->instance(NetworkInventoryInterface::class, $inventory);

        $ip = new IpAddress;
        $ip->address = '10.0.0.200';
        $ip->last_seen_at = Carbon::now();
        $ip->save();

        $switchConfig = SwitchConfig::factory()->create(['hostname' => 'switch01']);
        $factory = Mockery::mock(SwitchServiceFactory::class);
        $factory->shouldReceive('make')
            ->once()
            ->with(Mockery::on(fn (SwitchConfig $config): bool => $config->is($switchConfig)))
            ->andThrow(new RuntimeException('Switch offline'));
        $this->app->instance(SwitchServiceFactory::class, $factory);

        $response = $this->actingAs($admin)->get('/admin/ips/'.$ip->address);

        $response->assertOk();
        $response->assertInertia(fn ($page) => $page
            ->component('Admin/Ips/Show')
            ->has('ip')
            ->has('port')
            ->where('status', 'Unable to connect to switch')
            ->where('shutdown', true)
            ->where('config', null)
        );
    }

    public function test_admin_can_view_ip_show_with_successful_switch_connection(): void
    {
        Queue::fake();
        $admin = $this->createAdminUser();

        $inventory = Mockery::mock(NetworkInventoryInterface::class);
        $inventory->shouldReceive('resolveIpToPort')
            ->andReturn(new ResolvedPort(ip: '10.0.0.201', mac: 'AA:BB:CC:DD:EE:01', port: '1', switch: ''));
        $inventory->shouldReceive('getPortDetail')
            ->andReturn(new PortDetail(hostname: 'switch01', interface: 'Gi0/1', status: 'up', adminStatus: 'down', speed: 1000));
        $this->app->instance(NetworkInventoryInterface::class, $inventory);

        $ip = new IpAddress;
        $ip->address = '10.0.0.201';
        $ip->last_seen_at = Carbon::now();
        $ip->save();

        $switch = Mockery::mock(NetworkSwitchInterface::class);
        $switch->shouldReceive('getPortStatus')
            ->once()
            ->with('Gi0/1')
            ->andReturn(new PortStatus(
                interface: 'Gi0/1',
                status: 'up',
                speed: '1000Mb/s',
                duplex: 'full',
            ));

        $switchConfig = SwitchConfig::factory()->create(['hostname' => 'switch01']);
        $factory = Mockery::mock(SwitchServiceFactory::class);
        $factory->shouldReceive('make')
            ->once()
            ->with(Mockery::on(fn (SwitchConfig $config): bool => $config->is($switchConfig)))
            ->andReturn($switch);
        $this->app->instance(SwitchServiceFactory::class, $factory);

        $response = $this->actingAs($admin)->get('/admin/ips/'.$ip->address);

        $response->assertOk();
        $response->assertInertia(fn ($page) => $page
            ->component('Admin/Ips/Show')
            ->has('ip')
            ->has('port')
            ->where('status', 'Gi0/1 is up')
            ->where('shutdown', true)
        );
    }

    public function test_admin_can_view_ip_show_with_fallback_switch_config_when_hostname_not_in_db(): void
    {
        // Covers IpAddressController::resolveSwitchConfig() lines 196-205:
        // when no SwitchConfig record matches the hostname, a new SwitchConfig is built
        // from the aperture.cisco.* config values and returned as fallback.
        Queue::fake();
        $admin = $this->createAdminUser();

        config([
            'aperture.cisco.username' => 'fallback-user',
            'aperture.cisco.password' => 'fallback-pass',
            'aperture.cisco.enablePassword' => 'fallback-enable',
            'aperture.cisco.timeout' => 10,
        ]);

        $inventory = Mockery::mock(NetworkInventoryInterface::class);
        $inventory->shouldReceive('resolveIpToPort')
            ->andReturn(new ResolvedPort(ip: '10.0.0.99', mac: 'BB:CC:DD:EE:FF:00', port: '1', switch: ''));
        $inventory->shouldReceive('getPortDetail')
            ->andReturn(new PortDetail(
                hostname: 'unknown-switch.local',  // No SwitchConfig for this hostname
                interface: 'Gi0/1',
                status: 'up',
                adminStatus: 'up',
                speed: 1000
            ));
        $this->app->instance(NetworkInventoryInterface::class, $inventory);

        $ip = new IpAddress;
        $ip->address = '10.0.0.99';
        $ip->last_seen_at = Carbon::now();
        $ip->save();

        // No SwitchConfig created for 'unknown-switch.local'

        $factory = Mockery::mock(SwitchServiceFactory::class);
        $factory->shouldReceive('make')
            ->once()
            ->with(Mockery::on(function (SwitchConfig $config): bool {
                // The fallback SwitchConfig should use the config values
                return $config->hostname === 'unknown-switch.local'
                    && $config->username === 'fallback-user';
            }))
            ->andThrow(new RuntimeException('Switch offline'));
        $this->app->instance(SwitchServiceFactory::class, $factory);

        $response = $this->actingAs($admin)->get('/admin/ips/'.$ip->address);

        $response->assertOk();
        $response->assertInertia(fn ($page) => $page
            ->component('Admin/Ips/Show')
            ->has('ip')
            ->has('port')
            ->where('status', 'Unable to connect to switch')
        );
    }
}
