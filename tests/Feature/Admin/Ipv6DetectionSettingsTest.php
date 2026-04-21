<?php

declare(strict_types=1);

namespace Tests\Feature\Admin;

use App\Models\IntegrationConfig;
use App\Models\Role;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Queue;
use Tests\TestCase;

class Ipv6DetectionSettingsTest extends TestCase
{
    use RefreshDatabase;

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

    public function test_show_returns_settings_page(): void
    {
        $response = $this->actingAs($this->admin)->get('/admin/settings/ipv6-detection');

        $response->assertStatus(200);
        $response->assertInertia(fn ($page) => $page
            ->component('Admin/Settings/Ipv6Detection')
            ->has('settings')
            ->where('settings.detection_enabled', false)
            ->where('settings.detection_endpoint', '')
            ->where('settings.jwks_url', '')
        );
    }

    public function test_show_returns_existing_config(): void
    {
        IntegrationConfig::setValue('ipv6', 'detection_enabled', '1');
        IntegrationConfig::setValue('ipv6', 'detection_endpoint', 'https://{random}.ipv6.example.com');
        IntegrationConfig::setValue('ipv6', 'jwks_url', 'https://ipv6.example.com/.well-known/jwks.json');

        $response = $this->actingAs($this->admin)->get('/admin/settings/ipv6-detection');

        $response->assertStatus(200);
        $response->assertInertia(fn ($page) => $page
            ->where('settings.detection_enabled', true)
            ->where('settings.detection_endpoint', 'https://{random}.ipv6.example.com')
            ->where('settings.jwks_url', 'https://ipv6.example.com/.well-known/jwks.json')
        );
    }

    public function test_update_saves_settings(): void
    {
        $response = $this->actingAs($this->admin)->put('/admin/settings/ipv6-detection', [
            'detection_enabled' => true,
            'detection_endpoint' => 'https://{random}.ipv6.test.com',
            'jwks_url' => 'https://ipv6.test.com/.well-known/jwks.json',
        ]);

        $response->assertRedirect();
        $response->assertSessionHas('success');

        $config = IntegrationConfig::getAll('ipv6');
        $this->assertSame('1', $config['detection_enabled']);
        $this->assertSame('https://{random}.ipv6.test.com', $config['detection_endpoint']);
        $this->assertSame('https://ipv6.test.com/.well-known/jwks.json', $config['jwks_url']);
    }

    public function test_update_validates_urls(): void
    {
        $response = $this->actingAs($this->admin)->put('/admin/settings/ipv6-detection', [
            'detection_enabled' => true,
            'detection_endpoint' => 'not-a-url',
            'jwks_url' => 'also-not-a-url',
        ]);

        $response->assertSessionHasErrors(['detection_endpoint', 'jwks_url']);
    }

    public function test_update_rejects_http_urls(): void
    {
        $response = $this->actingAs($this->admin)->put('/admin/settings/ipv6-detection', [
            'detection_enabled' => true,
            'detection_endpoint' => 'http://{random}.ipv6.test.com',
            'jwks_url' => 'http://ipv6.test.com/.well-known/jwks.json',
        ]);

        $response->assertSessionHasErrors(['detection_endpoint', 'jwks_url']);
    }

    public function test_requires_admin(): void
    {
        $user = User::factory()->create();

        $this->actingAs($user)->get('/admin/settings/ipv6-detection')->assertForbidden();
        $this->actingAs($user)->put('/admin/settings/ipv6-detection', [])->assertForbidden();
    }
}
