<?php

declare(strict_types=1);

namespace Tests\Feature\Admin;

use App\Models\Role;
use App\Models\Setting;
use App\Models\User;
use Illuminate\Foundation\Testing\LazilyRefreshDatabase;
use Illuminate\Support\Facades\Queue;
use Inertia\Testing\AssertableInertia as Assert;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

class CaptivePortalApiSettingsControllerTest extends TestCase
{
    use LazilyRefreshDatabase;

    private User $admin;

    protected function setUp(): void
    {
        parent::setUp();
        Queue::fake();
        $this->admin = $this->createAdminUser();
    }

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

    #[Test]
    public function settings_page_loads(): void
    {
        $response = $this->actingAs($this->admin)->get('/admin/settings/captive-portal-api');

        $response->assertOk();
        $response->assertInertia(fn (Assert $page): Assert => $page
            ->component('Admin/Settings/CaptivePortalApi')
        );
    }

    #[Test]
    public function settings_page_shows_defaults(): void
    {
        $response = $this->actingAs($this->admin)->get('/admin/settings/captive-portal-api');

        $response->assertOk();
        $response->assertInertia(fn (Assert $page): Assert => $page
            ->where('settings.user_portal_url', '')
            ->where('settings.venue_info_url', '')
            ->where('settings.can_extend_session', false)
            ->has('apiUrl')
        );
    }

    #[Test]
    public function settings_page_shows_saved_values(): void
    {
        Setting::set('captive_portal_api.user_portal_url', 'User Portal URL', 'https://portal.example.com');
        Setting::set('captive_portal_api.venue_info_url', 'Venue Info URL', 'https://venue.example.com');
        Setting::set('captive_portal_api.can_extend_session', 'Can Extend Session', '1');

        $response = $this->actingAs($this->admin)->get('/admin/settings/captive-portal-api');

        $response->assertOk();
        $response->assertInertia(fn (Assert $page): Assert => $page
            ->where('settings.user_portal_url', 'https://portal.example.com')
            ->where('settings.venue_info_url', 'https://venue.example.com')
            ->where('settings.can_extend_session', true)
        );
    }

    #[Test]
    public function settings_can_be_updated(): void
    {
        $response = $this->actingAs($this->admin)->put('/admin/settings/captive-portal-api', [
            'user_portal_url' => 'https://portal.example.com/captive',
            'venue_info_url' => 'https://example.com/about',
            'can_extend_session' => true,
        ]);

        $response->assertRedirect();
        $response->assertSessionHas('success');

        $this->assertEquals('https://portal.example.com/captive', Setting::get('captive_portal_api.user_portal_url'));
        $this->assertEquals('https://example.com/about', Setting::get('captive_portal_api.venue_info_url'));
        $this->assertEquals('1', Setting::get('captive_portal_api.can_extend_session'));
    }

    #[Test]
    public function settings_can_be_cleared(): void
    {
        Setting::set('captive_portal_api.user_portal_url', 'User Portal URL', 'https://old.example.com');

        $response = $this->actingAs($this->admin)->put('/admin/settings/captive-portal-api', [
            'user_portal_url' => null,
            'venue_info_url' => null,
            'can_extend_session' => false,
        ]);

        $response->assertRedirect();
        $this->assertEquals('', Setting::get('captive_portal_api.user_portal_url'));
        $this->assertEquals('0', Setting::get('captive_portal_api.can_extend_session'));
    }

    #[Test]
    public function validation_rejects_invalid_urls(): void
    {
        $response = $this->actingAs($this->admin)->put('/admin/settings/captive-portal-api', [
            'user_portal_url' => 'not-a-url',
            'venue_info_url' => 'also-not-a-url',
            'can_extend_session' => false,
        ]);

        $response->assertSessionHasErrors(['user_portal_url', 'venue_info_url']);
    }

    #[Test]
    public function non_admin_cannot_access_settings(): void
    {
        $user = User::factory()->create();

        $this->actingAs($user)->get('/admin/settings/captive-portal-api')->assertForbidden();
        $this->actingAs($user)->put('/admin/settings/captive-portal-api', [
            'can_extend_session' => false,
        ])->assertForbidden();
    }

    #[Test]
    public function guest_cannot_access_settings(): void
    {
        $this->get('/admin/settings/captive-portal-api')->assertRedirect();
    }

    #[Test]
    public function api_url_is_provided(): void
    {
        $response = $this->actingAs($this->admin)->get('/admin/settings/captive-portal-api');

        $response->assertInertia(fn (Assert $page): Assert => $page
            ->where('apiUrl', url('/api/captive-portal'))
        );
    }
}
