<?php

namespace Tests\Feature\Admin;

use App\Models\Role;
use App\Models\SwitchConfig;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Queue;
use Tests\TestCase;

class SwitchConfigControllerTest extends TestCase
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

    public function test_non_admin_cannot_access_switches(): void
    {
        Queue::fake();
        $user = User::factory()->create();

        $response = $this->actingAs($user)->get('/admin/settings/switches');

        $response->assertForbidden();
    }
}
