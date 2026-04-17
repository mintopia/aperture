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
use App\Services\NetworkSwitch\SwitchServiceFactory;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Queue;
use Mockery;
use RuntimeException;
use Tests\TestCase;

class SwitchManagementControllerTest extends TestCase
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

    // -------------------------------------------------------------------------
    // Authentication & Authorization
    // -------------------------------------------------------------------------

    public function test_unauthenticated_user_is_redirected_from_switches_index(): void
    {
        $response = $this->get('/admin/switches');
        $response->assertRedirect('/captive');
    }

    public function test_unauthenticated_user_is_redirected_from_switches_store(): void
    {
        $response = $this->post('/admin/switches', []);
        $response->assertRedirect('/captive');
    }

    public function test_unauthenticated_user_is_redirected_from_switches_show(): void
    {
        $switch = SwitchConfig::factory()->create();
        $response = $this->get('/admin/switches/'.$switch->id);
        $response->assertRedirect('/captive');
    }

    public function test_non_admin_cannot_access_switches_index(): void
    {
        $user = User::factory()->create();
        $this->actingAs($user)->get('/admin/switches')->assertForbidden();
    }

    public function test_non_admin_cannot_store_switch(): void
    {
        $user = User::factory()->create();
        $this->actingAs($user)->post('/admin/switches', [])->assertForbidden();
    }

    public function test_non_admin_cannot_update_switch(): void
    {
        $user = User::factory()->create();
        $switch = SwitchConfig::factory()->create();
        $this->actingAs($user)->put('/admin/switches/'.$switch->id, [])->assertForbidden();
    }

    public function test_non_admin_cannot_delete_switch(): void
    {
        $user = User::factory()->create();
        $switch = SwitchConfig::factory()->create();
        $this->actingAs($user)->delete('/admin/switches/'.$switch->id)->assertForbidden();
    }

    public function test_non_admin_cannot_sync_switch(): void
    {
        $user = User::factory()->create();
        $switch = SwitchConfig::factory()->create();
        $this->actingAs($user)->post('/admin/switches/'.$switch->id.'/sync')->assertForbidden();
    }

    public function test_non_admin_cannot_test_switch_connection(): void
    {
        $user = User::factory()->create();
        $switch = SwitchConfig::factory()->create();
        $this->actingAs($user)->post('/admin/switches/'.$switch->id.'/test')->assertForbidden();
    }

    public function test_non_admin_cannot_view_switch_config(): void
    {
        $user = User::factory()->create();
        $switch = SwitchConfig::factory()->create();
        $this->actingAs($user)->get('/admin/switches/'.$switch->id.'/config')->assertForbidden();
    }

    // -------------------------------------------------------------------------
    // Index — data-testid: switches-index-page
    // -------------------------------------------------------------------------

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

    // -------------------------------------------------------------------------
    // Create — data-testid: switches-create-page
    // -------------------------------------------------------------------------

    public function test_admin_can_view_switch_create_form(): void
    {
        $admin = $this->createAdminUser();

        $response = $this->actingAs($admin)->get('/admin/switches/create');

        $response->assertOk();
        $response->assertInertia(fn ($page) => $page
            ->component('Admin/Switches/Create')
        );
    }

    // -------------------------------------------------------------------------
    // Store — data-testid: switches-store-action
    // -------------------------------------------------------------------------

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

    // -------------------------------------------------------------------------
    // Show — data-testid: switches-show-page
    // -------------------------------------------------------------------------

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

    public function test_show_includes_can_download_config_true_for_cisco_ios(): void
    {
        $admin = $this->createAdminUser();
        $switch = SwitchConfig::factory()->create(['type' => 'cisco_ios']);

        $response = $this->actingAs($admin)->get('/admin/switches/'.$switch->id);

        $response->assertOk();
        $response->assertInertia(fn ($page) => $page
            ->where('canDownloadConfig', true)
        );
    }

    public function test_show_includes_can_download_config_true_for_cisco_nxos(): void
    {
        $admin = $this->createAdminUser();
        $switch = SwitchConfig::factory()->create(['type' => 'cisco_nxos']);

        $response = $this->actingAs($admin)->get('/admin/switches/'.$switch->id);

        $response->assertOk();
        $response->assertInertia(fn ($page) => $page
            ->where('canDownloadConfig', true)
        );
    }

    public function test_show_includes_can_download_config_false_for_other_types(): void
    {
        $admin = $this->createAdminUser();
        $switch = SwitchConfig::factory()->create(['type' => 'cisco']);

        $response = $this->actingAs($admin)->get('/admin/switches/'.$switch->id);

        $response->assertOk();
        $response->assertInertia(fn ($page) => $page
            ->where('canDownloadConfig', false)
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

    public function test_show_returns_404_for_nonexistent_switch(): void
    {
        $admin = $this->createAdminUser();

        $response = $this->actingAs($admin)->get('/admin/switches/99999');

        $response->assertNotFound();
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

    // -------------------------------------------------------------------------
    // Edit — data-testid: switches-edit-page
    // -------------------------------------------------------------------------

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

    public function test_edit_returns_404_for_nonexistent_switch(): void
    {
        $admin = $this->createAdminUser();

        $response = $this->actingAs($admin)->get('/admin/switches/99999/edit');

        $response->assertNotFound();
    }

    // -------------------------------------------------------------------------
    // Update — data-testid: switches-update-action
    // -------------------------------------------------------------------------

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

    public function test_update_skips_empty_password(): void
    {
        $admin = $this->createAdminUser();
        $switch = SwitchConfig::factory()->create([
            'password' => 'original-password',
        ]);

        $originalPasswordRaw = SwitchConfig::where('id', $switch->id)
            ->first()
            ->getRawOriginal('password');

        $response = $this->actingAs($admin)->put('/admin/switches/'.$switch->id, [
            'name' => $switch->name,
            'hostname' => $switch->hostname,
            'type' => 'cisco',
            'username' => $switch->username,
            'password' => '',
            'port' => $switch->port,
            'timeout' => $switch->timeout,
        ]);

        $response->assertRedirect();

        $updatedPasswordRaw = SwitchConfig::where('id', $switch->id)
            ->first()
            ->getRawOriginal('password');

        $this->assertEquals($originalPasswordRaw, $updatedPasswordRaw);
    }

    public function test_update_skips_empty_enable_password(): void
    {
        $admin = $this->createAdminUser();
        $switch = SwitchConfig::factory()->create([
            'enable_password' => 'original-enable',
        ]);

        $originalRaw = SwitchConfig::where('id', $switch->id)
            ->first()
            ->getRawOriginal('enable_password');

        $response = $this->actingAs($admin)->put('/admin/switches/'.$switch->id, [
            'name' => $switch->name,
            'hostname' => $switch->hostname,
            'type' => 'cisco',
            'username' => $switch->username,
            'password' => '',
            'enable_password' => '',
            'port' => $switch->port,
            'timeout' => $switch->timeout,
        ]);

        $response->assertRedirect();

        $updatedRaw = SwitchConfig::where('id', $switch->id)
            ->first()
            ->getRawOriginal('enable_password');

        $this->assertEquals($originalRaw, $updatedRaw);
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

    public function test_update_returns_404_for_nonexistent_switch(): void
    {
        $admin = $this->createAdminUser();

        $response = $this->actingAs($admin)->put('/admin/switches/99999', [
            'name' => 'Ghost Switch',
            'hostname' => 'ghost-sw.local',
            'type' => 'cisco',
            'username' => 'admin',
            'password' => 'secret',
        ]);

        $response->assertNotFound();
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

    // -------------------------------------------------------------------------
    // Destroy — data-testid: switches-delete-action
    // -------------------------------------------------------------------------

    public function test_admin_can_delete_switch(): void
    {
        $admin = $this->createAdminUser();
        $switch = SwitchConfig::factory()->create();

        $response = $this->actingAs($admin)->delete('/admin/switches/'.$switch->id);

        $response->assertRedirect('/admin/switches');
        $response->assertSessionHas('success');
        $this->assertDatabaseMissing('switch_configs', ['id' => $switch->id]);
    }

    public function test_delete_returns_404_for_nonexistent_switch(): void
    {
        $admin = $this->createAdminUser();

        $response = $this->actingAs($admin)->delete('/admin/switches/99999');

        $response->assertNotFound();
    }

    public function test_delete_redirects_to_switches_index(): void
    {
        $admin = $this->createAdminUser();
        $switch = SwitchConfig::factory()->create();

        $response = $this->actingAs($admin)->delete('/admin/switches/'.$switch->id);

        $response->assertRedirect('/admin/switches');
    }

    // -------------------------------------------------------------------------
    // Sync — data-testid: switches-sync-action
    // -------------------------------------------------------------------------

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

    public function test_sync_returns_404_for_nonexistent_switch(): void
    {
        $admin = $this->createAdminUser();

        $response = $this->actingAs($admin)->post('/admin/switches/99999/sync');

        $response->assertNotFound();
    }

    // -------------------------------------------------------------------------
    // Test Connection — data-testid: switches-test-connection-action
    // -------------------------------------------------------------------------

    public function test_admin_can_test_switch_connection_success(): void
    {
        $admin = $this->createAdminUser();
        $switch = SwitchConfig::factory()->create();

        $adapterMock = Mockery::mock(NetworkSwitchInterface::class);
        $adapterMock->shouldReceive('getAllPorts')->once()->andReturn(collect([]));

        $factoryMock = Mockery::mock(SwitchServiceFactory::class);
        $factoryMock->shouldReceive('make')
            ->with(Mockery::on(fn (SwitchConfig $sc): bool => $sc->id === $switch->id))
            ->once()
            ->andReturn($adapterMock);
        $this->app->instance(SwitchServiceFactory::class, $factoryMock);

        $response = $this->actingAs($admin)->postJson('/admin/switches/'.$switch->id.'/test');

        $response->assertOk();
        $response->assertJson([
            'success' => true,
        ]);
    }

    public function test_admin_can_test_switch_connection_failure(): void
    {
        $admin = $this->createAdminUser();
        $switch = SwitchConfig::factory()->create();

        $factoryMock = Mockery::mock(SwitchServiceFactory::class);
        $factoryMock->shouldReceive('make')
            ->with(Mockery::on(fn (SwitchConfig $sc): bool => $sc->id === $switch->id))
            ->once()
            ->andThrow(new RuntimeException('Connection refused'));
        $this->app->instance(SwitchServiceFactory::class, $factoryMock);

        $response = $this->actingAs($admin)->postJson('/admin/switches/'.$switch->id.'/test');

        $response->assertOk();
        $response->assertJson([
            'success' => false,
        ]);
    }

    public function test_test_connection_returns_404_for_nonexistent_switch(): void
    {
        $admin = $this->createAdminUser();

        $response = $this->actingAs($admin)->postJson('/admin/switches/99999/test');

        $response->assertNotFound();
    }

    // -------------------------------------------------------------------------
    // Config — data-testid: switches-config-page
    // -------------------------------------------------------------------------

    public function test_admin_can_view_switch_running_config(): void
    {
        $admin = $this->createAdminUser();
        $switch = SwitchConfig::factory()->create();

        $response = $this->actingAs($admin)->get('/admin/switches/'.$switch->id.'/config');

        $response->assertOk();
    }

    public function test_config_returns_404_for_nonexistent_switch(): void
    {
        $admin = $this->createAdminUser();

        $response = $this->actingAs($admin)->get('/admin/switches/99999/config');

        $response->assertNotFound();
    }

    // -------------------------------------------------------------------------
    // Route names — ensure proper naming conventions
    // -------------------------------------------------------------------------

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
}
