<?php

declare(strict_types=1);

namespace Tests\Feature\Api;

use App\Models\User;
use Illuminate\Foundation\Testing\LazilyRefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class CurrentUserApiTest extends TestCase
{
    use LazilyRefreshDatabase;

    public function test_api_user_returns_user_resource_without_sensitive_attributes(): void
    {
        $user = User::factory()->create(['nickname' => 'apiuser']);
        Sanctum::actingAs($user);

        $response = $this->getJson('/api/user');

        $response->assertOk()
            ->assertJsonPath('data.nickname', 'apiuser')
            ->assertJsonStructure(['data' => ['id', 'nickname', 'email', 'internet_blocked', 'has_password', 'roles', 'avatar_url']])
            ->assertJsonMissingPath('data.password')
            ->assertJsonMissingPath('data.remember_token');
    }

    public function test_api_user_requires_authentication(): void
    {
        $this->getJson('/api/user')->assertUnauthorized();
    }
}
