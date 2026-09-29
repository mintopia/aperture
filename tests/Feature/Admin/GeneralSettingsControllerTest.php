<?php

declare(strict_types=1);

namespace Tests\Feature\Admin;

use App\Models\AuditLog;
use App\Models\Page;
use App\Models\Role;
use App\Models\Setting;
use App\Models\User;
use Illuminate\Foundation\Testing\LazilyRefreshDatabase;
use Illuminate\Support\Facades\Queue;
use PHPUnit\Framework\Attributes\DataProvider;
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

    #[DataProvider('termsTypeProvider')]
    public function test_admin_can_save_terms(string $termsType, string $termsValue): void
    {
        Queue::fake();
        $admin = $this->createAdminUser();

        $response = $this->actingAs($admin)->put('/admin/content/settings', $this->validPayload([
            'terms_type' => $termsType,
            'terms_value' => $termsValue,
        ]));

        $response->assertRedirect();
        $this->assertEquals($termsType, Setting::get('general.terms_type'));
        $this->assertEquals($termsValue, Setting::get('general.terms_value'));
    }

    public static function termsTypeProvider(): array
    {
        return [
            'page' => ['page', 'terms-of-service'],
            'url' => ['url', 'https://example.com/terms'],
        ];
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

    #[DataProvider('invalidSettingsPayloadProvider')]
    public function test_settings_update_rejects_invalid_field(array $overrides, string $expectedErrorField): void
    {
        Queue::fake();
        $admin = $this->createAdminUser();

        $response = $this->actingAs($admin)->put('/admin/content/settings', $this->validPayload($overrides));

        $response->assertSessionHasErrors($expectedErrorField);
    }

    public static function invalidSettingsPayloadProvider(): array
    {
        return [
            'empty site title' => [['site_title' => ''], 'site_title'],
            'invalid terms type' => [['terms_type' => 'invalid'], 'terms_type'],
            'invalid theme mode' => [['theme_mode' => 'invalid-mode'], 'theme_mode'],
            'accent hue below range' => [['accent_hue' => -1], 'accent_hue'],
            'accent hue above range' => [['accent_hue' => 500], 'accent_hue'],
            'accent chroma above range' => [['accent_chroma' => 0.5], 'accent_chroma'],
            'accent lightness above range' => [['accent_lightness' => 100], 'accent_lightness'],
            'custom css exceeds max length' => [['custom_css' => str_repeat('a', 10001)], 'custom_css'],
            'custom css rejects script tags' => [['custom_css' => 'body { color: red; } <script>alert("xss")</script>'], 'custom_css'],
        ];
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

    public function test_settings_update_creates_audit_log(): void
    {
        Queue::fake();
        $admin = $this->createAdminUser();

        $this->actingAs($admin)->put('/admin/content/settings', $this->validPayload([
            'site_title' => 'Audit Test Portal',
        ]));

        $this->assertTrue(AuditLog::where('action', 'settings.updated')->exists());
        $log = AuditLog::where('action', 'settings.updated')->first();
        $this->assertNotNull($log);
        $this->assertEquals('admin', $log->process);
        $this->assertNull($log->subject_type);
        $this->assertNull($log->subject_id);
        $this->assertEquals('general', $log->metadata['setting_group']);
        $this->assertArrayHasKey('ip', $log->metadata);
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

    public function test_custom_css_style_breakout_is_rejected(): void
    {
        $admin = $this->createAdminUser();

        foreach (['</style><script>alert(1)</script>', 'a{} </STYLE >', 'a{} <img src=x>'] as $payload) {
            $response = $this->actingAs($admin)->put('/admin/content/settings', $this->validPayload([
                'custom_css' => $payload,
            ]));

            $response->assertSessionHasErrors('custom_css');
        }

        $this->assertNull(Setting::get('theme.custom_css'));
    }

    public function test_stored_breakout_css_is_neutralised_at_render(): void
    {
        Setting::set('theme.custom_css', 'Custom CSS', 'body{color:red}</style><script>alert(1)</script>');

        $response = $this->get('/login');

        $response->assertDontSee('<script>alert(1)</script>', false);
        $response->assertDontSee('</style><script>', false);
    }
}
