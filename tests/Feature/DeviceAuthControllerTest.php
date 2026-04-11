<?php

namespace Tests\Feature;

use App\Models\AuthProvider;
use App\Services\Auth\AuthResult;
use App\Services\Auth\DeviceFlowResponse;
use App\Services\Auth\UserInfo;
use App\Services\Interfaces\AuthProviderInterface;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Queue;
use Tests\TestCase;

class DeviceAuthControllerTest extends TestCase
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

    public function test_initiate_returns_device_code_and_verification_uri(): void
    {
        $this->createAuthProvider();

        $mock = $this->mock(AuthProviderInterface::class);
        $mock->shouldReceive('initiateDeviceFlow')
            ->once()
            ->with('test')
            ->andReturn(new DeviceFlowResponse(
                verificationUri: 'https://example.com/verify',
                deviceCode: 'test-device-code',
                userCode: 'ABCD-1234',
                expiresIn: 600,
                interval: 5,
                verificationUriComplete: 'https://example.com/verify?code=ABCD-1234',
            ));

        $response = $this->postJson('/auth/device/initiate', [
            'provider' => 'test',
        ]);

        $response->assertOk()
            ->assertJsonStructure([
                'device_code',
                'user_code',
                'verification_uri',
                'expires_in',
                'interval',
            ])
            ->assertJson([
                'device_code' => 'test-device-code',
                'user_code' => 'ABCD-1234',
                'verification_uri' => 'https://example.com/verify',
            ]);
    }

    public function test_initiate_validates_provider(): void
    {
        $response = $this->postJson('/auth/device/initiate', [
            'provider' => 'nonexistent',
        ]);

        $response->assertUnprocessable();
    }

    public function test_poll_returns_pending_while_waiting(): void
    {
        Cache::put('device_flow:test-code', [
            'provider_code' => 'test',
            'status' => 'pending',
            'ip' => '127.0.0.1',
        ], now()->addMinutes(10));

        $mock = $this->mock(AuthProviderInterface::class);
        $mock->shouldReceive('pollDeviceFlow')
            ->once()
            ->with('test-code')
            ->andReturn(null);

        $response = $this->getJson('/auth/device/poll/test-code');

        $response->assertOk()
            ->assertJson(['status' => 'pending']);
    }

    public function test_poll_returns_expired_for_unknown_device_code(): void
    {
        $response = $this->getJson('/auth/device/poll/nonexistent-code');

        $response->assertStatus(410)
            ->assertJson(['status' => 'expired']);
    }

    public function test_poll_returns_complete_after_auth(): void
    {
        Queue::fake();

        $this->createAuthProvider();

        Cache::put('device_flow:test-code', [
            'provider_code' => 'test',
            'status' => 'pending',
            'ip' => '127.0.0.1',
        ], now()->addMinutes(10));

        $mock = $this->mock(AuthProviderInterface::class);
        $mock->shouldReceive('pollDeviceFlow')
            ->once()
            ->with('test-code')
            ->andReturn(new AuthResult(
                accessToken: 'test-token',
                tokenType: 'Bearer',
                expiresIn: 3600,
            ));
        $mock->shouldReceive('getUserInfo')
            ->once()
            ->with('test-token')
            ->andReturn(new UserInfo(
                id: 'ext-123',
                nickname: 'TestUser',
                email: 'test@example.com',
            ));

        $response = $this->getJson('/auth/device/poll/test-code');

        $response->assertOk()
            ->assertJson(['status' => 'complete']);

        $this->assertDatabaseHas('users', ['nickname' => 'TestUser']);
    }
}
