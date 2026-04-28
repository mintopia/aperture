<?php

declare(strict_types=1);

namespace Tests\Feature\Admin;

use App\Models\Page;
use App\Models\Role;
use App\Models\Setting;
use App\Models\User;
use Illuminate\Foundation\Testing\LazilyRefreshDatabase;
use Illuminate\Support\Facades\Queue;
use Tests\TestCase;

class GeneralSettingsControllerTest extends TestCase
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

    protected function validPayload(array $overrides = []): array
    {
        return array_merge([
            'site_title' => 'Portal',
            'terms_type' => 'url',
            'terms_value' => null,
            'privacy_type' => 'url',
            'privacy_value' => null,
            'theme_mode' => 'dark',
            'accent_hue' => 55,
            'accent_chroma' => 0.16,
            'accent_lightness' => 76,
            'custom_css' => null,
        ], $overrides);
    }

    public function test_admin_can_view_general_settings(): void
    {
        Queue::fake();
        $admin = $this->createAdminUser();

        $response = $this->actingAs($admin)->get('/admin/content/settings');

        $response->assertOk();
        $response->assertInertia(fn ($page) => $page->component('Admin/Content/Settings'));
    }

    public function test_settings_page_includes_pages_list(): void
    {
        Queue::fake();
        $admin = $this->createAdminUser();

        Page::create(['title' => 'Privacy Policy', 'slug' => 'privacy-policy', 'content' => null]);
        Page::create(['title' => 'Terms of Service', 'slug' => 'terms-of-service', 'content' => null]);

        $response = $this->actingAs($admin)->get('/admin/content/settings');

        $response->assertOk();
        $response->assertInertia(fn ($page) => $page
            ->component('Admin/Content/Settings')
            ->has('pages', 2)
        );
    }

    public function test_admin_can_save_site_title(): void
    {
        Queue::fake();
        $admin = $this->createAdminUser();

        $response = $this->actingAs($admin)->put('/admin/content/settings', $this->validPayload([
            'site_title' => 'My Network Portal',
        ]));

        $response->assertRedirect();
        $this->assertEquals('My Network Portal', Setting::get('general.site_title'));
    }

    public function test_admin_can_save_terms_as_page(): void
    {
        Queue::fake();
        $admin = $this->createAdminUser();

        $response = $this->actingAs($admin)->put('/admin/content/settings', $this->validPayload([
            'terms_type' => 'page',
            'terms_value' => 'terms-of-service',
        ]));

        $response->assertRedirect();
        $this->assertEquals('page', Setting::get('general.terms_type'));
        $this->assertEquals('terms-of-service', Setting::get('general.terms_value'));
    }

    public function test_admin_can_save_terms_as_url(): void
    {
        Queue::fake();
        $admin = $this->createAdminUser();

        $response = $this->actingAs($admin)->put('/admin/content/settings', $this->validPayload([
            'terms_type' => 'url',
            'terms_value' => 'https://example.com/terms',
        ]));

        $response->assertRedirect();
        $this->assertEquals('url', Setting::get('general.terms_type'));
        $this->assertEquals('https://example.com/terms', Setting::get('general.terms_value'));
    }

    public function test_site_title_is_required(): void
    {
        Queue::fake();
        $admin = $this->createAdminUser();

        $response = $this->actingAs($admin)->put('/admin/content/settings', $this->validPayload([
            'site_title' => '',
        ]));

        $response->assertSessionHasErrors('site_title');
    }

    public function test_terms_type_must_be_valid(): void
    {
        Queue::fake();
        $admin = $this->createAdminUser();

        $response = $this->actingAs($admin)->put('/admin/content/settings', $this->validPayload([
            'terms_type' => 'invalid',
        ]));

        $response->assertSessionHasErrors('terms_type');
    }

    public function test_non_admin_cannot_access_general_settings(): void
    {
        Queue::fake();
        $user = User::factory()->create();

        $this->actingAs($user)->get('/admin/content/settings')->assertForbidden();
        $this->actingAs($user)->put('/admin/content/settings', $this->validPayload())->assertForbidden();
    }

    public function test_settings_page_returns_current_settings_values(): void
    {
        Queue::fake();
        $admin = $this->createAdminUser();

        $this->saveSetting('general.site_title', 'Site Title', 'My Portal');
        $this->saveSetting('general.terms_type', 'Terms Type', 'page');
        $this->saveSetting('general.terms_value', 'Terms Value', 'terms');

        $response = $this->actingAs($admin)->get('/admin/content/settings');

        $response->assertOk();
        $response->assertInertia(fn ($page) => $page
            ->component('Admin/Content/Settings')
            ->has('settings')
            ->where('settings.site_title', 'My Portal')
            ->where('settings.terms_type', 'page')
            ->where('settings.terms_value', 'terms')
        );
    }

    public function test_admin_can_update_theme_settings(): void
    {
        Queue::fake();
        $admin = $this->createAdminUser();

        $response = $this->actingAs($admin)->put('/admin/content/settings', $this->validPayload([
            'accent_hue' => 230,
            'theme_mode' => 'light',
        ]));

        $response->assertRedirect();
        $this->assertEquals('230', Setting::get('theme.accent_hue'));
        $this->assertEquals('light', Setting::get('theme.mode'));
    }

    public function test_theme_update_validates_accent_hue_range(): void
    {
        Queue::fake();
        $admin = $this->createAdminUser();

        $response = $this->actingAs($admin)->put('/admin/content/settings', $this->validPayload([
            'accent_hue' => -1,
        ]));
        $response->assertSessionHasErrors('accent_hue');

        $response = $this->actingAs($admin)->put('/admin/content/settings', $this->validPayload([
            'accent_hue' => 500,
        ]));
        $response->assertSessionHasErrors('accent_hue');
    }

    public function test_theme_mode_validates_allowed_values(): void
    {
        Queue::fake();
        $admin = $this->createAdminUser();

        $response = $this->actingAs($admin)->put('/admin/content/settings', $this->validPayload([
            'theme_mode' => 'invalid-mode',
        ]));

        $response->assertSessionHasErrors('theme_mode');
    }

    public function test_admin_can_update_accent_chroma_and_lightness(): void
    {
        Queue::fake();
        $admin = $this->createAdminUser();

        $response = $this->actingAs($admin)->put('/admin/content/settings', $this->validPayload([
            'accent_hue' => 230,
            'accent_chroma' => 0.25,
            'accent_lightness' => 68,
        ]));

        $response->assertRedirect();
        $this->assertEquals('0.25', Setting::get('theme.accent_chroma'));
        $this->assertEquals('68', Setting::get('theme.accent_lightness'));
    }

    public function test_accent_chroma_validates_range(): void
    {
        Queue::fake();
        $admin = $this->createAdminUser();

        $response = $this->actingAs($admin)->put('/admin/content/settings', $this->validPayload([
            'accent_chroma' => 0.5,
        ]));

        $response->assertSessionHasErrors('accent_chroma');
    }

    public function test_accent_lightness_validates_range(): void
    {
        Queue::fake();
        $admin = $this->createAdminUser();

        $response = $this->actingAs($admin)->put('/admin/content/settings', $this->validPayload([
            'accent_lightness' => 100,
        ]));

        $response->assertSessionHasErrors('accent_lightness');
    }

    public function test_settings_page_returns_theme_values(): void
    {
        Queue::fake();
        $admin = $this->createAdminUser();

        $this->saveSetting('theme.accent_hue', 'Accent Hue', '230');
        $this->saveSetting('theme.mode', 'Theme Mode', 'light');
        $this->saveSetting('theme.accent_chroma', 'Accent Chroma', '0.25');
        $this->saveSetting('theme.accent_lightness', 'Accent Lightness', '68');
        $this->saveSetting('theme.custom_css', 'Custom CSS', 'body { font-size: 16px; }');

        $response = $this->actingAs($admin)->get('/admin/content/settings');

        $response->assertOk();
        $response->assertInertia(fn ($page) => $page
            ->has('settings')
            ->where('settings.accent_hue', 230)
            ->where('settings.theme_mode', 'light')
            ->where('settings.accent_chroma', 0.25)
            ->where('settings.accent_lightness', 68)
            ->where('settings.custom_css', 'body { font-size: 16px; }')
        );
    }

    public function test_admin_can_save_custom_css(): void
    {
        Queue::fake();
        $admin = $this->createAdminUser();

        $customCss = 'body { background-color: #000; color: #fff; }';

        $response = $this->actingAs($admin)->put('/admin/content/settings', $this->validPayload([
            'custom_css' => $customCss,
        ]));

        $response->assertRedirect();
        $this->assertEquals($customCss, Setting::get('theme.custom_css'));
    }

    public function test_custom_css_validates_max_length(): void
    {
        Queue::fake();
        $admin = $this->createAdminUser();

        $response = $this->actingAs($admin)->put('/admin/content/settings', $this->validPayload([
            'custom_css' => str_repeat('a', 10001),
        ]));

        $response->assertSessionHasErrors('custom_css');
    }

    public function test_custom_css_rejects_script_tags(): void
    {
        Queue::fake();
        $admin = $this->createAdminUser();

        $response = $this->actingAs($admin)->put('/admin/content/settings', $this->validPayload([
            'custom_css' => 'body { color: red; } <script>alert("xss")</script>',
        ]));

        $response->assertSessionHasErrors('custom_css');
    }

    public function test_admin_can_save_all_settings_together(): void
    {
        Queue::fake();
        $admin = $this->createAdminUser();

        $response = $this->actingAs($admin)->put('/admin/content/settings', $this->validPayload([
            'site_title' => 'My Portal',
            'terms_type' => 'url',
            'terms_value' => 'https://example.com/terms',
            'accent_hue' => 230,
            'theme_mode' => 'light',
            'custom_css' => 'body { font-size: 18px; }',
        ]));

        $response->assertRedirect();
        $this->assertEquals('My Portal', Setting::get('general.site_title'));
        $this->assertEquals('230', Setting::get('theme.accent_hue'));
        $this->assertEquals('light', Setting::get('theme.mode'));
        $this->assertEquals('body { font-size: 18px; }', Setting::get('theme.custom_css'));
    }

    public function test_admin_can_clear_optional_theme_fields(): void
    {
        Queue::fake();
        $admin = $this->createAdminUser();

        $this->saveSetting('theme.custom_css', 'Custom CSS', 'body { color: red; }');

        $response = $this->actingAs($admin)->put('/admin/content/settings', $this->validPayload([
            'custom_css' => null,
        ]));

        $response->assertRedirect();
        $this->assertNull(Setting::get('theme.custom_css'));
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
}
