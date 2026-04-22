<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\User;
use App\Services\Auth\AuthResult;
use App\Services\Auth\UserInfo;
use App\Services\Interfaces\AuthProviderInterface;
use App\Services\IpAddressActionService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Queue;
use Mockery\MockInterface;
use Tests\TestCase;

class CaptivePortalPollTest extends TestCase
{
    use RefreshDatabase;

    public function test_poll_returns_expired_when_no_cache(): void
    {
        $response = $this->getJson('/captive/poll/nonexistent-code');

        $response->assertStatus(410);
        $response->assertJson(['status' => 'expired']);
    }

    public function test_poll_returns_pending_when_auth_not_ready(): void
    {
        Cache::put('device_flow:test-code', [
            'status' => 'pending',
            'ip' => '192.168.1.100',
        ], now()->addMinutes(10));

        $mock = $this->mock(AuthProviderInterface::class);
        $mock->shouldReceive('pollDeviceFlow')
            ->with('test-code')
            ->andReturn(null);

        $response = $this->getJson('/captive/poll/test-code');

        $response->assertOk();
        $response->assertJson(['status' => 'pending']);
    }

    public function test_poll_returns_complete_when_already_complete(): void
    {
        Cache::put('device_flow:test-code', [
            'status' => 'complete',
            'ip' => '192.168.1.100',
            'user_id' => 1,
        ], now()->addMinutes(10));

        $response = $this->getJson('/captive/poll/test-code');

        $response->assertOk();
        $response->assertJson(['status' => 'complete']);
    }

    public function test_poll_creates_user_and_returns_complete_on_success(): void
    {
        Queue::fake();
        $this->mock(IpAddressActionService::class, function (MockInterface $mock): void {
            $mock->shouldReceive('enableInternet')->once();
        });

        Cache::put('device_flow:test-code', [
            'status' => 'pending',
            'ip' => '192.168.1.100',
        ], now()->addMinutes(10));

        $mock = $this->mock(AuthProviderInterface::class);
        $mock->shouldReceive('pollDeviceFlow')
            ->with('test-code')
            ->andReturn(new AuthResult(
                accessToken: 'access-123',
                tokenType: 'Bearer',
                expiresIn: 3600,
                refreshToken: 'refresh-123',
            ));
        $mock->shouldReceive('getUserInfo')
            ->with('access-123')
            ->andReturn(new UserInfo(
                id: 'ext-user-1',
                nickname: 'PollUser',
                email: 'poll@example.com',
                avatarUrl: 'https://example.com/avatar.png',
            ));

        $response = $this->getJson('/captive/poll/test-code');

        $response->assertOk();
        $response->assertJson(['status' => 'complete']);

        $this->assertDatabaseHas('users', [
            'external_id' => 'ext-user-1',
            'nickname' => 'PollUser',
            'email' => 'poll@example.com',
        ]);
    }

    public function test_poll_logs_in_user_on_success(): void
    {
        Queue::fake();
        $this->mock(IpAddressActionService::class, function (MockInterface $mock): void {
            $mock->shouldReceive('enableInternet')->once();
        });

        Cache::put('device_flow:test-code', [
            'status' => 'pending',
            'ip' => '192.168.1.100',
        ], now()->addMinutes(10));

        $mock = $this->mock(AuthProviderInterface::class);
        $mock->shouldReceive('pollDeviceFlow')
            ->with('test-code')
            ->andReturn(new AuthResult(
                accessToken: 'access-123',
                tokenType: 'Bearer',
                expiresIn: 3600,
            ));
        $mock->shouldReceive('getUserInfo')
            ->with('access-123')
            ->andReturn(new UserInfo(
                id: 'ext-logged-in',
                nickname: 'LoggedInUser',
            ));

        $this->getJson('/captive/poll/test-code');

        $this->assertAuthenticated();
    }

    public function test_poll_allows_ip_for_non_blocked_user(): void
    {
        Queue::fake();
        $this->mock(IpAddressActionService::class, function (MockInterface $mock): void {
            $mock->shouldReceive('enableInternet')->once();
        });

        Cache::put('device_flow:test-code', [
            'status' => 'pending',
            'ip' => '192.168.1.100',
        ], now()->addMinutes(10));

        $mock = $this->mock(AuthProviderInterface::class);
        $mock->shouldReceive('pollDeviceFlow')
            ->with('test-code')
            ->andReturn(new AuthResult(
                accessToken: 'access-123',
                tokenType: 'Bearer',
                expiresIn: 3600,
            ));
        $mock->shouldReceive('getUserInfo')
            ->with('access-123')
            ->andReturn(new UserInfo(
                id: 'ext-ip-test',
                nickname: 'IpTestUser',
            ));

        $this->getJson('/captive/poll/test-code');

        $this->assertDatabaseHas('ip_addresses', [
            'address' => '192.168.1.100',
        ]);
    }

    public function test_poll_does_not_allow_ip_for_blocked_user(): void
    {
        Queue::fake();
        $this->mock(IpAddressActionService::class, function (MockInterface $mock): void {
            $mock->shouldReceive('enableInternet')->never();
        });

        $user = User::factory()->internetBlocked()->create([
            'external_id' => 'ext-blocked',
        ]);

        Cache::put('device_flow:test-code', [
            'status' => 'pending',
            'ip' => '192.168.1.200',
        ], now()->addMinutes(10));

        $mock = $this->mock(AuthProviderInterface::class);
        $mock->shouldReceive('pollDeviceFlow')
            ->with('test-code')
            ->andReturn(new AuthResult(
                accessToken: 'access-blocked',
                tokenType: 'Bearer',
                expiresIn: 3600,
            ));
        $mock->shouldReceive('getUserInfo')
            ->with('access-blocked')
            ->andReturn(new UserInfo(
                id: 'ext-blocked',
                nickname: 'BlockedUser',
            ));

        $this->getJson('/captive/poll/test-code');

        $user->refresh();
        $this->assertTrue((bool) $user->internet_blocked);
    }
}
