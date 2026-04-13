<?php

namespace Tests\Feature;

use App\Models\User;
use App\Providers\RouteServiceProvider;
use App\Services\Auth\DeviceFlowResponse;
use App\Services\Interfaces\AuthProviderInterface;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class RedirectIfAuthenticatedTest extends TestCase
{
    use RefreshDatabase;

    public function test_authenticated_user_is_redirected(): void
    {
        $user = User::factory()->create();

        $response = $this->actingAs($user)->get('/login');
        $response->assertRedirect(RouteServiceProvider::HOME);
    }

    public function test_guest_login_redirects_to_captive(): void
    {
        $response = $this->get('/login');
        $response->assertRedirect(route('captive.index'));
    }

    public function test_unauthenticated_home_redirects_to_captive(): void
    {
        $mock = $this->mock(AuthProviderInterface::class);
        $mock->shouldReceive('initiateDeviceFlow')
            ->andReturn(new DeviceFlowResponse(
                verificationUri: 'https://example.com/verify',
                deviceCode: 'test-device-code',
                userCode: 'ABCD-1234',
                expiresIn: 600,
                interval: 5,
            ));

        $response = $this->get('/');
        $response->assertRedirect(route('captive.index'));
    }
}
