<?php

namespace Tests\Feature;

use App\Models\AuthProvider;
use App\Models\User;
use App\Models\UserAuthentication;
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

    public function test_poll_returns_complete_for_already_completed_flow(): void
    {
        Cache::put('device_flow:completed-code', [
            'provider_code' => 'test',
            'status' => 'complete',
            'ip' => '127.0.0.1',
            'user_id' => 1,
        ], now()->addMinutes(10));

        $response = $this->getJson('/auth/device/poll/completed-code');

        $response->assertOk()
            ->assertJson(['status' => 'complete']);
    }

    public function test_poll_finds_existing_user_by_auth(): void
    {
        Queue::fake();

        $provider = $this->createAuthProvider();
        $existingUser = User::factory()->create(['nickname' => 'ExistingUser']);

        $userAuth = new UserAuthentication;
        $userAuth->user()->associate($existingUser);
        $userAuth->provider()->associate($provider);
        $userAuth->external_id = 'ext-existing';
        $userAuth->access_token = 'old-token';
        $userAuth->save();

        Cache::put('device_flow:existing-auth-code', [
            'provider_code' => 'test',
            'status' => 'pending',
            'ip' => '127.0.0.1',
        ], now()->addMinutes(10));

        $mock = $this->mock(AuthProviderInterface::class);
        $mock->shouldReceive('pollDeviceFlow')
            ->with('existing-auth-code')
            ->andReturn(new AuthResult(
                accessToken: 'new-token',
                tokenType: 'Bearer',
                expiresIn: 3600,
            ));
        $mock->shouldReceive('getUserInfo')
            ->with('new-token')
            ->andReturn(new UserInfo(
                id: 'ext-existing',
                nickname: 'UpdatedNick',
                email: 'existing@example.com',
            ));

        $response = $this->getJson('/auth/device/poll/existing-auth-code');

        $response->assertOk()
            ->assertJson(['status' => 'complete']);

        $this->assertEquals('UpdatedNick', $existingUser->fresh()->nickname);
    }

    public function test_poll_creates_user_without_email(): void
    {
        Queue::fake();

        $this->createAuthProvider();

        Cache::put('device_flow:no-email-code', [
            'provider_code' => 'test',
            'status' => 'pending',
            'ip' => '127.0.0.1',
        ], now()->addMinutes(10));

        $mock = $this->mock(AuthProviderInterface::class);
        $mock->shouldReceive('pollDeviceFlow')
            ->with('no-email-code')
            ->andReturn(new AuthResult(
                accessToken: 'test-token',
                tokenType: 'Bearer',
                expiresIn: 3600,
            ));
        $mock->shouldReceive('getUserInfo')
            ->with('test-token')
            ->andReturn(new UserInfo(
                id: 'ext-no-email',
                nickname: 'NoEmailUser',
            ));

        $response = $this->getJson('/auth/device/poll/no-email-code');

        $response->assertOk()
            ->assertJson(['status' => 'complete']);
        $this->assertDatabaseHas('users', ['nickname' => 'NoEmailUser']);
    }
}
