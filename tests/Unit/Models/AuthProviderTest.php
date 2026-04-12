<?php

declare(strict_types=1);

namespace Tests\Unit\Models;

use App\Models\AuthProvider;
use App\Services\Auth\DiscordAuth;
use App\Services\Interfaces\AuthBackendInterface;
use Illuminate\Database\Eloquent\Relations\HasManyThrough;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class AuthProviderTest extends TestCase
{
    use RefreshDatabase;

    public function test_can_create_auth_provider(): void
    {
        $provider = new AuthProvider;
        $provider->name = 'Discord';
        $provider->code = 'discord';
        $provider->class = DiscordAuth::class;
        $provider->enabled = 1;
        $provider->save();

        $this->assertDatabaseHas('auth_providers', ['code' => 'discord']);
    }

    public function test_authentications_relationship(): void
    {
        $provider = new AuthProvider;
        $provider->name = 'Discord';
        $provider->code = 'discord';
        $provider->class = DiscordAuth::class;
        $provider->enabled = 1;
        $provider->save();

        $this->assertCount(0, $provider->authentications);
    }

    public function test_users_relationship(): void
    {
        $provider = new AuthProvider;
        $provider->name = 'Discord';
        $provider->code = 'discord';
        $provider->class = DiscordAuth::class;
        $provider->enabled = 1;
        $provider->save();

        $this->assertInstanceOf(HasManyThrough::class, $provider->users());
    }

    public function test_get_backend_returns_interface(): void
    {
        $provider = new AuthProvider;
        $provider->class = DiscordAuth::class;

        $backend = $provider->getBackend();
        $this->assertInstanceOf(AuthBackendInterface::class, $backend);
    }

    public function test_client_secret_is_hidden(): void
    {
        $provider = new AuthProvider;
        $provider->name = 'Discord';
        $provider->code = 'discord';
        $provider->class = DiscordAuth::class;
        $provider->client_secret = 'secret123';
        $provider->enabled = 1;

        $array = $provider->toArray();
        $this->assertArrayNotHasKey('client_secret', $array);
    }
}
