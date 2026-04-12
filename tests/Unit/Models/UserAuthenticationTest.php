<?php

namespace Tests\Unit\Models;

use App\Models\AuthProvider;
use App\Models\User;
use App\Models\UserAuthentication;
use App\Services\Auth\DiscordAuth;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class UserAuthenticationTest extends TestCase
{
    use RefreshDatabase;

    public function test_user_returns_belongs_to_relationship(): void
    {
        $auth = new UserAuthentication;
        $this->assertInstanceOf(BelongsTo::class, $auth->user());
    }

    public function test_provider_returns_belongs_to_relationship(): void
    {
        $auth = new UserAuthentication;
        $this->assertInstanceOf(BelongsTo::class, $auth->provider());
    }

    public function test_access_token_and_refresh_token_are_hidden(): void
    {
        $user = User::factory()->create();
        $provider = new AuthProvider;
        $provider->name = 'Test';
        $provider->code = 'test';
        $provider->class = DiscordAuth::class;
        $provider->client_id = 'id';
        $provider->client_secret = 'secret';
        $provider->enabled = true;
        $provider->save();

        $auth = new UserAuthentication;
        $auth->user()->associate($user);
        $auth->provider()->associate($provider);
        $auth->external_id = '12345';
        $auth->access_token = 'secret-token';
        $auth->refresh_token = 'refresh-secret';
        $auth->save();

        $array = $auth->toArray();
        $this->assertArrayNotHasKey('access_token', $array);
        $this->assertArrayNotHasKey('refresh_token', $array);
    }

    public function test_tokens_are_encrypted(): void
    {
        $user = User::factory()->create();
        $provider = new AuthProvider;
        $provider->name = 'Test';
        $provider->code = 'test';
        $provider->class = DiscordAuth::class;
        $provider->client_id = 'id';
        $provider->client_secret = 'secret';
        $provider->enabled = true;
        $provider->save();

        $auth = new UserAuthentication;
        $auth->user()->associate($user);
        $auth->provider()->associate($provider);
        $auth->external_id = '12345';
        $auth->access_token = 'my-token';
        $auth->refresh_token = 'my-refresh';
        $auth->save();

        $fresh = UserAuthentication::find($auth->id);
        $this->assertEquals('my-token', $fresh->access_token);
        $this->assertEquals('my-refresh', $fresh->refresh_token);
    }
}
