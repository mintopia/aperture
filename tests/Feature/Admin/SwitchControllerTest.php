<?php

declare(strict_types=1);

namespace Tests\Feature\Admin;

use App\Models\Role;
use App\Models\SwitchConfig;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Queue;
use Tests\TestCase;

class SwitchControllerTest extends TestCase
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

    public function test_admin_can_view_switches_page(): void
    {
        Queue::fake();
        $admin = $this->createAdminUser();

        $response = $this->actingAs($admin)->get('/admin/settings/switches');

        $response->assertOk();
        $response->assertInertia(fn ($page) => $page->component('Admin/Settings/Switches'));
    }

    public function test_switches_index_returns_switch_data(): void
    {
        Queue::fake();
        $admin = $this->createAdminUser();
        $switch = SwitchConfig::factory()->create([
            'name' => 'Core Switch',
            'hostname' => 'core-sw.local',
            'type' => 'cisco',
        ]);

        $response = $this->actingAs($admin)->get('/admin/settings/switches');

        $response->assertOk();
        $response->assertInertia(fn ($page) => $page
            ->component('Admin/Settings/Switches')
            ->has('switches', 1)
            ->where('switches.0.name', 'Core Switch')
            ->where('switches.0.hostname', 'core-sw.local')
        );
    }

    public function test_admin_can_add_switch(): void
    {
        Queue::fake();
        $admin = $this->createAdminUser();

        $response = $this->actingAs($admin)->post('/admin/settings/switches', [
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
        ]);
    }

    public function test_add_switch_validates_required_fields(): void
    {
        Queue::fake();
        $admin = $this->createAdminUser();

        $response = $this->actingAs($admin)->post('/admin/settings/switches', [
            'hostname' => 'core-sw.local',
        ]);

        $response->assertSessionHasErrors(['name', 'type', 'username', 'password']);
    }

    public function test_add_switch_validates_hostname_uniqueness(): void
    {
        Queue::fake();
        $admin = $this->createAdminUser();
        SwitchConfig::factory()->create(['hostname' => 'existing-sw.local']);

        $response = $this->actingAs($admin)->post('/admin/settings/switches', [
            'name' => 'New Switch',
            'hostname' => 'existing-sw.local',
            'type' => 'cisco',
            'username' => 'admin',
            'password' => 'secret',
        ]);

        $response->assertSessionHasErrors('hostname');
    }

    public function test_add_switch_validates_port_range(): void
    {
        Queue::fake();
        $admin = $this->createAdminUser();

        $response = $this->actingAs($admin)->post('/admin/settings/switches', [
            'name' => 'Test Switch',
            'hostname' => 'test-sw.local',
            'type' => 'cisco',
            'username' => 'admin',
            'password' => 'secret',
            'port' => 99999,
        ]);

        $response->assertSessionHasErrors('port');
    }

    public function test_admin_can_update_switch(): void
    {
        Queue::fake();
        $admin = $this->createAdminUser();
        $switch = SwitchConfig::factory()->create();

        $response = $this->actingAs($admin)->put(
            '/admin/settings/switches/'.$switch->id,
            [
                'name' => 'Updated Switch',
                'hostname' => 'updated-sw.local',
                'type' => 'cisco',
                'username' => 'newadmin',
                'password' => 'newpassword',
                'port' => 2222,
                'timeout' => 10,
            ]
        );

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

    public function test_update_switch_skips_empty_password(): void
    {
        Queue::fake();
        $admin = $this->createAdminUser();
        $switch = SwitchConfig::factory()->create([
            'password' => 'original-password',
        ]);

        $originalPasswordRaw = SwitchConfig::where('id', $switch->id)
            ->first()
            ->getRawOriginal('password');

        $response = $this->actingAs($admin)->put(
            '/admin/settings/switches/'.$switch->id,
            [
                'name' => $switch->name,
                'hostname' => $switch->hostname,
                'type' => 'cisco',
                'username' => $switch->username,
                'password' => '',
                'port' => $switch->port,
                'timeout' => $switch->timeout,
            ]
        );

        $response->assertRedirect();

        $updatedPasswordRaw = SwitchConfig::where('id', $switch->id)
            ->first()
            ->getRawOriginal('password');

        $this->assertEquals($originalPasswordRaw, $updatedPasswordRaw);
    }

    public function test_update_switch_skips_empty_enable_password(): void
    {
        Queue::fake();
        $admin = $this->createAdminUser();
        $switch = SwitchConfig::factory()->create([
            'enable_password' => 'original-enable',
        ]);

        $originalRaw = SwitchConfig::where('id', $switch->id)
            ->first()
            ->getRawOriginal('enable_password');

        $response = $this->actingAs($admin)->put(
            '/admin/settings/switches/'.$switch->id,
            [
                'name' => $switch->name,
                'hostname' => $switch->hostname,
                'type' => 'cisco',
                'username' => $switch->username,
                'password' => '',
                'enable_password' => '',
                'port' => $switch->port,
                'timeout' => $switch->timeout,
            ]
        );

        $response->assertRedirect();

        $updatedRaw = SwitchConfig::where('id', $switch->id)
            ->first()
            ->getRawOriginal('enable_password');

        $this->assertEquals($originalRaw, $updatedRaw);
    }

    public function test_update_switch_allows_same_hostname(): void
    {
        Queue::fake();
        $admin = $this->createAdminUser();
        $switch = SwitchConfig::factory()->create(['hostname' => 'my-sw.local']);

        $response = $this->actingAs($admin)->put(
            '/admin/settings/switches/'.$switch->id,
            [
                'name' => 'Updated Name',
                'hostname' => 'my-sw.local',
                'type' => 'cisco',
                'username' => 'admin',
                'password' => 'pass',
                'port' => 22,
                'timeout' => 5,
            ]
        );

        $response->assertRedirect();
        $response->assertSessionHas('success');
    }

    public function test_admin_can_delete_switch(): void
    {
        Queue::fake();
        $admin = $this->createAdminUser();
        $switch = SwitchConfig::factory()->create();

        $response = $this->actingAs($admin)->delete(
            '/admin/settings/switches/'.$switch->id
        );

        $response->assertRedirect();
        $response->assertSessionHas('success');
        $this->assertDatabaseMissing('switch_configs', ['id' => $switch->id]);
    }

    public function test_non_admin_cannot_access_switches(): void
    {
        Queue::fake();
        $user = User::factory()->create();

        $this->actingAs($user)->get('/admin/settings/switches')->assertForbidden();
        $this->actingAs($user)->post('/admin/settings/switches', [])->assertForbidden();
    }

    public function test_store_uses_form_request_validation(): void
    {
        Queue::fake();
        $admin = $this->createAdminUser();

        // Test timeout max validation (from form request)
        $response = $this->actingAs($admin)->post('/admin/settings/switches', [
            'name' => 'Test Switch',
            'hostname' => 'test-sw.local',
            'type' => 'cisco',
            'username' => 'admin',
            'password' => 'secret',
            'timeout' => 999,
        ]);

        $response->assertSessionHasErrors('timeout');
    }

    public function test_route_names_are_preserved(): void
    {
        $this->assertEquals('/admin/settings/switches', route('admin.settings.switches', absolute: false));
        $this->assertEquals('/admin/settings/switches', route('admin.settings.switches.store', absolute: false));

        $switch = SwitchConfig::factory()->create();
        $this->assertStringContainsString(
            '/admin/settings/switches/'.$switch->id,
            route('admin.settings.switches.update', $switch)
        );
        $this->assertStringContainsString(
            '/admin/settings/switches/'.$switch->id,
            route('admin.settings.switches.destroy', $switch)
        );
    }
}
