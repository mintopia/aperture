<?php

namespace Tests\Feature\Admin;

use App\Models\CapabilityAssignment;
use App\Models\IntegrationConfig;
use App\Models\Role;
use App\Models\Setting;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Queue;
use Tests\TestCase;

class SettingsControllerTest extends TestCase
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

    public function test_admin_can_view_theme_settings(): void
    {
        Queue::fake();
        $admin = $this->createAdminUser();

        $response = $this->actingAs($admin)->get('/admin/settings/theme');

        $response->assertOk();
        $response->assertInertia(fn ($page) => $page->component('Admin/Settings/Theme'));
    }

    public function test_admin_can_update_theme_settings(): void
    {
        Queue::fake();
        $admin = $this->createAdminUser();

        $response = $this->actingAs($admin)->put('/admin/settings/theme', [
            'theme_name' => 'matrix',
            'theme_mode' => 'light',
        ]);

        $response->assertRedirect();
        $this->assertEquals('matrix', Setting::get('theme.name'));
        $this->assertEquals('light', Setting::get('theme.mode'));
    }

    public function test_theme_update_validates_allowed_values(): void
    {
        Queue::fake();
        $admin = $this->createAdminUser();

        $response = $this->actingAs($admin)->put('/admin/settings/theme', [
            'theme_name' => 'invalid-theme',
            'theme_mode' => 'dark',
        ]);

        $response->assertSessionHasErrors('theme_name');
    }

    public function test_admin_can_set_default_theme(): void
    {
        Queue::fake();
        $admin = $this->createAdminUser();

        $response = $this->actingAs($admin)->put('/admin/settings/theme', [
            'theme_name' => 'default',
            'theme_mode' => 'dark',
        ]);

        $response->assertRedirect();
        $this->assertEquals('default', Setting::get('theme.name'));
    }

    public function test_admin_can_update_event_settings(): void
    {
        Queue::fake();
        $admin = $this->createAdminUser();

        $response = $this->actingAs($admin)->put('/admin/settings/event', [
            'event_name' => 'Epic LAN 42',
            'event_description' => 'The best LAN party ever',
        ]);

        $response->assertRedirect();
        $this->assertEquals('Epic LAN 42', Setting::get('event.name'));
    }

    public function test_admin_can_update_portal_settings(): void
    {
        Queue::fake();
        $admin = $this->createAdminUser();

        $response = $this->actingAs($admin)->put('/admin/settings/portal', [
            'portal_session_timeout' => 3600,
            'portal_redirect_url' => 'https://example.com',
        ]);

        $response->assertRedirect();
        $this->assertEquals(3600, Setting::get('portal.session_timeout'));
    }

    public function test_portal_session_timeout_minimum_enforced(): void
    {
        Queue::fake();
        $admin = $this->createAdminUser();

        $response = $this->actingAs($admin)->put('/admin/settings/portal', [
            'portal_session_timeout' => 60,
            'portal_redirect_url' => '',
        ]);

        $response->assertSessionHasErrors('portal_session_timeout');
    }

    public function test_non_admin_cannot_access_settings(): void
    {
        Queue::fake();
        $user = User::factory()->create();

        $response = $this->actingAs($user)->get('/admin/settings/theme');

        $response->assertForbidden();
    }

    public function test_admin_can_view_integrations_settings(): void
    {
        Queue::fake();
        $admin = $this->createAdminUser();

        $response = $this->actingAs($admin)->get('/admin/settings/integrations');

        $response->assertOk();
        $response->assertInertia(fn ($page) => $page
            ->component('Admin/Settings/Integrations')
            ->has('services')
        );
    }

    public function test_integrations_page_returns_table_data(): void
    {
        Queue::fake();
        $admin = $this->createAdminUser();

        IntegrationConfig::setValue('opnsense', 'endpoint', 'https://opn.local');
        CapabilityAssignment::assign('dhcp', 'opnsense');

        $response = $this->actingAs($admin)->get('/admin/settings/integrations');

        $response->assertOk();
        $response->assertInertia(fn ($page) => $page
            ->component('Admin/Settings/Integrations')
            ->has('services', 5)
            ->where('services.4.id', 'borealis')
            ->has('services.4.capabilities')
        );
    }

    public function test_admin_can_view_event_settings(): void
    {
        Queue::fake();
        $admin = $this->createAdminUser();

        $response = $this->actingAs($admin)->get('/admin/settings/event');

        $response->assertOk();
        $response->assertInertia(fn ($page) => $page->component('Admin/Settings/Event'));
    }

    public function test_admin_can_view_portal_settings(): void
    {
        Queue::fake();
        $admin = $this->createAdminUser();

        $response = $this->actingAs($admin)->get('/admin/settings/portal');

        $response->assertOk();
        $response->assertInertia(fn ($page) => $page->component('Admin/Settings/Portal'));
    }
}
