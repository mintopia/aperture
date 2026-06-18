<?php

declare(strict_types=1);

namespace Tests\Unit\Services\Auth;

use App\Models\User;
use App\Services\Auth\AuthResult;
use App\Services\Auth\DeviceFlowUserService;
use App\Services\Auth\UserInfo;
use Illuminate\Foundation\Testing\LazilyRefreshDatabase;
use Tests\TestCase;

class DeviceFlowUserServiceTest extends TestCase
{
    use LazilyRefreshDatabase;

    protected bool $seedSetupUser = false;

    private DeviceFlowUserService $service;

    protected function setUp(): void
    {
        parent::setUp();
        $this->service = new DeviceFlowUserService;
    }

    public function test_creates_new_user_when_none_exists(): void
    {
        $userInfo = new UserInfo(
            id: 'ext-123',
            nickname: 'TestUser',
            email: 'test@example.com',
            avatarUrl: 'https://example.com/avatar.png',
        );
        $authResult = new AuthResult(
            accessToken: 'access-token-123',
            tokenType: 'Bearer',
            expiresIn: 3600,
            refreshToken: 'refresh-token-123',
        );

        $user = $this->service->findOrCreateFromDeviceFlow($userInfo, $authResult);

        $this->assertInstanceOf(User::class, $user);
        $this->assertTrue($user->exists);
        $this->assertSame('ext-123', $user->external_id);
        $this->assertSame('TestUser', $user->nickname);
        $this->assertSame('test@example.com', $user->email);
        $this->assertSame('https://example.com/avatar.png', $user->avatar_url);
        $this->assertSame('access-token-123', $user->access_token);
        $this->assertSame('refresh-token-123', $user->refresh_token);
        $this->assertNotNull($user->token_expires_at);
    }

    public function test_finds_existing_user_by_external_id(): void
    {
        $existing = User::factory()->create([
            'external_id' => 'ext-456',
            'nickname' => 'OldName',
        ]);

        $userInfo = new UserInfo(
            id: 'ext-456',
            nickname: 'NewName',
            email: 'new@example.com',
            avatarUrl: 'https://example.com/new-avatar.png',
        );
        $authResult = new AuthResult(
            accessToken: 'new-access-token',
            tokenType: 'Bearer',
            expiresIn: 7200,
            refreshToken: 'new-refresh-token',
        );

        $user = $this->service->findOrCreateFromDeviceFlow($userInfo, $authResult);

        $this->assertSame($existing->id, $user->id);
        $this->assertSame('NewName', $user->nickname);
        $this->assertSame('new-access-token', $user->access_token);
        $this->assertSame('new-refresh-token', $user->refresh_token);
        $this->assertSame('https://example.com/new-avatar.png', $user->avatar_url);
    }

    public function test_finds_existing_user_by_email_when_external_id_not_matched(): void
    {
        $existing = User::factory()->create([
            'email' => 'shared@example.com',
            'external_id' => null,
        ]);

        $userInfo = new UserInfo(
            id: 'ext-789',
            nickname: 'MatchedByEmail',
            email: 'shared@example.com',
        );
        $authResult = new AuthResult(
            accessToken: 'token-abc',
            tokenType: 'Bearer',
            expiresIn: 3600,
        );

        $user = $this->service->findOrCreateFromDeviceFlow($userInfo, $authResult);

        $this->assertSame($existing->id, $user->id);
        $this->assertSame('ext-789', $user->external_id);
        $this->assertSame('MatchedByEmail', $user->nickname);
    }

    public function test_creates_new_user_when_no_email_match(): void
    {
        User::factory()->create(['email' => 'different@example.com']);

        $userInfo = new UserInfo(
            id: 'ext-new',
            nickname: 'BrandNew',
            email: 'unique@example.com',
        );
        $authResult = new AuthResult(
            accessToken: 'token-xyz',
            tokenType: 'Bearer',
            expiresIn: 3600,
        );

        $user = $this->service->findOrCreateFromDeviceFlow($userInfo, $authResult);

        $this->assertSame(2, User::count());
        $this->assertSame('ext-new', $user->external_id);
        $this->assertSame('BrandNew', $user->nickname);
    }

    public function test_handles_null_email_in_user_info(): void
    {
        $userInfo = new UserInfo(
            id: 'ext-no-email',
            nickname: 'NoEmail',
        );
        $authResult = new AuthResult(
            accessToken: 'token-no-email',
            tokenType: 'Bearer',
            expiresIn: 3600,
        );

        $user = $this->service->findOrCreateFromDeviceFlow($userInfo, $authResult);

        $this->assertSame('ext-no-email', $user->external_id);
        $this->assertSame('NoEmail', $user->nickname);
        $this->assertSame('', $user->email);
    }

    public function test_handles_null_refresh_token(): void
    {
        $userInfo = new UserInfo(
            id: 'ext-no-refresh',
            nickname: 'NoRefresh',
            email: 'norefresh@example.com',
        );
        $authResult = new AuthResult(
            accessToken: 'token-only',
            tokenType: 'Bearer',
            expiresIn: 3600,
        );

        $user = $this->service->findOrCreateFromDeviceFlow($userInfo, $authResult);

        $this->assertSame('token-only', $user->access_token);
        $this->assertSame('', $user->refresh_token);
    }
}
