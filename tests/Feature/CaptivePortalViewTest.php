<?php

namespace Tests\Feature;

use App\Services\Auth\AuthResult;
use App\Services\Auth\DeviceFlowResponse;
use App\Services\Auth\UserInfo;
use App\Services\Interfaces\AuthProviderInterface;
use Illuminate\Foundation\Testing\LazilyRefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Queue;
use RuntimeException;
use Tests\TestCase;

class CaptivePortalViewTest extends TestCase
{
    use LazilyRefreshDatabase;

    protected function mockAuthProvider(): void
    {
        $mock = $this->mock(AuthProviderInterface::class);
        $mock->shouldReceive('initiateDeviceFlow')
            ->andReturn(new DeviceFlowResponse(
                verificationUri: 'https://example.com/verify',
                deviceCode: 'test-device-code',
                userCode: 'ABCD-1234',
                expiresIn: 600,
                interval: 5,
                verificationUriComplete: 'https://example.com/verify?code=ABCD-1234',
            ));
    }

    public function test_captive_login_page_renders(): void
    {
        $this->mockAuthProvider();

        $response = $this->get('/captive');

        $response->assertOk();
        $response->assertViewIs('captive.login');
    }

    public function test_captive_login_inline_scripts_carry_csp_nonce_and_no_inline_handlers(): void
    {
        $this->mockAuthProvider();

        $response = $this->get('/captive');

        preg_match("/'nonce-([^']+)'/", $response->headers->get('Content-Security-Policy'), $m);
        $html = $response->getContent();
        $this->assertStringContainsString('<script nonce="'.$m[1].'">', $html);
        $this->assertDoesNotMatchRegularExpression('/<script>/', $html);
        $this->assertDoesNotMatchRegularExpression('/\sonclick=/', $html);
    }

    public function test_captive_login_page_displays_user_code(): void
    {
        $this->mockAuthProvider();

        $response = $this->get('/captive');

        $response->assertOk();
        $response->assertSee('ABCD-1234');
    }

    public function test_captive_login_page_displays_verification_uri(): void
    {
        $this->mockAuthProvider();

        $response = $this->get('/captive');

        $response->assertOk();
        $response->assertSee('https://example.com/verify');
    }

    public function test_interstitial_page_renders(): void
    {
        $response = $this->get('/captive/interstitial');

        $response->assertOk();
        $response->assertViewIs('captive.interstitial');
    }

    public function test_captive_login_has_step_instructions(): void
    {
        $this->mockAuthProvider();

        $response = $this->get('/captive');

        $response->assertOk();
        $response->assertSee('data-testid="captive-instructions"', false);
    }

    public function test_captive_expired_status_has_improved_message(): void
    {
        $this->mockAuthProvider();

        $response = $this->get('/captive');

        $response->assertOk();
        $response->assertSee('Your login code has expired');
    }

    public function test_captive_expired_has_refresh_button_data_testid(): void
    {
        $this->mockAuthProvider();

        $response = $this->get('/captive');

        $response->assertOk();
        $response->assertSee('data-testid="captive-refresh"', false);
    }

    public function test_captive_js_has_math_max_for_interval(): void
    {
        $this->mockAuthProvider();

        $response = $this->get('/captive');

        $response->assertOk();
        $response->assertSee('Math.max', false);
    }

    public function test_captive_qr_code_svg_uses_inline_styles(): void
    {
        $this->mockAuthProvider();

        $response = $this->get('/captive');

        $response->assertOk();
        $response->assertSee('<svg', false);
    }

    public function test_captive_expired_uses_hidden_class_not_d_none(): void
    {
        $this->mockAuthProvider();

        $response = $this->get('/captive');

        $response->assertOk();
        $response->assertDontSee('d-none');
    }

    public function test_captive_login_stores_device_flow_in_cache(): void
    {
        $this->mockAuthProvider();

        $this->get('/captive');

        $this->assertNotNull(Cache::get('device_flow:test-device-code'));
    }

    public function test_captive_login_uses_poll_url(): void
    {
        $this->mockAuthProvider();

        $response = $this->get('/captive');

        $response->assertOk();
        $response->assertSee('/captive/poll/', false);
    }

    public function test_captive_login_shows_graceful_error_when_oauth_not_configured(): void
    {
        $mock = $this->mock(AuthProviderInterface::class);
        $mock->shouldReceive('initiateDeviceFlow')->andThrow(new RuntimeException('OAuth2 not configured'));

        $response = $this->get('/captive');

        $response->assertStatus(503);
        $response->assertSee('Portal authentication is currently unavailable.');
        $response->assertSee('data-testid="captive-config-error"', false);
        $response->assertSee('[data-reload]', false);
    }

    public function test_captive_routes_have_throttle_middleware(): void
    {
        $routes = resolve('router')->getRoutes();

        $captiveIndex = $routes->getByName('captive.index');
        $this->assertNotNull($captiveIndex, 'captive.index route should exist');
        $this->assertTrue(
            collect($captiveIndex->gatherMiddleware())->contains(fn ($m): bool => str_contains((string) $m, 'throttle')),
            'captive.index should have throttle middleware'
        );

        $captivePoll = $routes->getByName('captive.poll');
        $this->assertNotNull($captivePoll, 'captive.poll route should exist');
        $this->assertTrue(
            collect($captivePoll->gatherMiddleware())->contains(fn ($m): bool => str_contains((string) $m, 'throttle')),
            'captive.poll should have throttle middleware'
        );
    }

    public function test_captive_portal_is_rate_limited(): void
    {
        $this->mockAuthProvider();

        for ($i = 0; $i < 30; $i++) {
            $this->get('/captive');
        }

        $response = $this->get('/captive');

        $response->assertStatus(429);
    }

    public function test_captive_device_flow_login_regenerates_session_id(): void
    {
        Queue::fake();
        Cache::put('device_flow:test-code', ['status' => 'pending', 'ip' => '192.168.1.100'], now()->addMinutes(10));

        $mock = $this->mock(AuthProviderInterface::class);
        $mock->shouldReceive('pollDeviceFlow')->with('test-code')->andReturn(new AuthResult(
            accessToken: 'access-123',
            tokenType: 'Bearer',
            expiresIn: 3600,
            refreshToken: 'refresh-123',
        ));
        $mock->shouldReceive('getUserInfo')->with('access-123')->andReturn(new UserInfo(
            id: 'ext-user-1',
            nickname: 'SessionUser',
            email: 'session@example.com',
            avatarUrl: 'https://example.com/avatar.png',
        ));

        $this->startSession();
        $before = session()->getId();

        $this->withServerVariables(['REMOTE_ADDR' => '192.168.1.100'])
            ->getJson('/captive/poll/test-code')
            ->assertOk()
            ->assertJson(['status' => 'complete']);

        $this->assertAuthenticated();
        $this->assertNotSame($before, session()->getId());
    }
}
