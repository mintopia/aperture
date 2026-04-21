<?php

namespace Tests\Feature;

use App\Models\IntegrationConfig;
use App\Models\User;
use App\Services\Ipv6JwtService;
use Firebase\JWT\SignatureInvalidException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Queue;
use Mockery;
use Tests\TestCase;

class PortalControllerTest extends TestCase
{
    use RefreshDatabase;

    public function test_index_creates_ip_and_renders_view(): void
    {
        Queue::fake();
        $user = User::factory()->create(['blocked' => false]);

        $response = $this->actingAs($user)->get('/');
        $response->assertStatus(200);
    }

    public function test_index_does_not_allow_when_user_blocked(): void
    {
        Queue::fake();
        $user = User::factory()->create(['blocked' => true]);

        $response = $this->actingAs($user)->get('/');
        $response->assertStatus(200);
    }

    public function test_status_returns_json_with_ip_info(): void
    {
        $user = User::factory()->create();

        $response = $this->actingAs($user)->get('/status');
        $response->assertStatus(200);
        $response->assertJsonStructure(['ip', 'allowed']);
    }

    public function test_ipv6_verifies_jwt_and_registers_address(): void
    {
        Queue::fake();
        $user = User::factory()->create(['blocked' => false]);

        $jwtService = Mockery::mock(Ipv6JwtService::class);
        $jwtService->shouldReceive('verifyAndExtract')
            ->with('valid.jwt.token', 'https://ipv6.example.com/.well-known/jwks.json')
            ->andReturn('2001:db8::1');
        $this->app->instance(Ipv6JwtService::class, $jwtService);

        IntegrationConfig::setValue('ipv6', 'jwks_url', 'https://ipv6.example.com/.well-known/jwks.json');

        $response = $this->actingAs($user)->postJson('/ipv6', [
            'token' => 'valid.jwt.token',
        ]);

        $response->assertStatus(200);
        $response->assertJsonStructure(['ip', 'allowed']);
        $this->assertDatabaseHas('ip_addresses', ['address' => '2001:db8::1']);
    }

    public function test_ipv6_rejects_invalid_jwt(): void
    {
        Queue::fake();
        $user = User::factory()->create(['blocked' => false]);

        $jwtService = Mockery::mock(Ipv6JwtService::class);
        $jwtService->shouldReceive('verifyAndExtract')
            ->andThrow(new SignatureInvalidException('bad sig'));
        $this->app->instance(Ipv6JwtService::class, $jwtService);

        IntegrationConfig::setValue('ipv6', 'jwks_url', 'https://ipv6.example.com/.well-known/jwks.json');

        $response = $this->actingAs($user)->postJson('/ipv6', [
            'token' => 'invalid.jwt.token',
        ]);

        $response->assertStatus(422);
    }

    public function test_ipv6_returns_503_when_jwks_not_configured(): void
    {
        Queue::fake();
        $user = User::factory()->create(['blocked' => false]);

        $response = $this->actingAs($user)->postJson('/ipv6', [
            'token' => 'any.jwt.token',
        ]);

        $response->assertStatus(503);
    }

    public function test_ipv6_requires_token_field(): void
    {
        Queue::fake();
        $user = User::factory()->create(['blocked' => false]);

        $response = $this->actingAs($user)->postJson('/ipv6', []);

        $response->assertStatus(422);
        $response->assertJsonValidationErrors(['token']);
    }

    public function test_ipv6_does_not_allow_for_blocked_user(): void
    {
        Queue::fake();
        $user = User::factory()->create(['blocked' => true]);

        $jwtService = Mockery::mock(Ipv6JwtService::class);
        $jwtService->shouldReceive('verifyAndExtract')
            ->andReturn('2001:db8::2');
        $this->app->instance(Ipv6JwtService::class, $jwtService);

        IntegrationConfig::setValue('ipv6', 'jwks_url', 'https://ipv6.example.com/.well-known/jwks.json');

        $response = $this->actingAs($user)->postJson('/ipv6', [
            'token' => 'valid.jwt.token',
        ]);

        $response->assertStatus(200);
        $this->assertDatabaseHas('ip_addresses', ['address' => '2001:db8::2', 'allowed' => false]);
    }

    public function test_unauthenticated_user_redirects_to_captive(): void
    {
        $response = $this->get('/');
        $response->assertRedirect(route('captive.index'));
    }

    public function test_index_passes_ipv6_config_when_enabled(): void
    {
        Queue::fake();
        IntegrationConfig::setValue('ipv6', 'detection_enabled', '1');
        IntegrationConfig::setValue('ipv6', 'detection_endpoint', 'https://{random}.ipv6.example.com');
        $user = User::factory()->create(['blocked' => false]);

        $response = $this->actingAs($user)->get('/');
        $response->assertStatus(200);
        $response->assertViewHas('ipv6DetectionEnabled', true);
        $response->assertViewHas('ipv6DetectionEndpoint', 'https://{random}.ipv6.example.com');
    }

    public function test_index_passes_ipv6_config_when_disabled(): void
    {
        Queue::fake();
        $user = User::factory()->create(['blocked' => false]);

        $response = $this->actingAs($user)->get('/');
        $response->assertStatus(200);
        $response->assertViewHas('ipv6DetectionEnabled', false);
        $response->assertViewHas('ipv6DetectionEndpoint', '');
    }

    public function test_portal_uses_captive_layout(): void
    {
        Queue::fake();
        $user = User::factory()->create(['blocked' => false]);

        $response = $this->actingAs($user)->get('/');

        $response->assertOk();
        $response->assertSee('max-w-md');
    }

    public function test_portal_has_data_testid(): void
    {
        Queue::fake();
        $user = User::factory()->create(['blocked' => false]);

        $response = $this->actingAs($user)->get('/');

        $response->assertOk();
        $response->assertSee('data-testid="portal-page"', false);
    }

    public function test_portal_shows_blocked_message_with_data_testid(): void
    {
        Queue::fake();
        $user = User::factory()->create(['blocked' => true]);

        $response = $this->actingAs($user)->get('/');

        $response->assertOk();
        $response->assertSee('data-testid="portal-blocked"', false);
    }

    public function test_portal_shows_waiting_status_with_data_testid(): void
    {
        Queue::fake();
        $user = User::factory()->create(['blocked' => false]);

        $response = $this->actingAs($user)->get('/');

        $response->assertOk();
        $response->assertSee('data-testid="portal-status-waiting"', false);
    }

    public function test_portal_shows_ok_status_with_data_testid_when_allowed(): void
    {
        Queue::fake();
        $user = User::factory()->create(['blocked' => false]);

        $response = $this->actingAs($user)->get('/');

        $response->assertOk();
        $response->assertSee('data-testid="portal-status-ok"', false);
    }

    public function test_portal_shows_ip_address_with_data_testid(): void
    {
        Queue::fake();
        $user = User::factory()->create(['blocked' => false]);

        $response = $this->actingAs($user)->get('/');

        $response->assertOk();
        $response->assertSee('data-testid="portal-ip"', false);
    }

    public function test_portal_has_dashboard_link_with_data_testid_when_allowed(): void
    {
        Queue::fake();
        $user = User::factory()->create(['blocked' => false]);

        $response = $this->actingAs($user)->get('/');

        $response->assertOk();
        $response->assertSee('data-testid="portal-dashboard-link"', false);
    }

    public function test_portal_js_uses_hidden_class_not_d_none(): void
    {
        Queue::fake();
        $user = User::factory()->create(['blocked' => false]);

        $response = $this->actingAs($user)->get('/');

        $response->assertOk();
        $response->assertDontSee('d-none');
        $response->assertSee('hidden');
    }

    public function test_portal_dns_warning_uses_hidden_class(): void
    {
        Queue::fake();
        $user = User::factory()->create(['blocked' => false]);

        $response = $this->actingAs($user)->get('/');

        $response->assertOk();
        $response->assertSee('dns-warning');
        $response->assertDontSee('d-none');
    }
}
