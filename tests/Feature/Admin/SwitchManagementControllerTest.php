<?php

declare(strict_types=1);

namespace Tests\Feature\Admin;

use App\Jobs\SyncSwitchPortsJob;
use App\Models\Role;
use App\Models\SwitchConfig;
use App\Models\SwitchPort;
use App\Models\SwitchSyncRun;
use App\Models\User;
use App\Services\Interfaces\NetworkSwitchInterface;
use App\Services\Interfaces\SshProxyClientInterface;
use App\Services\NetworkSwitch\CircuitBreaker;
use App\Services\NetworkSwitch\SwitchServiceFactory;
use App\Services\SshProxy\CommandOutput;
use App\Services\SshProxy\CommandResult;
use Closure;
use Illuminate\Foundation\Testing\LazilyRefreshDatabase;
use Illuminate\Support\Facades\Queue;
use Mockery;
use PHPUnit\Framework\Attributes\DataProvider;
use RuntimeException;
use Tests\TestCase;

class SwitchManagementControllerTest extends TestCase
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

    #[DataProvider('switchRoutesProvider')]
    public function test_unauthenticated_user_is_redirected_from_switches_route(string $method, string $pathTemplate): void
    {
        $switch = SwitchConfig::factory()->create();
        $path = str_replace('{id}', (string) $switch->id, $pathTemplate);

        $response = $this->{$method}($path, []);
        $response->assertRedirect('/captive');
    }

    public static function switchRoutesProvider(): array
    {
        return [
            'index' => ['get', '/admin/switches'],
            'store' => ['post', '/admin/switches'],
            'show' => ['get', '/admin/switches/{id}'],
        ];
    }

    #[DataProvider('nonAdminSwitchRoutesProvider')]
    public function test_non_admin_cannot_access_switch_route(string $method, string $pathTemplate): void
    {
        $user = User::factory()->create();
        $switch = SwitchConfig::factory()->create();
        $path = str_replace('{id}', (string) $switch->id, $pathTemplate);

        $this->actingAs($user)->{$method}($path, [])->assertForbidden();
    }

    public static function nonAdminSwitchRoutesProvider(): array
    {
        return [
            'index' => ['get', '/admin/switches'],
            'store' => ['post', '/admin/switches'],
            'update' => ['put', '/admin/switches/{id}'],
            'delete' => ['delete', '/admin/switches/{id}'],
            'sync' => ['post', '/admin/switches/{id}/sync'],
            'test connection' => ['post', '/admin/switches/{id}/test'],
            'view config' => ['get', '/admin/switches/{id}/config'],
        ];
    }

    #[DataProvider('nonexistentSwitchRoutesProvider')]
    public function test_route_returns_404_for_nonexistent_switch(string $method, string $path): void
    {
        $admin = $this->createAdminUser();

        $response = $this->actingAs($admin)->{$method}($path, []);

        $response->assertNotFound();
    }

    public static function nonexistentSwitchRoutesProvider(): array
    {
        return [
            'show' => ['get', '/admin/switches/99999'],
            'edit' => ['get', '/admin/switches/99999/edit'],
            'update' => ['put', '/admin/switches/99999'],
            'delete' => ['delete', '/admin/switches/99999'],
            'sync' => ['post', '/admin/switches/99999/sync'],
            'test connection' => ['post', '/admin/switches/99999/test'],
            'config' => ['get', '/admin/switches/99999/config'],
        ];
    }

    public function test_admin_can_view_switches_index_with_no_switches(): void
    {
        $admin = $this->createAdminUser();

        $response = $this->actingAs($admin)->get('/admin/switches');

        $response->assertOk();
        $response->assertInertia(fn ($page) => $page
            ->component('Admin/Switches/Index')
            ->has('switches', 0)
        );
    }

    public function test_admin_can_view_switches_index_with_one_switch(): void
    {
        $admin = $this->createAdminUser();
        SwitchConfig::factory()->create([
            'name' => 'Core Switch',
            'hostname' => 'core-sw.local',
        ]);

        $response = $this->actingAs($admin)->get('/admin/switches');

        $response->assertOk();
        $response->assertInertia(fn ($page) => $page
            ->component('Admin/Switches/Index')
            ->has('switches', 1)
            ->where('switches.0.name', 'Core Switch')
            ->where('switches.0.hostname', 'core-sw.local')
        );
    }

    public function test_admin_can_view_switches_index_with_multiple_switches(): void
    {
        $admin = $this->createAdminUser();
        SwitchConfig::factory()->count(5)->create();

        $response = $this->actingAs($admin)->get('/admin/switches');

        $response->assertOk();
        $response->assertInertia(fn ($page) => $page
            ->component('Admin/Switches/Index')
            ->has('switches', 5)
        );
    }

    public function test_switches_index_does_not_expose_password_fields(): void
    {
        $admin = $this->createAdminUser();
        SwitchConfig::factory()->create([
            'password' => 'super-secret',
            'enable_password' => 'enable-secret',
        ]);

        $response = $this->actingAs($admin)->get('/admin/switches');

        $response->assertOk();
        $response->assertInertia(fn ($page) => $page
            ->component('Admin/Switches/Index')
            ->has('switches', 1)
            ->missing('switches.0.password')
            ->missing('switches.0.enable_password')
        );
    }

    public function test_switches_index_includes_enabled_status(): void
    {
        $admin = $this->createAdminUser();
        SwitchConfig::factory()->create(['enabled' => true, 'name' => 'Enabled Switch']);
        SwitchConfig::factory()->disabled()->create(['name' => 'Disabled Switch']);

        $response = $this->actingAs($admin)->get('/admin/switches');

        $response->assertOk();
        $response->assertInertia(fn ($page) => $page
            ->component('Admin/Switches/Index')
            ->has('switches', 2)
        );
    }

    public function test_switches_index_includes_port_and_sync_aggregates(): void
    {
        $admin = $this->createAdminUser();
        $switch = SwitchConfig::factory()->create(['name' => 'Aggregate Switch']);

        SwitchPort::factory()->count(3)->create([
            'switch_config_id' => $switch->id,
            'status' => 'up',
        ]);
        SwitchPort::factory()->count(2)->create([
            'switch_config_id' => $switch->id,
            'status' => 'connected',
        ]);
        SwitchPort::factory()->create([
            'switch_config_id' => $switch->id,
            'status' => 'down',
        ]);
        SwitchPort::factory()->create([
            'switch_config_id' => $switch->id,
            'status' => 'notconnect',
        ]);
        SwitchPort::factory()->create([
            'switch_config_id' => $switch->id,
            'status' => 'err-disabled',
        ]);

        SwitchSyncRun::factory()->completed()->create([
            'switch_config_id' => $switch->id,
        ]);

        $response = $this->actingAs($admin)->get('/admin/switches');

        $response->assertOk();
        $response->assertInertia(fn ($page) => $page
            ->component('Admin/Switches/Index')
            ->has('switches', 1)
            ->where('switches.0.port_count', 8)
            ->where('switches.0.ports_up', 5)
            ->where('switches.0.ports_down', 2)
            ->where('switches.0.ports_error', 1)
            ->has('switches.0.last_synced_at')
            ->where('switches.0.latest_sync_status', 'completed')
        );
    }

    public function test_admin_can_view_switch_create_form(): void
    {
        $admin = $this->createAdminUser();

        $response = $this->actingAs($admin)->get('/admin/switches/create');

        $response->assertOk();
        $response->assertInertia(fn ($page) => $page
            ->component('Admin/Switches/Create')
        );
    }

    public function test_admin_can_store_switch_with_valid_data(): void
    {
        $admin = $this->createAdminUser();

        $response = $this->actingAs($admin)->post('/admin/switches', [
            'name' => 'Core Switch',
            'hostname' => 'core-sw.local',
            'type' => 'cisco',
            'username' => 'admin',
            'password' => 'secret123',
            'enable_password' => 'enable123',
            'port' => 22,
            'timeout' => 5,
        ]);

        $response->assertRedirect();
        $response->assertSessionHas('success');

        $this->assertDatabaseHas('switch_configs', [
            'name' => 'Core Switch',
            'hostname' => 'core-sw.local',
            'type' => 'cisco',
            'username' => 'admin',
            'port' => 22,
            'timeout' => 5,
        ]);
    }

    public function test_store_redirects_to_show_page_after_creation(): void
    {
        $admin = $this->createAdminUser();

        $response = $this->actingAs($admin)->post('/admin/switches', [
            'name' => 'New Switch',
            'hostname' => 'new-sw.local',
            'type' => 'cisco',
            'username' => 'admin',
            'password' => 'secret',
            'port' => 22,
            'timeout' => 5,
        ]);

        $switch = SwitchConfig::where('hostname', 'new-sw.local')->first();
        $this->assertNotNull($switch);
        $response->assertRedirect('/admin/switches/'.$switch->id);
    }

    public function test_store_validates_name_is_required(): void
    {
        $admin = $this->createAdminUser();

        $response = $this->actingAs($admin)->post('/admin/switches', [
            'hostname' => 'core-sw.local',
            'type' => 'cisco',
            'username' => 'admin',
            'password' => 'secret',
        ]);

        $response->assertSessionHasErrors('name');
    }

    public function test_store_validates_hostname_is_required(): void
    {
        $admin = $this->createAdminUser();

        $response = $this->actingAs($admin)->post('/admin/switches', [
            'name' => 'Core Switch',
            'type' => 'cisco',
            'username' => 'admin',
            'password' => 'secret',
        ]);

        $response->assertSessionHasErrors('hostname');
    }

    public function test_store_validates_type_is_required(): void
    {
        $admin = $this->createAdminUser();

        $response = $this->actingAs($admin)->post('/admin/switches', [
            'name' => 'Core Switch',
            'hostname' => 'core-sw.local',
            'username' => 'admin',
            'password' => 'secret',
        ]);

        $response->assertSessionHasErrors('type');
    }

    public function test_store_validates_all_required_fields(): void
    {
        $admin = $this->createAdminUser();

        $response = $this->actingAs($admin)->post('/admin/switches', []);

        $response->assertSessionHasErrors(['name', 'hostname', 'type']);
    }

    public function test_store_validates_hostname_uniqueness(): void
    {
        $admin = $this->createAdminUser();
        SwitchConfig::factory()->create(['hostname' => 'existing-sw.local']);

        $response = $this->actingAs($admin)->post('/admin/switches', [
            'name' => 'New Switch',
            'hostname' => 'existing-sw.local',
            'type' => 'cisco',
            'username' => 'admin',
            'password' => 'secret',
        ]);

        $response->assertSessionHasErrors('hostname');
    }

    public function test_store_validates_port_range(): void
    {
        $admin = $this->createAdminUser();

        $response = $this->actingAs($admin)->post('/admin/switches', [
            'name' => 'Test Switch',
            'hostname' => 'test-sw.local',
            'type' => 'cisco',
            'username' => 'admin',
            'password' => 'secret',
            'port' => 99999,
        ]);

        $response->assertSessionHasErrors('port');
    }

    public function test_store_validates_timeout_max(): void
    {
        $admin = $this->createAdminUser();

        $response = $this->actingAs($admin)->post('/admin/switches', [
            'name' => 'Test Switch',
            'hostname' => 'test-sw.local',
            'type' => 'cisco',
            'username' => 'admin',
            'password' => 'secret',
            'timeout' => 999,
        ]);

        $response->assertSessionHasErrors('timeout');
    }

    public function test_store_sets_enabled_to_true_by_default(): void
    {
        $admin = $this->createAdminUser();

        $this->actingAs($admin)->post('/admin/switches', [
            'name' => 'Enabled Switch',
            'hostname' => 'enabled-sw.local',
            'type' => 'cisco',
            'username' => 'admin',
            'password' => 'secret',
            'port' => 22,
            'timeout' => 5,
        ]);

        $this->assertDatabaseHas('switch_configs', [
            'hostname' => 'enabled-sw.local',
            'enabled' => true,
        ]);
    }

    public function test_admin_can_view_switch_show_page(): void
    {
        $admin = $this->createAdminUser();
        $switch = SwitchConfig::factory()->create([
            'name' => 'Core Switch',
            'hostname' => 'core-sw.local',
            'type' => 'cisco',
        ]);

        $response = $this->actingAs($admin)->get('/admin/switches/'.$switch->id);

        $response->assertOk();
        $response->assertInertia(fn ($page) => $page
            ->component('Admin/Switches/Show')
            ->has('switchConfig')
            ->where('switchConfig.name', 'Core Switch')
            ->where('switchConfig.hostname', 'core-sw.local')
            ->where('switchConfig.type', 'cisco')
        );
    }

    public function test_show_includes_ports_data(): void
    {
        $admin = $this->createAdminUser();
        $switch = SwitchConfig::factory()->create();

        SwitchPort::factory()->count(2)->create([
            'switch_config_id' => $switch->id,
        ]);

        $response = $this->actingAs($admin)->get('/admin/switches/'.$switch->id);

        $response->assertOk();
        $response->assertInertia(fn ($page) => $page
            ->component('Admin/Switches/Show')
            ->has('ports', 2)
        );
    }

    public function test_show_maps_port_fields_correctly(): void
    {
        $admin = $this->createAdminUser();
        $switch = SwitchConfig::factory()->create();

        SwitchPort::factory()->create([
            'switch_config_id' => $switch->id,
            'port_name' => 'GigabitEthernet0/1',
            'switch_description' => 'Uplink to core',
            'status' => 'up',
            'admin_status' => 'up',
            'speed' => '1000',
            'access_vlan' => 100,
            'poe_status' => 'on',
            'duplex' => 'full',
            'switchport_mode' => 'access',
        ]);

        $response = $this->actingAs($admin)->get('/admin/switches/'.$switch->id);

        $response->assertOk();
        $response->assertInertia(fn ($page) => $page
            ->has('ports', 1)
            ->where('ports.0.interface', 'GigabitEthernet0/1')
            ->where('ports.0.description', 'Uplink to core')
            ->where('ports.0.status', 'up')
            ->where('ports.0.admin_status', 'up')
            ->where('ports.0.speed', '1000')
            ->where('ports.0.vlan', 100)
            ->where('ports.0.poe', 'on')
            ->where('ports.0.duplex', 'full')
            ->where('ports.0.switchport_mode', 'access')
        );
    }

    public function test_show_does_not_expose_password_fields(): void
    {
        $admin = $this->createAdminUser();
        $switch = SwitchConfig::factory()->create([
            'password' => 'super-secret',
            'enable_password' => 'enable-secret',
        ]);

        $response = $this->actingAs($admin)->get('/admin/switches/'.$switch->id);

        $response->assertOk();
        $response->assertInertia(fn ($page) => $page
            ->component('Admin/Switches/Show')
            ->missing('switchConfig.password')
            ->missing('switchConfig.enable_password')
        );
    }

    public function test_admin_can_view_switch_edit_page(): void
    {
        $admin = $this->createAdminUser();
        $switch = SwitchConfig::factory()->create([
            'name' => 'Core Switch',
            'hostname' => 'core-sw.local',
        ]);

        $response = $this->actingAs($admin)->get('/admin/switches/'.$switch->id.'/edit');

        $response->assertOk();
        $response->assertInertia(fn ($page) => $page
            ->component('Admin/Switches/Edit')
            ->has('switchConfig')
            ->where('switchConfig.name', 'Core Switch')
            ->where('switchConfig.hostname', 'core-sw.local')
        );
    }

    public function test_admin_can_update_switch(): void
    {
        $admin = $this->createAdminUser();
        $switch = SwitchConfig::factory()->create();

        $response = $this->actingAs($admin)->put('/admin/switches/'.$switch->id, [
            'name' => 'Updated Switch',
            'hostname' => 'updated-sw.local',
            'type' => 'cisco',
            'username' => 'newadmin',
            'password' => 'newpassword',
            'port' => 2222,
            'timeout' => 10,
        ]);

        $response->assertRedirect();
        $response->assertSessionHas('success');

        $this->assertDatabaseHas('switch_configs', [
            'id' => $switch->id,
            'name' => 'Updated Switch',
            'hostname' => 'updated-sw.local',
            'username' => 'newadmin',
            'port' => 2222,
            'timeout' => 10,
        ]);
    }

    #[DataProvider('emptyPasswordFieldsProvider')]
    public function test_update_skips_empty_password_field(string $column, array $extraPayload): void
    {
        $admin = $this->createAdminUser();
        $switch = SwitchConfig::factory()->create([
            $column => 'original-value',
        ]);

        $originalRaw = SwitchConfig::where('id', $switch->id)
            ->first()
            ->getRawOriginal($column);

        $response = $this->actingAs($admin)->put('/admin/switches/'.$switch->id, array_merge([
            'name' => $switch->name,
            'hostname' => $switch->hostname,
            'type' => 'cisco',
            'username' => $switch->username,
            'password' => '',
            'port' => $switch->port,
            'timeout' => $switch->timeout,
        ], $extraPayload));

        $response->assertRedirect();

        $updatedRaw = SwitchConfig::where('id', $switch->id)
            ->first()
            ->getRawOriginal($column);

        $this->assertEquals($originalRaw, $updatedRaw);
    }

    public static function emptyPasswordFieldsProvider(): array
    {
        return [
            'password' => ['password', []],
            'enable_password' => ['enable_password', ['enable_password' => '']],
        ];
    }

    public function test_update_switch_keeps_existing_username_when_blank(): void
    {
        $admin = $this->createAdminUser();
        $switch = SwitchConfig::factory()->create(['username' => 'originaluser']);

        $response = $this->actingAs($admin)->put('/admin/switches/'.$switch->id, [
            'name' => $switch->name,
            'hostname' => $switch->hostname,
            'type' => $switch->type,
            'username' => '',
            'password' => '',
            'port' => $switch->port,
            'timeout' => $switch->timeout,
        ]);

        $response->assertRedirect();
        $response->assertSessionHasNoErrors();
        $this->assertDatabaseHas('switch_configs', [
            'id' => $switch->id,
            'username' => 'originaluser',
        ]);
    }

    public function test_update_allows_same_hostname_for_same_switch(): void
    {
        $admin = $this->createAdminUser();
        $switch = SwitchConfig::factory()->create(['hostname' => 'my-sw.local']);

        $response = $this->actingAs($admin)->put('/admin/switches/'.$switch->id, [
            'name' => 'Updated Name',
            'hostname' => 'my-sw.local',
            'type' => 'cisco',
            'username' => 'admin',
            'password' => 'pass',
            'port' => 22,
            'timeout' => 5,
        ]);

        $response->assertRedirect();
        $response->assertSessionHas('success');
    }

    public function test_update_rejects_duplicate_hostname_from_different_switch(): void
    {
        $admin = $this->createAdminUser();
        SwitchConfig::factory()->create(['hostname' => 'taken-sw.local']);
        $switch = SwitchConfig::factory()->create(['hostname' => 'my-sw.local']);

        $response = $this->actingAs($admin)->put('/admin/switches/'.$switch->id, [
            'name' => 'Updated Name',
            'hostname' => 'taken-sw.local',
            'type' => 'cisco',
            'username' => 'admin',
            'password' => 'pass',
            'port' => 22,
            'timeout' => 5,
        ]);

        $response->assertSessionHasErrors('hostname');
    }

    public function test_update_validates_required_fields(): void
    {
        $admin = $this->createAdminUser();
        $switch = SwitchConfig::factory()->create();

        $response = $this->actingAs($admin)->put('/admin/switches/'.$switch->id, []);

        $response->assertSessionHasErrors(['name', 'hostname', 'type']);
    }

    public function test_update_can_toggle_enabled_state(): void
    {
        $admin = $this->createAdminUser();
        $switch = SwitchConfig::factory()->create(['enabled' => true]);

        $response = $this->actingAs($admin)->put('/admin/switches/'.$switch->id, [
            'name' => $switch->name,
            'hostname' => $switch->hostname,
            'type' => $switch->type,
            'username' => $switch->username,
            'password' => '',
            'enabled' => false,
            'port' => $switch->port,
            'timeout' => $switch->timeout,
        ]);

        $response->assertRedirect();

        $this->assertDatabaseHas('switch_configs', [
            'id' => $switch->id,
            'enabled' => false,
        ]);
    }

    public function test_admin_can_delete_switch(): void
    {
        $admin = $this->createAdminUser();
        $switch = SwitchConfig::factory()->create();

        $response = $this->actingAs($admin)->delete('/admin/switches/'.$switch->id);

        $response->assertRedirect('/admin/switches');
        $response->assertSessionHas('success');
        $this->assertDatabaseMissing('switch_configs', ['id' => $switch->id]);
        $this->assertDatabaseHas('audit_logs', [
            'action' => 'switch.deleted',
            'subject_type' => $switch->getMorphClass(),
            'subject_id' => $switch->id,
            'process' => 'admin',
        ]);
    }

    public function test_admin_can_trigger_switch_sync(): void
    {
        Queue::fake();

        $admin = $this->createAdminUser();
        $switch = SwitchConfig::factory()->create();

        $response = $this->actingAs($admin)->post('/admin/switches/'.$switch->id.'/sync');

        $response->assertRedirect();
        $response->assertSessionHas('success', 'Switch sync has been queued.');

        Queue::assertPushed(SyncSwitchPortsJob::class, function (SyncSwitchPortsJob $job) use ($switch): bool {
            return $job->switchConfig->id === $switch->id;
        });
    }

    public function test_sync_resets_circuit_breaker(): void
    {
        Queue::fake();

        $admin = $this->createAdminUser();
        $switch = SwitchConfig::factory()->create();

        $circuitBreaker = resolve(CircuitBreaker::class);
        $circuitBreaker->recordFailure($switch);
        $circuitBreaker->recordFailure($switch);
        $circuitBreaker->recordFailure($switch);
        $this->assertFalse($circuitBreaker->isAvailable($switch));

        $this->actingAs($admin)->post('/admin/switches/'.$switch->id.'/sync');

        $this->assertTrue($circuitBreaker->isAvailable($switch));
    }

    #[DataProvider('switchConnectionTestProvider')]
    public function test_admin_can_test_switch_connection(Closure $mockSetup, bool $expectedSuccess): void
    {
        $admin = $this->createAdminUser();
        $switch = SwitchConfig::factory()->create();

        $mockSetup($switch);

        $response = $this->actingAs($admin)->postJson('/admin/switches/'.$switch->id.'/test');

        $response->assertOk();
        $response->assertJson([
            'success' => $expectedSuccess,
        ]);
    }

    public static function switchConnectionTestProvider(): array
    {
        return [
            'success' => [
                function (SwitchConfig $switch): void {
                    $adapterMock = Mockery::mock(NetworkSwitchInterface::class);
                    $adapterMock->shouldReceive('getAllPorts')->once()->andReturn(collect([]));

                    $factoryMock = Mockery::mock(SwitchServiceFactory::class);
                    $factoryMock->shouldReceive('make')
                        ->with(Mockery::on(fn (SwitchConfig $sc): bool => $sc->id === $switch->id))
                        ->once()
                        ->andReturn($adapterMock);
                    app()->instance(SwitchServiceFactory::class, $factoryMock);
                },
                true,
            ],
            'failure' => [
                function (SwitchConfig $switch): void {
                    $factoryMock = Mockery::mock(SwitchServiceFactory::class);
                    $factoryMock->shouldReceive('make')
                        ->with(Mockery::on(fn (SwitchConfig $sc): bool => $sc->id === $switch->id))
                        ->once()
                        ->andThrow(new RuntimeException('Connection refused'));
                    app()->instance(SwitchServiceFactory::class, $factoryMock);
                },
                false,
            ],
        ];
    }

    public function test_admin_can_view_switch_running_config(): void
    {
        $admin = $this->createAdminUser();
        $switch = SwitchConfig::factory()->create();

        $response = $this->actingAs($admin)->get('/admin/switches/'.$switch->id.'/config');

        $response->assertOk();
    }

    public function test_route_names_for_switch_management(): void
    {
        $this->assertEquals('/admin/switches', route('admin.switches.index', absolute: false));
        $this->assertEquals('/admin/switches/create', route('admin.switches.create', absolute: false));
        $this->assertEquals('/admin/switches', route('admin.switches.store', absolute: false));

        $switch = SwitchConfig::factory()->create();
        $this->assertStringContainsString(
            '/admin/switches/'.$switch->id,
            route('admin.switches.show', $switch)
        );
        $this->assertStringContainsString(
            '/admin/switches/'.$switch->id.'/edit',
            route('admin.switches.edit', $switch)
        );
        $this->assertStringContainsString(
            '/admin/switches/'.$switch->id,
            route('admin.switches.update', $switch)
        );
        $this->assertStringContainsString(
            '/admin/switches/'.$switch->id,
            route('admin.switches.destroy', $switch)
        );
    }

    public function test_route_names_for_switch_actions(): void
    {
        $switch = SwitchConfig::factory()->create();

        $this->assertStringContainsString(
            '/admin/switches/'.$switch->id.'/sync',
            route('admin.switches.sync', $switch)
        );
        $this->assertStringContainsString(
            '/admin/switches/'.$switch->id.'/test',
            route('admin.switches.test-connection', $switch)
        );
        $this->assertStringContainsString(
            '/admin/switches/'.$switch->id.'/config',
            route('admin.switches.config', $switch)
        );
    }

    public function test_test_connection_uses_proxy_transport_with_configured_port(): void
    {
        $admin = $this->createAdminUser();
        $switch = SwitchConfig::factory()->create(['port' => 2222, 'enable_password' => null]);
        $proxy = Mockery::mock(SshProxyClientInterface::class);
        $proxy->shouldReceive('execute')
            ->once()
            ->with($switch->hostname, Mockery::any(), Mockery::any(), Mockery::type('array'), 2222, 'commands', Mockery::any(), Mockery::any(), Mockery::any())
            ->andReturn(new CommandResult(true, [new CommandOutput('terminal length 0', ''), new CommandOutput('show interface status', '')]));
        $this->app->instance(SshProxyClientInterface::class, $proxy);

        $this->actingAs($admin)->postJson('/admin/switches/'.$switch->id.'/test')
            ->assertOk()
            ->assertJson(['success' => true]);
    }
}
