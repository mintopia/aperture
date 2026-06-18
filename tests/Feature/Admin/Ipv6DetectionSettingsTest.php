<?php

declare(strict_types=1);

namespace Tests\Feature\Admin;

use App\Models\IntegrationConfig;
use App\Models\Role;
use App\Models\User;
use Illuminate\Foundation\Testing\LazilyRefreshDatabase;
use Illuminate\Support\Facades\Queue;
use Tests\TestCase;

class Ipv6DetectionSettingsTest extends TestCase
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

    public function test_show_returns_settings_page(): void
    {
        $response = $this->actingAs($this->admin)->get('/admin/settings/ipv6-detection');

        $response->assertStatus(200);
        $response->assertInertia(fn ($page) => $page
            ->component('Admin/Settings/Ipv6Detection')
            ->has('settings')
            ->where('settings.detection_endpoint', '')
            ->where('settings.jwks_url', '')
        );
    }

    public function test_show_returns_existing_config(): void
    {
        IntegrationConfig::setValue('ipv6', 'detection_endpoint', 'https://{uuid}.ipv6.example.com');
        IntegrationConfig::setValue('ipv6', 'jwks_url', 'https://ipv6.example.com/.well-known/jwks.json');

        $response = $this->actingAs($this->admin)->get('/admin/settings/ipv6-detection');

        $response->assertStatus(200);
        $response->assertInertia(fn ($page) => $page
            ->where('settings.detection_endpoint', 'https://{uuid}.ipv6.example.com')
            ->where('settings.jwks_url', 'https://ipv6.example.com/.well-known/jwks.json')
        );
    }

    public function test_show_does_not_return_detection_enabled(): void
    {
        $response = $this->actingAs($this->admin)->get('/admin/settings/ipv6-detection');

        $response->assertStatus(200);
        $response->assertInertia(fn ($page) => $page
            ->component('Admin/Settings/Ipv6Detection')
            ->missing('settings.detection_enabled')
        );
    }

    public function test_update_saves_settings(): void
    {
        $response = $this->actingAs($this->admin)->put('/admin/settings/ipv6-detection', [
            'detection_endpoint' => 'https://{uuid}.ipv6.test.com',
            'jwks_url' => 'https://ipv6.test.com/.well-known/jwks.json',
        ]);

        $response->assertRedirect();
        $response->assertSessionHas('success');

        $config = IntegrationConfig::getAll('ipv6');
        $this->assertSame('https://{uuid}.ipv6.test.com', $config['detection_endpoint']);
        $this->assertSame('https://ipv6.test.com/.well-known/jwks.json', $config['jwks_url']);
    }

    public function test_update_does_not_save_detection_enabled(): void
    {
        $this->actingAs($this->admin)->put('/admin/settings/ipv6-detection', [
            'detection_endpoint' => 'https://{uuid}.ipv6.test.com',
            'jwks_url' => 'https://ipv6.test.com/.well-known/jwks.json',
        ]);

        $config = IntegrationConfig::getAll('ipv6');
        $this->assertArrayNotHasKey('detection_enabled', $config);
    }

    public function test_update_validates_urls(): void
    {
        $response = $this->actingAs($this->admin)->put('/admin/settings/ipv6-detection', [
            'detection_endpoint' => 'not-a-url',
            'jwks_url' => 'also-not-a-url',
        ]);

        $response->assertSessionHasErrors(['detection_endpoint', 'jwks_url']);
    }

    public function test_update_rejects_http_urls(): void
    {
        $response = $this->actingAs($this->admin)->put('/admin/settings/ipv6-detection', [
            'detection_endpoint' => 'http://{uuid}.ipv6.test.com',
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

    public function test_show_returns_jwt_audience_and_issuer_settings(): void
    {
        IntegrationConfig::setValue('ipv6', 'jwt_audience', 'aperture');
        IntegrationConfig::setValue('ipv6', 'jwt_issuer', 'borealis');

        $response = $this->actingAs($this->admin)->get('/admin/settings/ipv6-detection');

        $response->assertStatus(200);
        $response->assertInertia(fn ($page) => $page
            ->where('settings.jwt_audience', 'aperture')
            ->where('settings.jwt_issuer', 'borealis')
        );
    }

    public function test_show_returns_empty_jwt_audience_and_issuer_when_not_set(): void
    {
        $response = $this->actingAs($this->admin)->get('/admin/settings/ipv6-detection');

        $response->assertStatus(200);
        $response->assertInertia(fn ($page) => $page
            ->where('settings.jwt_audience', '')
            ->where('settings.jwt_issuer', '')
        );
    }

    public function test_update_saves_jwt_audience_and_issuer(): void
    {
        $response = $this->actingAs($this->admin)->put('/admin/settings/ipv6-detection', [
            'detection_endpoint' => 'https://{uuid}.ipv6.test.com',
            'jwks_url' => 'https://ipv6.test.com/.well-known/jwks.json',
            'jwt_audience' => 'aperture',
            'jwt_issuer' => 'borealis',
        ]);

        $response->assertRedirect();
        $response->assertSessionHas('success');

        $config = IntegrationConfig::getAll('ipv6');
        $this->assertSame('aperture', $config['jwt_audience']);
        $this->assertSame('borealis', $config['jwt_issuer']);
    }

    public function test_update_allows_empty_jwt_audience_and_issuer(): void
    {
        $response = $this->actingAs($this->admin)->put('/admin/settings/ipv6-detection', [
            'detection_endpoint' => 'https://{uuid}.ipv6.test.com',
            'jwks_url' => 'https://ipv6.test.com/.well-known/jwks.json',
            'jwt_audience' => '',
            'jwt_issuer' => '',
        ]);

        $response->assertRedirect();
        $response->assertSessionHas('success');
    }

    public function test_update_validates_jwt_audience_max_length(): void
    {
        $response = $this->actingAs($this->admin)->put('/admin/settings/ipv6-detection', [
            'jwt_audience' => str_repeat('a', 256),
        ]);

        $response->assertSessionHasErrors('jwt_audience');
    }

    public function test_update_validates_jwt_issuer_max_length(): void
    {
        $response = $this->actingAs($this->admin)->put('/admin/settings/ipv6-detection', [
            'jwt_issuer' => str_repeat('a', 256),
        ]);

        $response->assertSessionHasErrors('jwt_issuer');
    }
}
