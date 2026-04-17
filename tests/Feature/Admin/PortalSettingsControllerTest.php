<?php

declare(strict_types=1);

namespace Tests\Feature\Admin;

use App\Models\Role;
use App\Models\Setting;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Queue;
use Tests\TestCase;

class PortalSettingsControllerTest extends TestCase
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

    public function test_admin_can_view_portal_settings(): void
    {
        Queue::fake();
        $admin = $this->createAdminUser();

        $response = $this->actingAs($admin)->get('/admin/settings/portal');

        $response->assertOk();
        $response->assertInertia(fn ($page) => $page->component('Admin/Settings/Portal'));
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
        $this->assertEquals('https://example.com', Setting::get('portal.redirect_url'));
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

    public function test_portal_redirect_url_must_be_valid_url(): void
    {
        Queue::fake();
        $admin = $this->createAdminUser();

        $response = $this->actingAs($admin)->put('/admin/settings/portal', [
            'portal_session_timeout' => 3600,
            'portal_redirect_url' => 'not-a-url',
        ]);

        $response->assertSessionHasErrors('portal_redirect_url');
    }

    public function test_portal_redirect_url_is_optional(): void
    {
        Queue::fake();
        $admin = $this->createAdminUser();

        $response = $this->actingAs($admin)->put('/admin/settings/portal', [
            'portal_session_timeout' => 3600,
            'portal_redirect_url' => '',
        ]);

        $response->assertRedirect();
        $this->assertEquals(3600, Setting::get('portal.session_timeout'));
    }

    protected function saveSetting(string $code, string $name, mixed $value): void
    {
        $setting = Setting::whereCode($code)->first();
        if (! $setting) {
            $setting = new Setting;
            $setting->code = $code;
            $setting->name = $name;
        }

        $setting->value = $value;
        $setting->save();
    }

    public function test_non_admin_cannot_access_portal_settings(): void
    {
        Queue::fake();
        $user = User::factory()->create();

        $this->actingAs($user)->get('/admin/settings/portal')->assertForbidden();
        $this->actingAs($user)->put('/admin/settings/portal', [
            'portal_session_timeout' => 3600,
        ])->assertForbidden();
    }

    public function test_portal_show_returns_correct_settings_structure(): void
    {
        Queue::fake();
        $admin = $this->createAdminUser();

        $this->saveSetting('portal.session_timeout', 'Session Timeout', 7200);
        $this->saveSetting('portal.redirect_url', 'Redirect URL', 'https://test.com');

        $response = $this->actingAs($admin)->get('/admin/settings/portal');

        $response->assertOk();
        $response->assertInertia(fn ($page) => $page
            ->component('Admin/Settings/Portal')
            ->has('settings')
            ->where('settings.portal_session_timeout', 7200)
            ->where('settings.portal_redirect_url', 'https://test.com')
        );
    }
}
