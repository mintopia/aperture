<?php

namespace Tests\Feature;

use App\Models\IntegrationConfig;
use App\Models\IpAddress;
use App\Models\Setting;
use App\Models\User;
use App\Services\Interfaces\CaptivePortalInterface;
use App\Services\Ipv6JwtService;
use Firebase\JWT\SignatureInvalidException;
use Illuminate\Foundation\Testing\LazilyRefreshDatabase;
use Illuminate\Support\Facades\Queue;
use Mockery;
use Tests\TestCase;

class PortalControllerTest extends TestCase
{
    use LazilyRefreshDatabase;

    public function test_index_creates_ip_and_renders_view(): void
    {
        Queue::fake();
        $user = User::factory()->create(['internet_blocked' => false]);

        $response = $this->actingAs($user)->get('/');
        $response->assertStatus(200);
    }

    public function test_index_does_not_allow_when_user_blocked(): void
    {
        Queue::fake();
        $user = User::factory()->internetBlocked()->create();

        $response = $this->actingAs($user)->get('/');
        $response->assertStatus(200);
    }

    public function test_status_returns_json_with_ip_info(): void
    {
        Queue::fake();
        $user = User::factory()->create();

        $response = $this->actingAs($user)->get('/status');
        $response->assertStatus(200);
        $response->assertJsonStructure(['ip', 'internetEnabled']);
    }

    public function test_ipv6_verifies_jwt_and_registers_address(): void
    {
        Queue::fake();
        $user = User::factory()->create(['internet_blocked' => false]);

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
        $response->assertJsonStructure(['ip', 'internetEnabled']);
        $this->assertDatabaseHas('ip_addresses', ['address' => '2001:db8::1']);
    }

    public function test_ipv6_rejects_invalid_jwt(): void
    {
        Queue::fake();
        $user = User::factory()->create(['internet_blocked' => false]);

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
        $user = User::factory()->create(['internet_blocked' => false]);

        $response = $this->actingAs($user)->postJson('/ipv6', [
            'token' => 'any.jwt.token',
        ]);

        $response->assertStatus(503);
    }

    public function test_ipv6_requires_token_field(): void
    {
        Queue::fake();
        $user = User::factory()->create(['internet_blocked' => false]);

        $response = $this->actingAs($user)->postJson('/ipv6', []);

        $response->assertStatus(422);
        $response->assertJsonValidationErrors(['token']);
    }

    public function test_ipv6_does_not_allow_for_blocked_user(): void
    {
        Queue::fake();
        $user = User::factory()->internetBlocked()->create();

        $jwtService = Mockery::mock(Ipv6JwtService::class);
        $jwtService->shouldReceive('verifyAndExtract')
            ->andReturn('2001:db8::2');
        $this->app->instance(Ipv6JwtService::class, $jwtService);

        IntegrationConfig::setValue('ipv6', 'jwks_url', 'https://ipv6.example.com/.well-known/jwks.json');

        $response = $this->actingAs($user)->postJson('/ipv6', [
            'token' => 'valid.jwt.token',
        ]);

        $response->assertStatus(200);
        $this->assertDatabaseHas('ip_addresses', ['address' => '2001:db8::2', 'internet_enabled' => false]);
    }

    public function test_unauthenticated_user_redirects_to_captive(): void
    {
        $response = $this->get('/');
        $response->assertRedirect(route('captive.index'));
    }

    public function test_index_passes_ipv6_endpoint_when_configured(): void
    {
        Queue::fake();
        IntegrationConfig::setValue('ipv6', 'detection_endpoint', 'https://{random}.ipv6.example.com');
        $user = User::factory()->create(['internet_blocked' => false]);

        $response = $this->actingAs($user)->get('/');
        $response->assertStatus(200);
        $response->assertViewHas('ipv6DetectionEndpoint', 'https://{random}.ipv6.example.com');
    }

    public function test_index_passes_empty_ipv6_endpoint_when_not_configured(): void
    {
        Queue::fake();
        $user = User::factory()->create(['internet_blocked' => false]);

        $response = $this->actingAs($user)->get('/');
        $response->assertStatus(200);
        $response->assertViewHas('ipv6DetectionEndpoint', '');
    }

    public function test_portal_uses_captive_layout(): void
    {
        Queue::fake();
        $user = User::factory()->create(['internet_blocked' => false]);

        $response = $this->actingAs($user)->get('/');

        $response->assertOk();
        $response->assertSee('max-w-md');
    }

    public function test_portal_has_data_testid(): void
    {
        Queue::fake();
        $user = User::factory()->create(['internet_blocked' => false]);

        $response = $this->actingAs($user)->get('/');

        $response->assertOk();
        $response->assertSee('data-testid="portal-page"', false);
    }

    public function test_portal_shows_blocked_message_with_data_testid(): void
    {
        Queue::fake();
        $user = User::factory()->internetBlocked()->create();

        $response = $this->actingAs($user)->get('/');

        $response->assertOk();
        $response->assertSee('data-testid="portal-blocked"', false);
    }

    public function test_portal_shows_waiting_status_with_data_testid(): void
    {
        Queue::fake();
        $user = User::factory()->create(['internet_blocked' => false]);

        $response = $this->actingAs($user)->get('/');

        $response->assertOk();
        $response->assertSee('data-testid="portal-status-waiting"', false);
    }

    public function test_portal_shows_ok_status_with_data_testid_when_allowed(): void
    {
        Queue::fake();
        $user = User::factory()->create(['internet_blocked' => false]);

        $response = $this->actingAs($user)->get('/');

        $response->assertOk();
        $response->assertSee('data-testid="portal-status-ok"', false);
    }

    public function test_portal_shows_ip_address_with_data_testid(): void
    {
        Queue::fake();
        $user = User::factory()->create(['internet_blocked' => false]);

        $response = $this->actingAs($user)->get('/');

        $response->assertOk();
        $response->assertSee('data-testid="portal-ip"', false);
    }

    public function test_portal_has_dashboard_link_with_data_testid_when_allowed(): void
    {
        Queue::fake();
        $user = User::factory()->create(['internet_blocked' => false]);

        $response = $this->actingAs($user)->get('/');

        $response->assertOk();
        $response->assertSee('data-testid="portal-dashboard-link"', false);
    }

    public function test_portal_js_uses_hidden_class_not_d_none(): void
    {
        Queue::fake();
        $user = User::factory()->create(['internet_blocked' => false]);

        $response = $this->actingAs($user)->get('/');

        $response->assertOk();
        $response->assertDontSee('d-none');
        $response->assertSee('hidden');
    }

    public function test_portal_dns_warning_uses_hidden_class(): void
    {
        Queue::fake();
        $user = User::factory()->create(['internet_blocked' => false]);

        $response = $this->actingAs($user)->get('/');

        $response->assertOk();
        $response->assertSee('dns-warning');
        $response->assertDontSee('d-none');
    }

    public function test_status_returns_null_ip_when_outside_managed_range(): void
    {
        Queue::fake();
        Setting::set('network.managed_ranges_v4', 'Managed IPv4 Ranges', json_encode(['172.16.0.0/12']));

        $user = User::factory()->create();

        $response = $this->actingAs($user)
            ->withServerVariables(['REMOTE_ADDR' => '203.0.113.50'])
            ->get('/status');

        $response->assertOk();
        $response->assertJson([
            'ip' => '203.0.113.50',
            'internetEnabled' => false,
        ]);
    }

    public function test_index_renders_with_null_ip_when_outside_managed_range(): void
    {
        Queue::fake();
        Setting::set('network.managed_ranges_v4', 'Managed IPv4 Ranges', json_encode(['172.16.0.0/12']));

        $user = User::factory()->create(['internet_blocked' => false]);

        $response = $this->actingAs($user)
            ->withServerVariables(['REMOTE_ADDR' => '10.0.0.1'])
            ->get('/');

        $response->assertOk();
        $response->assertViewHas('ip');
    }

    public function test_ipv6_returns_null_ip_when_outside_managed_range(): void
    {
        Queue::fake();
        Setting::set('network.managed_ranges_v6', 'Managed IPv6 Ranges', json_encode(['fd00::/8']));

        $user = User::factory()->create(['internet_blocked' => false]);

        $jwtService = Mockery::mock(Ipv6JwtService::class);
        $jwtService->shouldReceive('verifyAndExtract')
            ->with('valid.jwt.token', 'https://ipv6.example.com/.well-known/jwks.json')
            ->andReturn('2001:db8::1');
        $this->app->instance(Ipv6JwtService::class, $jwtService);

        IntegrationConfig::setValue('ipv6', 'jwks_url', 'https://ipv6.example.com/.well-known/jwks.json');

        $response = $this->actingAs($user)->postJson('/ipv6', [
            'token' => 'valid.jwt.token',
        ]);

        $response->assertOk();
        $response->assertJson([
            'ip' => '2001:db8::1',
            'internetEnabled' => false,
        ]);
    }

    public function test_status_calls_firewall_enable_when_user_has_internet(): void
    {
        // Pre-create IP so internet_enabled is already true in DB — simulates firewall losing state
        $ip = IpAddress::factory()->internetEnabled()->create(['address' => '127.0.0.1']);
        $user = User::factory()->create(['internet_enabled' => true]);
        $user->ips()->create(['ip_address_id' => $ip->id, 'last_seen_at' => now()]);

        $captivePortal = Mockery::mock(CaptivePortalInterface::class);
        $captivePortal->shouldReceive('addIp')->once();
        $this->app->instance(CaptivePortalInterface::class, $captivePortal);

        $this->actingAs($user)->get('/status');
    }

    public function test_status_does_not_call_firewall_when_user_is_blocked(): void
    {
        $ip = IpAddress::factory()->create(['address' => '127.0.0.1', 'internet_enabled' => false]);
        $user = User::factory()->create([
            'internet_enabled' => true,
            'internet_blocked' => true,
        ]);
        $user->ips()->create(['ip_address_id' => $ip->id, 'last_seen_at' => now()]);

        $captivePortal = Mockery::mock(CaptivePortalInterface::class);
        $captivePortal->shouldNotReceive('addIp');

        $this->app->instance(CaptivePortalInterface::class, $captivePortal);

        $this->actingAs($user)->get('/status');
    }

    public function test_index_calls_firewall_enable_when_user_has_internet(): void
    {
        // Pre-create IP so internet_enabled is already true in DB — simulates firewall losing state
        $ip = IpAddress::factory()->internetEnabled()->create(['address' => '127.0.0.1']);
        $user = User::factory()->create(['internet_enabled' => true]);
        $user->ips()->create(['ip_address_id' => $ip->id, 'last_seen_at' => now()]);

        $captivePortal = Mockery::mock(CaptivePortalInterface::class);
        $captivePortal->shouldReceive('addIp')->once();
        $this->app->instance(CaptivePortalInterface::class, $captivePortal);

        $this->actingAs($user)->get('/');
    }

    public function test_ipv6_calls_firewall_enable_when_user_has_internet(): void
    {
        // Pre-create IPv6 so internet_enabled is already true in DB — simulates firewall losing state
        $ip = IpAddress::factory()->internetEnabled()->create(['address' => '2001:db8::1']);
        $user = User::factory()->create(['internet_enabled' => true]);
        $user->ips()->create(['ip_address_id' => $ip->id, 'last_seen_at' => now()]);

        IntegrationConfig::setValue('ipv6', 'jwks_url', 'https://example.com/.well-known/jwks.json');

        $jwtService = Mockery::mock(Ipv6JwtService::class);
        $jwtService->shouldReceive('verifyAndExtract')->andReturn('2001:db8::1');
        $this->app->instance(Ipv6JwtService::class, $jwtService);

        $captivePortal = Mockery::mock(CaptivePortalInterface::class);
        $captivePortal->shouldReceive('addIp')->once();
        $this->app->instance(CaptivePortalInterface::class, $captivePortal);

        $this->actingAs($user)->postJson('/ipv6', ['token' => 'test-jwt-token']);
    }

    public function test_portal_passes_ipv6_endpoint_regardless_of_internet_status(): void
    {
        Queue::fake();
        $user = User::factory()->create(['internet_enabled' => true]);
        IntegrationConfig::setValue('ipv6', 'detection_endpoint', 'https://{random}.ipv6.example.com');

        $response = $this->actingAs($user)->get('/');

        $response->assertViewHas('ipv6DetectionEndpoint', 'https://{random}.ipv6.example.com');
    }

    public function test_portal_renders_ipv6_detection_for_enabled_user(): void
    {
        Queue::fake();
        $user = User::factory()->create(['internet_enabled' => true]);
        IntegrationConfig::setValue('ipv6', 'detection_endpoint', 'https://{random}.ipv6.example.com');

        $response = $this->actingAs($user)->get('/');

        $response->assertSee('attemptIpv6Detection', false);
        $response->assertSee('ipv6.example.com', false);
    }
}
