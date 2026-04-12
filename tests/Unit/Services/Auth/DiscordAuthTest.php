<?php

namespace Tests\Unit\Services\Auth;

use App\Models\AuthProvider;
use App\Models\Role;
use App\Models\Setting;
use App\Models\User;
use App\Models\UserAuthentication;
use App\Services\Auth\DiscordAuth;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\RedirectResponse;
use Laravel\Socialite\Contracts\Provider as SocialiteProvider;
use Laravel\Socialite\Facades\Socialite;
use Laravel\Socialite\Two\User as SocialiteUser;
use Mockery;
use Tests\TestCase;

class DiscordAuthTest extends TestCase
{
    use RefreshDatabase;

    protected function createProvider(): AuthProvider
    {
        $provider = new AuthProvider;
        $provider->name = 'Discord';
        $provider->code = 'discord';
        $provider->class = DiscordAuth::class;
        $provider->client_id = 'test-client-id';
        $provider->client_secret = 'test-client-secret';
        $provider->enabled = true;
        $provider->save();

        return $provider;
    }

    public function test_supports_borealis_returns_true(): void
    {
        $provider = $this->createProvider();
        $auth = new DiscordAuth($provider);
        $this->assertTrue($auth->supportsBorealis());
    }

    public function test_get_required_hostnames_returns_discord_hosts(): void
    {
        $provider = $this->createProvider();
        $auth = new DiscordAuth($provider);
        $hostnames = $auth->getRequiredHostnames();
        $this->assertContains('discord.com', $hostnames);
        $this->assertContains('gateway.discord.gg', $hostnames);
    }

    public function test_redirect_calls_socialite_driver(): void
    {
        $provider = $this->createProvider();

        $mockDriver = Mockery::mock(SocialiteProvider::class);
        $mockDriver->shouldReceive('redirect')->andReturn(redirect('https://discord.com/oauth'));
        $mockDriver->shouldReceive('setConfig')->andReturnSelf();

        Socialite::shouldReceive('driver')->with('discord')->andReturn($mockDriver);

        $auth = new DiscordAuth($provider);
        $result = $auth->redirect();
        $this->assertInstanceOf(RedirectResponse::class, $result);
    }

    public function test_user_creates_new_user_when_not_found(): void
    {
        $provider = $this->createProvider();

        $role = new Role;
        $role->name = 'User';
        $role->code = 'user';
        $role->save();

        $socialiteUser = Mockery::mock(SocialiteUser::class);
        $socialiteUser->id = 'discord-123';
        $socialiteUser->email = 'new@example.com';
        $socialiteUser->nickname = 'NewUser';
        $socialiteUser->token = 'access-token';
        $socialiteUser->refreshToken = 'refresh-token';
        $socialiteUser->expiresIn = 3600;
        $socialiteUser->user = ['global_name' => 'Global Name'];

        $mockDriver = Mockery::mock(SocialiteProvider::class);
        $mockDriver->shouldReceive('user')->andReturn($socialiteUser);
        $mockDriver->shouldReceive('setConfig')->andReturnSelf();

        Socialite::shouldReceive('driver')->with('discord')->andReturn($mockDriver);

        $auth = new DiscordAuth($provider);
        $user = $auth->user();

        $this->assertInstanceOf(User::class, $user);
        $this->assertEquals('new@example.com', $user->email);
        $this->assertEquals('Global Name', $user->nickname);
        $this->assertDatabaseHas('user_authentications', [
            'external_id' => 'discord-123',
            'auth_provider_id' => $provider->id,
        ]);
    }

    public function test_user_links_existing_user_by_email(): void
    {
        $provider = $this->createProvider();

        $role = new Role;
        $role->name = 'User';
        $role->code = 'user';
        $role->save();

        $existingUser = User::factory()->create(['email' => 'existing@example.com']);

        // Enable email linking
        $setting = new Setting;
        $setting->code = 'auth.linkemails';
        $setting->name = 'Link Emails';
        $setting->value = true;
        $setting->save();

        $socialiteUser = Mockery::mock(SocialiteUser::class);
        $socialiteUser->id = 'discord-456';
        $socialiteUser->email = 'existing@example.com';
        $socialiteUser->nickname = 'ExistingUser';
        $socialiteUser->token = 'access-token';
        $socialiteUser->refreshToken = 'refresh-token';
        $socialiteUser->expiresIn = 3600;
        $socialiteUser->user = ['global_name' => 'Existing Global'];

        $mockDriver = Mockery::mock(SocialiteProvider::class);
        $mockDriver->shouldReceive('user')->andReturn($socialiteUser);
        $mockDriver->shouldReceive('setConfig')->andReturnSelf();

        Socialite::shouldReceive('driver')->with('discord')->andReturn($mockDriver);

        $auth = new DiscordAuth($provider);
        $user = $auth->user();

        $this->assertEquals($existingUser->id, $user->id);
        $this->assertEquals('Existing Global', $user->nickname);
    }

    public function test_user_returns_existing_auth_user(): void
    {
        $provider = $this->createProvider();

        $existingUser = User::factory()->create(['email' => 'auth@example.com']);

        $userAuth = new UserAuthentication;
        $userAuth->user()->associate($existingUser);
        $userAuth->provider()->associate($provider);
        $userAuth->external_id = 'discord-789';
        $userAuth->access_token = 'old-token';
        $userAuth->refresh_token = 'old-refresh';
        $userAuth->save();

        $socialiteUser = Mockery::mock(SocialiteUser::class);
        $socialiteUser->id = 'discord-789';
        $socialiteUser->email = 'auth@example.com';
        $socialiteUser->nickname = 'UpdatedNick';
        $socialiteUser->token = 'new-token';
        $socialiteUser->refreshToken = 'new-refresh';
        $socialiteUser->expiresIn = 7200;
        $socialiteUser->user = ['global_name' => 'Updated Global'];

        $mockDriver = Mockery::mock(SocialiteProvider::class);
        $mockDriver->shouldReceive('user')->andReturn($socialiteUser);
        $mockDriver->shouldReceive('setConfig')->andReturnSelf();

        Socialite::shouldReceive('driver')->with('discord')->andReturn($mockDriver);

        $auth = new DiscordAuth($provider);
        $user = $auth->user();

        $this->assertEquals($existingUser->id, $user->id);
        $this->assertEquals('Updated Global', $user->nickname);
    }

    public function test_user_falls_back_to_nickname_without_global_name(): void
    {
        $provider = $this->createProvider();

        $role = new Role;
        $role->name = 'User';
        $role->code = 'user';
        $role->save();

        $socialiteUser = Mockery::mock(SocialiteUser::class);
        $socialiteUser->id = 'discord-no-global';
        $socialiteUser->email = 'noglobal@example.com';
        $socialiteUser->nickname = 'FallbackNick';
        $socialiteUser->token = 'access-token';
        $socialiteUser->refreshToken = 'refresh-token';
        $socialiteUser->expiresIn = 3600;
        $socialiteUser->user = []; // No global_name

        $mockDriver = Mockery::mock(SocialiteProvider::class);
        $mockDriver->shouldReceive('user')->andReturn($socialiteUser);
        $mockDriver->shouldReceive('setConfig')->andReturnSelf();

        Socialite::shouldReceive('driver')->with('discord')->andReturn($mockDriver);

        $auth = new DiscordAuth($provider);
        $user = $auth->user();

        $this->assertEquals('FallbackNick', $user->nickname);
    }
}
