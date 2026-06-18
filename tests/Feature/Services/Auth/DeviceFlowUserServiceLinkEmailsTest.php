<?php

declare(strict_types=1);

namespace Tests\Feature\Services\Auth;

use App\Models\User;
use App\Services\Auth\AuthResult;
use App\Services\Auth\DeviceFlowUserService;
use App\Services\Auth\UserInfo;
use Illuminate\Foundation\Testing\LazilyRefreshDatabase;
use Illuminate\Support\Facades\Config;
use Tests\TestCase;

class DeviceFlowUserServiceLinkEmailsTest extends TestCase
{
    use LazilyRefreshDatabase;

    protected bool $seedSetupUser = false;

    private DeviceFlowUserService $service;

    protected function setUp(): void
    {
        parent::setUp();
        $this->service = new DeviceFlowUserService;
    }

    public function test_does_not_link_by_email_when_linkemails_is_false(): void
    {
        Config::set('auth.linkemails', false);

        // Create existing user with email but no external_id
        $existing = User::factory()->create([
            'email' => 'shared@example.com',
            'external_id' => null,
            'nickname' => 'OldUser',
        ]);

        // New user info with different external_id but same email
        $userInfo = new UserInfo(
            id: 'ext-new-123',
            nickname: 'NewUser',
            email: 'shared@example.com',
        );
        $authResult = new AuthResult(
            accessToken: 'token-abc',
            tokenType: 'Bearer',
            expiresIn: 3600,
        );

        $user = $this->service->findOrCreateFromDeviceFlow($userInfo, $authResult);

        // Should create a NEW user, not link to existing one
        $this->assertNotEquals($existing->id, $user->id, 'Should create new user when linkemails=false');
        $this->assertSame('ext-new-123', $user->external_id);
        $this->assertSame('NewUser', $user->nickname);
        $this->assertSame(2, User::count(), 'Should have 2 users total');
    }

    public function test_links_by_email_when_linkemails_is_true(): void
    {
        Config::set('auth.linkemails', true);

        $existing = User::factory()->create([
            'email' => 'shared@example.com',
            'external_id' => null,
            'nickname' => 'OldUser',
        ]);

        $userInfo = new UserInfo(
            id: 'ext-new-456',
            nickname: 'NewUser',
            email: 'shared@example.com',
        );
        $authResult = new AuthResult(
            accessToken: 'token-xyz',
            tokenType: 'Bearer',
            expiresIn: 3600,
        );

        $user = $this->service->findOrCreateFromDeviceFlow($userInfo, $authResult);

        // Should link to existing user
        $this->assertSame($existing->id, $user->id, 'Should link to existing user when linkemails=true');
        $this->assertSame('ext-new-456', $user->external_id);
        $this->assertSame(1, User::count(), 'Should still have 1 user');
    }

    public function test_links_by_email_when_linkemails_is_not_set_default_true(): void
    {
        // Default behavior when config is not set should be true (backward compatible)
        // Don't set config at all - let it use default from config/auth.php

        $existing = User::factory()->create([
            'email' => 'shared@example.com',
            'external_id' => null,
        ]);

        $userInfo = new UserInfo(
            id: 'ext-789',
            nickname: 'User',
            email: 'shared@example.com',
        );
        $authResult = new AuthResult(
            accessToken: 'token',
            tokenType: 'Bearer',
            expiresIn: 3600,
        );

        $user = $this->service->findOrCreateFromDeviceFlow($userInfo, $authResult);

        $this->assertSame($existing->id, $user->id, 'Should default to linking (backward compatible)');
    }

    public function test_always_links_by_external_id_regardless_of_linkemails(): void
    {
        Config::set('auth.linkemails', false);

        $existing = User::factory()->create([
            'external_id' => 'ext-match',
            'email' => 'same@example.com',
            'nickname' => 'OldNick',
        ]);

        $userInfo = new UserInfo(
            id: 'ext-match',
            nickname: 'NewNick',
            email: 'same@example.com',
        );
        $authResult = new AuthResult(
            accessToken: 'token',
            tokenType: 'Bearer',
            expiresIn: 3600,
        );

        $user = $this->service->findOrCreateFromDeviceFlow($userInfo, $authResult);

        // Should always link by external_id (takes precedence)
        $this->assertSame($existing->id, $user->id);
        $this->assertSame('NewNick', $user->nickname);
        $this->assertSame('same@example.com', $user->email);
    }
}
