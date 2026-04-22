<?php

declare(strict_types=1);

namespace Tests\Feature\Admin;

use App\Models\Page;
use App\Models\Role;
use App\Models\Setting;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Queue;
use Tests\TestCase;

class GeneralSettingsControllerTest extends TestCase
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

        $response = $this->actingAs($admin)->put('/admin/content/settings', [
            'site_title' => 'My Network Portal',
            'dns_filtering_default' => false,
            'terms_type' => 'url',
            'terms_value' => null,
            'privacy_type' => 'url',
            'privacy_value' => null,
        ]);

        $response->assertRedirect();
        $this->assertEquals('My Network Portal', Setting::get('general.site_title'));
    }

    public function test_admin_can_save_dns_filtering_default(): void
    {
        Queue::fake();
        $admin = $this->createAdminUser();

        $response = $this->actingAs($admin)->put('/admin/content/settings', [
            'site_title' => 'Portal',
            'dns_filtering_default' => true,
            'terms_type' => 'url',
            'terms_value' => null,
            'privacy_type' => 'url',
            'privacy_value' => null,
        ]);

        $response->assertRedirect();
        $this->assertEquals('1', Setting::get('general.dns_filtering_default'));
    }

    public function test_admin_can_save_terms_as_page(): void
    {
        Queue::fake();
        $admin = $this->createAdminUser();

        $response = $this->actingAs($admin)->put('/admin/content/settings', [
            'site_title' => 'Portal',
            'dns_filtering_default' => false,
            'terms_type' => 'page',
            'terms_value' => 'terms-of-service',
            'privacy_type' => 'url',
            'privacy_value' => null,
        ]);

        $response->assertRedirect();
        $this->assertEquals('page', Setting::get('general.terms_type'));
        $this->assertEquals('terms-of-service', Setting::get('general.terms_value'));
    }

    public function test_admin_can_save_terms_as_url(): void
    {
        Queue::fake();
        $admin = $this->createAdminUser();

        $response = $this->actingAs($admin)->put('/admin/content/settings', [
            'site_title' => 'Portal',
            'dns_filtering_default' => false,
            'terms_type' => 'url',
            'terms_value' => 'https://example.com/terms',
            'privacy_type' => 'url',
            'privacy_value' => null,
        ]);

        $response->assertRedirect();
        $this->assertEquals('url', Setting::get('general.terms_type'));
        $this->assertEquals('https://example.com/terms', Setting::get('general.terms_value'));
    }

    public function test_site_title_is_required(): void
    {
        Queue::fake();
        $admin = $this->createAdminUser();

        $response = $this->actingAs($admin)->put('/admin/content/settings', [
            'site_title' => '',
            'dns_filtering_default' => false,
            'terms_type' => 'url',
            'terms_value' => null,
            'privacy_type' => 'url',
            'privacy_value' => null,
        ]);

        $response->assertSessionHasErrors('site_title');
    }

    public function test_terms_type_must_be_valid(): void
    {
        Queue::fake();
        $admin = $this->createAdminUser();

        $response = $this->actingAs($admin)->put('/admin/content/settings', [
            'site_title' => 'Portal',
            'dns_filtering_default' => false,
            'terms_type' => 'invalid',
            'terms_value' => null,
            'privacy_type' => 'url',
            'privacy_value' => null,
        ]);

        $response->assertSessionHasErrors('terms_type');
    }

    public function test_non_admin_cannot_access_general_settings(): void
    {
        Queue::fake();
        $user = User::factory()->create();

        $this->actingAs($user)->get('/admin/content/settings')->assertForbidden();
        $this->actingAs($user)->put('/admin/content/settings', [
            'site_title' => 'Portal',
            'dns_filtering_default' => false,
            'terms_type' => 'url',
            'terms_value' => null,
            'privacy_type' => 'url',
            'privacy_value' => null,
        ])->assertForbidden();
    }

    public function test_settings_page_returns_current_settings_values(): void
    {
        Queue::fake();
        $admin = $this->createAdminUser();

        $this->saveSetting('general.site_title', 'Site Title', 'My Portal');
        $this->saveSetting('general.dns_filtering_default', 'DNS Filtering Default', '1');
        $this->saveSetting('general.terms_type', 'Terms Type', 'page');
        $this->saveSetting('general.terms_value', 'Terms Value', 'terms');

        $response = $this->actingAs($admin)->get('/admin/content/settings');

        $response->assertOk();
        $response->assertInertia(fn ($page) => $page
            ->component('Admin/Content/Settings')
            ->has('settings')
            ->where('settings.site_title', 'My Portal')
            ->where('settings.dns_filtering_default', true)
            ->where('settings.terms_type', 'page')
            ->where('settings.terms_value', 'terms')
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
}
