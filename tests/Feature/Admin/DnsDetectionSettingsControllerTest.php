<?php

declare(strict_types=1);

namespace Tests\Feature\Admin;

use App\Models\Role;
use App\Models\Setting;
use App\Models\User;
use Illuminate\Foundation\Testing\LazilyRefreshDatabase;
use Illuminate\Support\Facades\Queue;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\TestCase;

class DnsDetectionSettingsControllerTest extends TestCase
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

    public function test_show_returns_settings_page_with_defaults(): void
    {
        $response = $this->actingAs($this->admin)->get('/admin/settings/dns-detection');

        $response->assertOk();
        $response->assertInertia(fn (Assert $page): Assert => $page
            ->component('Admin/Settings/DnsDetection')
            ->has('settings')
            ->where('settings.dns_check_url', '')
            ->where('settings.dns_warning_message', '')
        );
    }

    public function test_show_returns_existing_settings(): void
    {
        Setting::create(['code' => 'dns.check_url', 'name' => 'DNS Check URL', 'value' => 'https://{uuid}.lancache.test.entropylan.party']);
        Setting::create(['code' => 'dns.warning_message', 'name' => 'DNS Warning Message', 'value' => 'Fix your DNS!']);

        $response = $this->actingAs($this->admin)->get('/admin/settings/dns-detection');

        $response->assertOk();
        $response->assertInertia(fn (Assert $page): Assert => $page
            ->where('settings.dns_check_url', 'https://{uuid}.lancache.test.entropylan.party')
            ->where('settings.dns_warning_message', 'Fix your DNS!')
        );
    }

    public function test_update_saves_settings(): void
    {
        $response = $this->actingAs($this->admin)->put('/admin/settings/dns-detection', [
            'dns_check_url' => 'https://{uuid}.lancache.test.entropylan.party',
            'dns_warning_message' => 'Please use event DNS.',
        ]);

        $response->assertRedirect();
        $response->assertSessionHas('success');
        $this->assertSame('https://{uuid}.lancache.test.entropylan.party', Setting::get('dns.check_url'));
        $this->assertSame('Please use event DNS.', Setting::get('dns.warning_message'));
    }

    public function test_update_clears_settings_when_empty(): void
    {
        Setting::create(['code' => 'dns.check_url', 'name' => 'DNS Check URL', 'value' => 'https://{uuid}.example.com']);
        Setting::create(['code' => 'dns.warning_message', 'name' => 'DNS Warning Message', 'value' => 'Old message']);

        $response = $this->actingAs($this->admin)->put('/admin/settings/dns-detection', [
            'dns_check_url' => '',
            'dns_warning_message' => '',
        ]);

        $response->assertRedirect();
        $this->assertNull(Setting::get('dns.check_url'));
        $this->assertNull(Setting::get('dns.warning_message'));
    }

    public function test_update_rejects_url_without_uuid_placeholder(): void
    {
        $response = $this->actingAs($this->admin)->put('/admin/settings/dns-detection', [
            'dns_check_url' => 'https://lancache.test.entropylan.party',
            'dns_warning_message' => 'Fix your DNS.',
        ]);

        $response->assertSessionHasErrors('dns_check_url');
    }

    public function test_update_rejects_warning_message_over_500_chars(): void
    {
        $response = $this->actingAs($this->admin)->put('/admin/settings/dns-detection', [
            'dns_check_url' => 'https://{uuid}.lancache.test.entropylan.party',
            'dns_warning_message' => str_repeat('a', 501),
        ]);

        $response->assertSessionHasErrors('dns_warning_message');
    }

    public function test_update_accepts_url_with_uuid_placeholder(): void
    {
        $response = $this->actingAs($this->admin)->put('/admin/settings/dns-detection', [
            'dns_check_url' => 'https://{uuid}.lancache.test.entropylan.party',
            'dns_warning_message' => '',
        ]);

        $response->assertRedirect();
        $response->assertSessionDoesntHaveErrors();
    }

    public function test_requires_admin(): void
    {
        $user = User::factory()->create();

        $this->actingAs($user)->get('/admin/settings/dns-detection')->assertForbidden();
        $this->actingAs($user)->put('/admin/settings/dns-detection', [])->assertForbidden();
    }
}
