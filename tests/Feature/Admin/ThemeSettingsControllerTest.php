<?php

declare(strict_types=1);

namespace Tests\Feature\Admin;

use App\Models\Role;
use App\Models\Setting;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Queue;
use Tests\TestCase;

class ThemeSettingsControllerTest extends TestCase
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

    public function test_non_admin_cannot_access_theme_settings(): void
    {
        Queue::fake();
        $user = User::factory()->create();

        $this->actingAs($user)->get('/admin/settings/theme')->assertForbidden();
        $this->actingAs($user)->put('/admin/settings/theme', [
            'theme_name' => 'matrix',
            'theme_mode' => 'dark',
        ])->assertForbidden();
    }

    public function test_theme_show_returns_correct_settings_structure(): void
    {
        Queue::fake();
        $admin = $this->createAdminUser();

        // Set known values
        $this->saveSetting('theme.name', 'Theme Name', 'warm-neon');
        $this->saveSetting('theme.mode', 'Theme Mode', 'light');

        $response = $this->actingAs($admin)->get('/admin/settings/theme');

        $response->assertOk();
        $response->assertInertia(fn ($page) => $page
            ->component('Admin/Settings/Theme')
            ->has('settings')
            ->where('settings.theme_name', 'warm-neon')
            ->where('settings.theme_mode', 'light')
        );
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

    public function test_theme_mode_validates_allowed_values(): void
    {
        Queue::fake();
        $admin = $this->createAdminUser();

        $response = $this->actingAs($admin)->put('/admin/settings/theme', [
            'theme_name' => 'matrix',
            'theme_mode' => 'invalid-mode',
        ]);

        $response->assertSessionHasErrors('theme_mode');
    }
}
