<?php

declare(strict_types=1);

namespace Tests\Feature\Http\Resources;

use App\Http\Resources\UserResource;
use App\Models\Role;
use App\Models\User;
use Illuminate\Foundation\Testing\LazilyRefreshDatabase;
use Illuminate\Http\Request;
use Tests\TestCase;

class UserResourceTest extends TestCase
{
    use LazilyRefreshDatabase;

    public function test_to_array_returns_expected_keys(): void
    {
        $user = User::factory()->create();
        $user->load('roles');

        $resource = new UserResource($user);
        $result = $resource->toArray(Request::create('/'));

        $this->assertArrayHasKey('id', $result);
        $this->assertArrayHasKey('nickname', $result);
        $this->assertArrayHasKey('email', $result);
        $this->assertArrayHasKey('internet_blocked', $result);
        $this->assertArrayHasKey('has_password', $result);
        $this->assertArrayHasKey('roles', $result);
        $this->assertArrayHasKey('avatar_url', $result);
    }

    public function test_to_array_returns_correct_values(): void
    {
        $user = User::factory()->create([
            'nickname' => 'testuser',
            'email' => 'test@example.com',
            'internet_blocked' => false,
            'avatar_url' => 'https://example.com/avatar.png',
        ]);
        $user->load('roles');

        $resource = new UserResource($user);
        $result = $resource->toArray(Request::create('/'));

        $this->assertSame($user->id, $result['id']);
        $this->assertSame('testuser', $result['nickname']);
        $this->assertSame('test@example.com', $result['email']);
        $this->assertFalse($result['internet_blocked']);
        $this->assertSame('https://example.com/avatar.png', $result['avatar_url']);
    }

    public function test_has_password_is_true_when_password_set(): void
    {
        $user = User::factory()->withPassword()->create();
        $user->load('roles');

        $resource = new UserResource($user);
        $result = $resource->toArray(Request::create('/'));

        $this->assertTrue($result['has_password']);
    }

    public function test_has_password_is_false_when_no_password(): void
    {
        $user = User::factory()->create(['password' => null]);
        $user->load('roles');

        $resource = new UserResource($user);
        $result = $resource->toArray(Request::create('/'));

        $this->assertFalse($result['has_password']);
    }

    public function test_roles_returns_role_codes(): void
    {
        $user = User::factory()->create();
        $role = new Role;
        $role->code = 'admin';
        $role->name = 'Admin';
        $role->save();
        $user->roles()->attach($role);
        $user->load('roles');

        $resource = new UserResource($user);
        $result = $resource->toArray(Request::create('/'));

        $this->assertContains('admin', $result['roles']->toArray());
    }

    public function test_to_array_does_not_expose_sensitive_fields(): void
    {
        $user = User::factory()->withAuth()->create();
        $user->load('roles');

        $resource = new UserResource($user);
        $result = $resource->toArray(Request::create('/'));

        $this->assertArrayNotHasKey('password', $result);
        $this->assertArrayNotHasKey('access_token', $result);
        $this->assertArrayNotHasKey('refresh_token', $result);
        $this->assertArrayNotHasKey('token_expires_at', $result);
        $this->assertArrayNotHasKey('external_id', $result);
    }

    public function test_resource_collection_returns_array_of_resources(): void
    {
        User::factory()->count(3)->create();

        $collection = UserResource::collection(User::with('roles')->get());
        $resolved = $collection->resolve();

        $this->assertCount(3, $resolved);

        foreach ($resolved as $item) {
            $this->assertArrayHasKey('id', $item);
            $this->assertArrayHasKey('nickname', $item);
            $this->assertArrayHasKey('email', $item);
        }
    }
}
