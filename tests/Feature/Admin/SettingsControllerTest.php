<?php

declare(strict_types=1);

namespace Tests\Feature\Admin;

use App\Models\CapabilityAssignment;
use App\Models\IntegrationConfig;
use App\Models\Role;
use App\Models\Setting;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Queue;
use Inertia\Testing\AssertableInertia as Assert;
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
        $serviceCount = count(config('integrations'));

        IntegrationConfig::setValue('opnsense', 'endpoint', 'https://opn.local');
        CapabilityAssignment::assign('dhcp', 'opnsense');

        $response = $this->actingAs($admin)->get('/admin/settings/integrations');

        $response->assertOk();
        $response->assertInertia(fn ($page) => $page
            ->component('Admin/Settings/Integrations')
            ->has('services', $serviceCount)
            ->where('services.'.($serviceCount - 1).'.id', 'prometheus')
            ->has('services.'.($serviceCount - 1).'.capabilities')
        );
    }

    public function test_non_admin_cannot_access_integrations(): void
    {
        Queue::fake();
        $user = User::factory()->create();

        $response = $this->actingAs($user)->get('/admin/settings/integrations');

        $response->assertForbidden();
    }

    public function test_admin_can_view_theme_settings(): void
    {
        $admin = $this->createAdminUser();

        $response = $this->actingAs($admin)->get('/admin/settings/theme');

        $response->assertOk();
        $response->assertInertia(fn (Assert $page): Assert => $page
            ->component('Admin/Settings/Theme')
            ->has('settings')
        );
    }

    public function test_admin_can_update_theme_settings(): void
    {
        $admin = $this->createAdminUser();

        $response = $this->actingAs($admin)->put('/admin/settings/theme', [
            'accent_hue' => 230,
            'theme_mode' => 'light',
        ]);

        $response->assertRedirect();
        $this->assertEquals('230', Setting::get('theme.accent_hue'));
        $this->assertEquals('light', Setting::get('theme.mode'));
    }

    public function test_theme_update_validates_allowed_values(): void
    {
        $admin = $this->createAdminUser();

        $response = $this->actingAs($admin)->put('/admin/settings/theme', [
            'accent_hue' => 500,
            'theme_mode' => 'dark',
        ]);

        $response->assertSessionHasErrors('accent_hue');
    }

    public function test_admin_can_update_theme_with_default_accent_hue(): void
    {
        $admin = $this->createAdminUser();

        $response = $this->actingAs($admin)->put('/admin/settings/theme', [
            'accent_hue' => 55,
            'theme_mode' => 'dark',
        ]);

        $response->assertRedirect();
        $this->assertEquals('55', Setting::get('theme.accent_hue'));
    }

    public function test_theme_page_returns_all_settings_including_new_fields(): void
    {
        $admin = $this->createAdminUser();

        // Set up all theme settings
        $setting = Setting::whereCode('theme.accent_hue')->first() ?? new Setting;
        $setting->code = 'theme.accent_hue';
        $setting->name = 'Accent Hue';
        $setting->value = '230';
        $setting->save();

        $setting = Setting::whereCode('theme.mode')->first() ?? new Setting;
        $setting->code = 'theme.mode';
        $setting->name = 'Theme Mode';
        $setting->value = 'light';
        $setting->save();

        $setting = Setting::whereCode('theme.site_title')->first() ?? new Setting;
        $setting->code = 'theme.site_title';
        $setting->name = 'Site Title';
        $setting->value = 'My Custom Site';
        $setting->save();

        $setting = Setting::whereCode('theme.custom_css')->first() ?? new Setting;
        $setting->code = 'theme.custom_css';
        $setting->name = 'Custom CSS';
        $setting->value = 'body { font-size: 16px; }';
        $setting->save();

        $response = $this->actingAs($admin)->get('/admin/settings/theme');

        $response->assertOk();
        $response->assertInertia(fn (Assert $page): Assert => $page
            ->component('Admin/Settings/Theme')
            ->has('settings')
            ->where('settings.accent_hue', 230)
            ->where('settings.theme_mode', 'light')
            ->where('settings.site_title', 'My Custom Site')
            ->where('settings.custom_css', 'body { font-size: 16px; }')
        );
    }

    public function test_admin_can_save_site_title(): void
    {
        $admin = $this->createAdminUser();

        $response = $this->actingAs($admin)->put('/admin/settings/theme', [
            'accent_hue' => 55,
            'theme_mode' => 'dark',
            'site_title' => 'My Awesome Site',
        ]);

        $response->assertRedirect();
        $this->assertEquals('My Awesome Site', Setting::get('theme.site_title'));
    }

    public function test_site_title_validates_max_length(): void
    {
        $admin = $this->createAdminUser();

        $response = $this->actingAs($admin)->put('/admin/settings/theme', [
            'accent_hue' => 55,
            'theme_mode' => 'dark',
            'site_title' => str_repeat('a', 256), // 256 characters, max is 255
        ]);

        $response->assertSessionHasErrors('site_title');
    }

    public function test_admin_can_save_custom_css(): void
    {
        $admin = $this->createAdminUser();

        $customCss = 'body { background-color: #000; color: #fff; }';

        $response = $this->actingAs($admin)->put('/admin/settings/theme', [
            'accent_hue' => 55,
            'theme_mode' => 'dark',
            'custom_css' => $customCss,
        ]);

        $response->assertRedirect();
        $this->assertEquals($customCss, Setting::get('theme.custom_css'));
    }

    public function test_custom_css_validates_max_length(): void
    {
        $admin = $this->createAdminUser();

        $response = $this->actingAs($admin)->put('/admin/settings/theme', [
            'accent_hue' => 55,
            'theme_mode' => 'dark',
            'custom_css' => str_repeat('a', 10001), // 10001 characters, max is 10000
        ]);

        $response->assertSessionHasErrors('custom_css');
    }

    public function test_custom_css_rejects_script_tags(): void
    {
        $admin = $this->createAdminUser();

        $response = $this->actingAs($admin)->put('/admin/settings/theme', [
            'accent_hue' => 55,
            'theme_mode' => 'dark',
            'custom_css' => 'body { color: red; } <script>alert("xss")</script>',
        ]);

        $response->assertSessionHasErrors('custom_css');
    }

    public function test_admin_can_save_all_theme_settings_together(): void
    {
        $admin = $this->createAdminUser();

        $response = $this->actingAs($admin)->put('/admin/settings/theme', [
            'accent_hue' => 230,
            'theme_mode' => 'light',
            'site_title' => 'Full Featured Site',
            'custom_css' => 'body { font-size: 18px; }',
        ]);

        $response->assertRedirect();
        $this->assertEquals('230', Setting::get('theme.accent_hue'));
        $this->assertEquals('light', Setting::get('theme.mode'));
        $this->assertEquals('Full Featured Site', Setting::get('theme.site_title'));
        $this->assertEquals('body { font-size: 18px; }', Setting::get('theme.custom_css'));
    }

    public function test_admin_can_clear_optional_theme_fields(): void
    {
        $admin = $this->createAdminUser();

        // First set some values
        $setting = Setting::whereCode('theme.site_title')->first() ?? new Setting;
        $setting->code = 'theme.site_title';
        $setting->name = 'Site Title';
        $setting->value = 'Old Title';
        $setting->save();

        $setting = Setting::whereCode('theme.custom_css')->first() ?? new Setting;
        $setting->code = 'theme.custom_css';
        $setting->name = 'Custom CSS';
        $setting->value = 'body { color: red; }';
        $setting->save();

        // Now clear them
        $response = $this->actingAs($admin)->put('/admin/settings/theme', [
            'accent_hue' => 55,
            'theme_mode' => 'dark',
            'site_title' => null,
            'custom_css' => null,
        ]);

        $response->assertRedirect();
        $this->assertNull(Setting::get('theme.site_title'));
        $this->assertNull(Setting::get('theme.custom_css'));
    }
}
