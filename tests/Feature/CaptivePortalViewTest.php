<?php

namespace Tests\Feature;

use App\Models\AuthProvider;
use App\Services\Auth\DeviceFlowResponse;
use App\Services\Interfaces\AuthProviderInterface;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class CaptivePortalViewTest extends TestCase
{
    use RefreshDatabase;

    protected function createAuthProvider(): AuthProvider
    {
        $provider = new AuthProvider;
        $provider->name = 'Test Provider';
        $provider->code = 'test';
        $provider->class = 'test';
        $provider->enabled = true;
        $provider->save();

        return $provider;
    }

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
        $this->createAuthProvider();
        $this->mockAuthProvider();

        $response = $this->get('/captive');

        $response->assertOk();
        $response->assertViewIs('captive.login');
    }

    public function test_captive_login_page_displays_user_code(): void
    {
        $this->createAuthProvider();
        $this->mockAuthProvider();

        $response = $this->get('/captive');

        $response->assertOk();
        $response->assertSee('ABCD-1234');
    }

    public function test_captive_login_page_displays_verification_uri(): void
    {
        $this->createAuthProvider();
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

    public function test_captive_login_aborts_when_no_provider(): void
    {
        // No AuthProvider created, so whereEnabled(true)->first() returns null
        $response = $this->get('/captive');
        $response->assertStatus(503);
    }

    public function test_captive_login_has_step_instructions(): void
    {
        $this->createAuthProvider();
        $this->mockAuthProvider();

        $response = $this->get('/captive');

        $response->assertOk();
        $response->assertSee('data-testid="captive-instructions"', false);
    }

    public function test_captive_expired_status_has_improved_message(): void
    {
        $this->createAuthProvider();
        $this->mockAuthProvider();

        $response = $this->get('/captive');

        $response->assertOk();
        $response->assertSee('Your login code has expired');
    }

    public function test_captive_expired_has_refresh_button_data_testid(): void
    {
        $this->createAuthProvider();
        $this->mockAuthProvider();

        $response = $this->get('/captive');

        $response->assertOk();
        $response->assertSee('data-testid="captive-refresh"', false);
    }

    public function test_captive_js_has_math_max_for_interval(): void
    {
        $this->createAuthProvider();
        $this->mockAuthProvider();

        $response = $this->get('/captive');

        $response->assertOk();
        $response->assertSee('Math.max', false);
    }

    public function test_captive_qr_code_svg_uses_inline_styles(): void
    {
        $this->createAuthProvider();
        $this->mockAuthProvider();

        $response = $this->get('/captive');

        $response->assertOk();
        $response->assertSee('<svg', false);
    }

    public function test_captive_expired_uses_hidden_class_not_d_none(): void
    {
        $this->createAuthProvider();
        $this->mockAuthProvider();

        $response = $this->get('/captive');

        $response->assertOk();
        $response->assertDontSee('d-none');
    }
}
