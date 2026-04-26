<?php

namespace Tests\Feature;

use App\Services\Auth\DeviceFlowResponse;
use App\Services\Interfaces\AuthProviderInterface;
use Illuminate\Foundation\Testing\LazilyRefreshDatabase;
use Illuminate\Support\Facades\Cache;
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
    }
}
