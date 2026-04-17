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
            'theme_name' => 'matrix',
            'theme_mode' => 'light',
        ]);

        $response->assertRedirect();
        $this->assertEquals('matrix', Setting::get('theme.name'));
        $this->assertEquals('light', Setting::get('theme.mode'));
    }

    public function test_theme_update_validates_allowed_values(): void
    {
        $admin = $this->createAdminUser();

        $response = $this->actingAs($admin)->put('/admin/settings/theme', [
            'theme_name' => 'invalid-theme',
            'theme_mode' => 'dark',
        ]);

        $response->assertSessionHasErrors('theme_name');
    }

    public function test_admin_can_update_theme_with_default_theme(): void
    {
        $admin = $this->createAdminUser();

        $response = $this->actingAs($admin)->put('/admin/settings/theme', [
            'theme_name' => 'default',
            'theme_mode' => 'dark',
        ]);

        $response->assertRedirect();
        $this->assertEquals('default', Setting::get('theme.name'));
    }

    public function test_theme_page_returns_all_settings_including_new_fields(): void
    {
        $admin = $this->createAdminUser();

        // Set up all theme settings
        $setting = Setting::whereCode('theme.name')->first() ?? new Setting;
        $setting->code = 'theme.name';
        $setting->name = 'Theme Name';
        $setting->value = 'matrix';
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

        $setting = Setting::whereCode('theme.custom_colors')->first() ?? new Setting;
        $setting->code = 'theme.custom_colors';
        $setting->name = 'Custom Colors';
        $setting->value = '{"primary":"#ff0000","accent":"#00ff00"}';
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
            ->where('settings.theme_name', 'matrix')
            ->where('settings.theme_mode', 'light')
            ->where('settings.site_title', 'My Custom Site')
            ->where('settings.custom_colors', '{"primary":"#ff0000","accent":"#00ff00"}')
            ->where('settings.custom_css', 'body { font-size: 16px; }')
        );
    }

    public function test_admin_can_save_site_title(): void
    {
        $admin = $this->createAdminUser();

        $response = $this->actingAs($admin)->put('/admin/settings/theme', [
            'theme_name' => 'cool-neon',
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
            'theme_name' => 'cool-neon',
            'theme_mode' => 'dark',
            'site_title' => str_repeat('a', 256), // 256 characters, max is 255
        ]);

        $response->assertSessionHasErrors('site_title');
    }

    public function test_admin_can_save_custom_colors(): void
    {
        $admin = $this->createAdminUser();

        $customColors = [
            'primary' => '#ff0000',
            'accent' => '#00ff00',
            'success' => '#0000ff',
        ];

        $response = $this->actingAs($admin)->put('/admin/settings/theme', [
            'theme_name' => 'cool-neon',
            'theme_mode' => 'dark',
            'custom_colors' => $customColors,
        ]);

        $response->assertRedirect();
        $this->assertEquals(json_encode($customColors), Setting::get('theme.custom_colors'));
    }

    public function test_custom_colors_validates_hex_format(): void
    {
        $admin = $this->createAdminUser();

        $response = $this->actingAs($admin)->put('/admin/settings/theme', [
            'theme_name' => 'cool-neon',
            'theme_mode' => 'dark',
            'custom_colors' => [
                'primary' => 'not-a-hex-color',
            ],
        ]);

        $response->assertSessionHasErrors('custom_colors.primary');
    }

    public function test_custom_colors_rejects_invalid_keys(): void
    {
        $admin = $this->createAdminUser();

        $response = $this->actingAs($admin)->put('/admin/settings/theme', [
            'theme_name' => 'cool-neon',
            'theme_mode' => 'dark',
            'custom_colors' => [
                'invalid_key' => '#ff0000',
            ],
        ]);

        $response->assertSessionHasErrors('custom_colors.invalid_key');
    }

    public function test_admin_can_save_custom_css(): void
    {
        $admin = $this->createAdminUser();

        $customCss = 'body { background-color: #000; color: #fff; }';

        $response = $this->actingAs($admin)->put('/admin/settings/theme', [
            'theme_name' => 'cool-neon',
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
            'theme_name' => 'cool-neon',
            'theme_mode' => 'dark',
            'custom_css' => str_repeat('a', 10001), // 10001 characters, max is 10000
        ]);

        $response->assertSessionHasErrors('custom_css');
    }

    public function test_custom_css_rejects_script_tags(): void
    {
        $admin = $this->createAdminUser();

        $response = $this->actingAs($admin)->put('/admin/settings/theme', [
            'theme_name' => 'cool-neon',
            'theme_mode' => 'dark',
            'custom_css' => 'body { color: red; } <script>alert("xss")</script>',
        ]);

        $response->assertSessionHasErrors('custom_css');
    }

    public function test_admin_can_save_all_theme_settings_together(): void
    {
        $admin = $this->createAdminUser();

        $customColors = [
            'primary' => '#ff0000',
            'accent' => '#00ff00',
        ];

        $response = $this->actingAs($admin)->put('/admin/settings/theme', [
            'theme_name' => 'matrix',
            'theme_mode' => 'light',
            'site_title' => 'Full Featured Site',
            'custom_colors' => $customColors,
            'custom_css' => 'body { font-size: 18px; }',
        ]);

        $response->assertRedirect();
        $this->assertEquals('matrix', Setting::get('theme.name'));
        $this->assertEquals('light', Setting::get('theme.mode'));
        $this->assertEquals('Full Featured Site', Setting::get('theme.site_title'));
        $this->assertEquals(json_encode($customColors), Setting::get('theme.custom_colors'));
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
            'theme_name' => 'cool-neon',
            'theme_mode' => 'dark',
            'site_title' => null,
            'custom_colors' => null,
            'custom_css' => null,
        ]);

        $response->assertRedirect();
        $this->assertNull(Setting::get('theme.site_title'));
        $this->assertNull(Setting::get('theme.custom_colors'));
        $this->assertNull(Setting::get('theme.custom_css'));
    }
}
