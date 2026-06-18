<?php

namespace Tests\Unit;

use App\Services\Auth\BorealisDeviceFlowService;
use App\Services\Borealis\RequestException;
use App\Services\BorealisService;
use RuntimeException;
use Tests\TestCase;

class BorealisDeviceFlowServiceTest extends TestCase
{
    public function test_initiate_device_flow_returns_response(): void
    {
        $borealisMock = $this->mock(BorealisService::class);
        $borealisMock->shouldReceive('getDeviceCodeRaw')
            ->once()
            ->with('discord')
            ->andReturn((object) [
                'verification_uri' => 'https://borealis.test/verify',
                'device_code' => 'abc123',
                'user_code' => 'TEST-CODE',
                'expires_in' => 600,
                'interval' => 5,
                'verification_uri_complete' => 'https://borealis.test/verify?code=TEST-CODE',
            ]);

        $service = new BorealisDeviceFlowService($borealisMock);
        $response = $service->initiateDeviceFlow('discord');

        $this->assertEquals('https://borealis.test/verify', $response->verificationUri);
        $this->assertEquals('abc123', $response->deviceCode);
        $this->assertEquals('TEST-CODE', $response->userCode);
        $this->assertEquals(600, $response->expiresIn);
        $this->assertEquals(5, $response->interval);
    }

    public function test_poll_device_flow_returns_null_when_pending(): void
    {
        $borealisMock = $this->mock(BorealisService::class);
        $borealisMock->shouldReceive('check')
            ->once()
            ->with('abc123')
            ->andThrow(new RequestException('authorization_pending', 403));

        $service = new BorealisDeviceFlowService($borealisMock);
        $result = $service->pollDeviceFlow('abc123');

        $this->assertNull($result);
    }

    public function test_poll_device_flow_returns_null_on_slow_down(): void
    {
        $borealisMock = $this->mock(BorealisService::class);
        $borealisMock->shouldReceive('check')
            ->once()
            ->with('abc123')
            ->andThrow(new RequestException('slow_down', 403));

        $service = new BorealisDeviceFlowService($borealisMock);
        $result = $service->pollDeviceFlow('abc123');

        $this->assertNull($result);
    }

    public function test_poll_device_flow_returns_auth_result_on_success(): void
    {
        $borealisMock = $this->mock(BorealisService::class);
        $borealisMock->shouldReceive('check')
            ->once()
            ->with('abc123')
            ->andReturn((object) [
                'access_token' => 'token-xyz',
                'token_type' => 'Bearer',
                'expires_in' => 3600,
                'refresh_token' => 'refresh-xyz',
                'user' => (object) [
                    'id' => 'user-123',
                    'nickname' => 'TestUser',
                    'email' => 'test@example.com',
                    'avatar_url' => 'https://example.com/avatar.png',
                ],
            ]);

        $service = new BorealisDeviceFlowService($borealisMock);
        $result = $service->pollDeviceFlow('abc123');

        $this->assertNotNull($result);
        $this->assertEquals('token-xyz', $result->accessToken);
        $this->assertEquals('Bearer', $result->tokenType);
        $this->assertEquals(3600, $result->expiresIn);
    }

    public function test_poll_device_flow_throws_on_other_errors(): void
    {
        $borealisMock = $this->mock(BorealisService::class);
        $borealisMock->shouldReceive('check')
            ->once()
            ->with('abc123')
            ->andThrow(new RequestException('access_denied', 403));

        $this->expectException(RequestException::class);
        $this->expectExceptionMessage('access_denied');

        $service = new BorealisDeviceFlowService($borealisMock);
        $service->pollDeviceFlow('abc123');
    }

    public function test_get_user_info_returns_user_info(): void
    {
        $borealisMock = $this->mock(BorealisService::class);
        $borealisMock->shouldReceive('check')
            ->once()
            ->with('abc123')
            ->andReturn((object) [
                'access_token' => 'token-xyz',
                'token_type' => 'Bearer',
                'expires_in' => 3600,
                'refresh_token' => 'refresh-xyz',
                'user' => (object) [
                    'id' => 'user-123',
                    'nickname' => 'TestUser',
                    'email' => 'test@example.com',
                    'avatar_url' => 'https://example.com/avatar.png',
                ],
            ]);

        $service = new BorealisDeviceFlowService($borealisMock);
        $service->pollDeviceFlow('abc123');

        $info = $service->getUserInfo('token-xyz');

        $this->assertEquals('user-123', $info->id);
        $this->assertEquals('TestUser', $info->nickname);
        $this->assertEquals('test@example.com', $info->email);
        $this->assertEquals('https://example.com/avatar.png', $info->avatarUrl);
    }

    public function test_get_user_info_throws_without_poll(): void
    {
        $borealisMock = $this->mock(BorealisService::class);

        $service = new BorealisDeviceFlowService($borealisMock);

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('User info not available');
        $service->getUserInfo('token-xyz');
    }
}
