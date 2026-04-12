<?php

namespace Tests\Unit\Services\Auth;

use App\Models\AuthProvider;
use App\Models\Role;
use App\Models\User;
use App\Models\UserAuthentication;
use App\Services\Auth\SteamAuth;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\RedirectResponse;
use Laravel\Socialite\Contracts\Provider as SocialiteProvider;
use Laravel\Socialite\Facades\Socialite;
use Laravel\Socialite\Two\User as SocialiteUser;
use Mockery;
use Tests\TestCase;

class SteamAuthTest extends TestCase
{
    use RefreshDatabase;

    protected function createProvider(): AuthProvider
    {
        $provider = new AuthProvider;
        $provider->name = 'Steam';
        $provider->code = 'steam';
        $provider->class = SteamAuth::class;
        $provider->client_secret = 'test-api-key';
        $provider->enabled = true;
        $provider->save();

        return $provider;
    }

    public function test_supports_borealis_returns_false(): void
    {
        $provider = $this->createProvider();
        $auth = new SteamAuth($provider);
        $this->assertFalse($auth->supportsBorealis());
    }

    public function test_get_required_hostnames_returns_steam_hosts(): void
    {
        $provider = $this->createProvider();
        $auth = new SteamAuth($provider);
        $hostnames = $auth->getRequiredHostnames();
        $this->assertContains('steamcommunity.com', $hostnames);
        $this->assertContains('api.steampowered.com', $hostnames);
    }

    public function test_redirect_calls_socialite_driver(): void
    {
        $provider = $this->createProvider();

        $mockDriver = Mockery::mock(SocialiteProvider::class);
        $mockDriver->shouldReceive('redirect')->andReturn(redirect('https://steamcommunity.com'));
        $mockDriver->shouldReceive('setConfig')->andReturnSelf();

        Socialite::shouldReceive('driver')->with('steam')->andReturn($mockDriver);

        $auth = new SteamAuth($provider);
        $result = $auth->redirect();
        $this->assertInstanceOf(RedirectResponse::class, $result);
    }

    public function test_user_creates_new_user_and_auth(): void
    {
        $provider = $this->createProvider();

        $role = new Role;
        $role->name = 'User';
        $role->code = 'user';
        $role->save();

        $socialiteUser = Mockery::mock(SocialiteUser::class);
        $socialiteUser->id = 'steam-123';
        $socialiteUser->nickname = 'SteamGamer';

        $mockDriver = Mockery::mock(SocialiteProvider::class);
        $mockDriver->shouldReceive('user')->andReturn($socialiteUser);
        $mockDriver->shouldReceive('setConfig')->andReturnSelf();

        Socialite::shouldReceive('driver')->with('steam')->andReturn($mockDriver);

        $auth = new SteamAuth($provider);
        $user = $auth->user();

        $this->assertInstanceOf(User::class, $user);
        $this->assertEquals('SteamGamer', $user->nickname);
        $this->assertDatabaseHas('user_authentications', [
            'external_id' => 'steam-123',
            'auth_provider_id' => $provider->id,
        ]);
    }

    public function test_user_returns_existing_auth_user(): void
    {
        $provider = $this->createProvider();

        $existingUser = User::factory()->create(['nickname' => 'OldNick']);

        $userAuth = new UserAuthentication;
        $userAuth->user()->associate($existingUser);
        $userAuth->provider()->associate($provider);
        $userAuth->external_id = 'steam-existing';
        $userAuth->access_token = 'old-token';
        $userAuth->save();

        $socialiteUser = Mockery::mock(SocialiteUser::class);
        $socialiteUser->id = 'steam-existing';
        $socialiteUser->nickname = 'UpdatedSteamNick';

        $mockDriver = Mockery::mock(SocialiteProvider::class);
        $mockDriver->shouldReceive('user')->andReturn($socialiteUser);
        $mockDriver->shouldReceive('setConfig')->andReturnSelf();

        Socialite::shouldReceive('driver')->with('steam')->andReturn($mockDriver);

        $auth = new SteamAuth($provider);
        $user = $auth->user();

        $this->assertEquals($existingUser->id, $user->id);
        $this->assertEquals('UpdatedSteamNick', $user->nickname);
    }
}
