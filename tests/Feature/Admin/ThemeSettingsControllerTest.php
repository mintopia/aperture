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
            'accent_hue' => 230,
            'theme_mode' => 'light',
        ]);

        $response->assertRedirect();
        $this->assertEquals(230, Setting::get('theme.accent_hue'));
        $this->assertEquals('light', Setting::get('theme.mode'));
    }

    public function test_theme_update_validates_allowed_values(): void
    {
        Queue::fake();
        $admin = $this->createAdminUser();

        $response = $this->actingAs($admin)->put('/admin/settings/theme', [
            'accent_hue' => -1,
            'theme_mode' => 'dark',
        ]);

        $response->assertSessionHasErrors('accent_hue');

        $response = $this->actingAs($admin)->put('/admin/settings/theme', [
            'accent_hue' => 500,
            'theme_mode' => 'dark',
        ]);

        $response->assertSessionHasErrors('accent_hue');
    }

    public function test_admin_can_set_default_accent_hue(): void
    {
        Queue::fake();
        $admin = $this->createAdminUser();

        $response = $this->actingAs($admin)->put('/admin/settings/theme', [
            'accent_hue' => 55,
            'theme_mode' => 'dark',
        ]);

        $response->assertRedirect();
        $this->assertEquals(55, Setting::get('theme.accent_hue'));
    }

    public function test_non_admin_cannot_access_theme_settings(): void
    {
        Queue::fake();
        $user = User::factory()->create();

        $this->actingAs($user)->get('/admin/settings/theme')->assertForbidden();
        $this->actingAs($user)->put('/admin/settings/theme', [
            'accent_hue' => 230,
            'theme_mode' => 'dark',
        ])->assertForbidden();
    }

    public function test_theme_show_returns_correct_settings_structure(): void
    {
        Queue::fake();
        $admin = $this->createAdminUser();

        // Set known values
        $this->saveSetting('theme.accent_hue', 'Accent Hue', 230);
        $this->saveSetting('theme.mode', 'Theme Mode', 'light');

        $response = $this->actingAs($admin)->get('/admin/settings/theme');

        $response->assertOk();
        $response->assertInertia(fn ($page) => $page
            ->component('Admin/Settings/Theme')
            ->has('settings')
            ->where('settings.accent_hue', 230)
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
            'accent_hue' => 55,
            'theme_mode' => 'invalid-mode',
        ]);

        $response->assertSessionHasErrors('theme_mode');
    }

    public function test_admin_can_update_accent_chroma_and_lightness(): void
    {
        Queue::fake();
        $admin = $this->createAdminUser();

        $response = $this->actingAs($admin)->put('/admin/settings/theme', [
            'accent_hue' => 230,
            'accent_chroma' => 0.25,
            'accent_lightness' => 68,
            'theme_mode' => 'dark',
        ]);

        $response->assertRedirect();
        $this->assertEquals('0.25', Setting::get('theme.accent_chroma'));
        $this->assertEquals('68', Setting::get('theme.accent_lightness'));
    }

    public function test_accent_chroma_validates_range(): void
    {
        Queue::fake();
        $admin = $this->createAdminUser();

        $response = $this->actingAs($admin)->put('/admin/settings/theme', [
            'accent_hue' => 55,
            'accent_chroma' => 0.5,
            'accent_lightness' => 72,
            'theme_mode' => 'dark',
        ]);

        $response->assertSessionHasErrors('accent_chroma');
    }

    public function test_accent_lightness_validates_range(): void
    {
        Queue::fake();
        $admin = $this->createAdminUser();

        $response = $this->actingAs($admin)->put('/admin/settings/theme', [
            'accent_hue' => 55,
            'accent_chroma' => 0.19,
            'accent_lightness' => 100,
            'theme_mode' => 'dark',
        ]);

        $response->assertSessionHasErrors('accent_lightness');
    }

    public function test_theme_show_returns_chroma_and_lightness(): void
    {
        Queue::fake();
        $admin = $this->createAdminUser();

        $this->saveSetting('theme.accent_chroma', 'Accent Chroma', '0.25');
        $this->saveSetting('theme.accent_lightness', 'Accent Lightness', '68');

        $response = $this->actingAs($admin)->get('/admin/settings/theme');

        $response->assertOk();
        $response->assertInertia(fn ($page) => $page
            ->where('settings.accent_chroma', 0.25)
            ->where('settings.accent_lightness', 68)
        );
    }
}
