<?php

declare(strict_types=1);

namespace Tests\Feature\Admin;

use App\Models\Role;
use App\Models\User;
use App\Models\UserParameter;
use Illuminate\Foundation\Testing\LazilyRefreshDatabase;
use Tests\TestCase;

class UserParameterAuthorizationTest extends TestCase
{
    use LazilyRefreshDatabase;

    private function createAdminUser(): User
    {
        $user = User::factory()->create();
        $role = new Role;
        $role->code = 'admin';
        $role->name = 'Admin';
        $role->save();
        $user->roles()->attach($role);

        return $user;
    }

    public function test_cannot_update_another_users_parameter(): void
    {
        $admin = $this->createAdminUser();

        $user1 = User::factory()->create();
        $user2 = User::factory()->create();

        $user1Param = UserParameter::factory()->create([
            'user_id' => $user1->id,
            'key' => 'user1-key',
            'value' => ['original' => 'value1'],
        ]);

        $user2Param = UserParameter::factory()->create([
            'user_id' => $user2->id,
            'key' => 'user2-key',
            'value' => ['original' => 'value2'],
        ]);

        // Try to update user2's parameter via user1's route
        $response = $this->actingAs($admin)->put(
            route('admin.users.parameters.update', [
                'user' => $user1->id,
                'parameter' => $user2Param->id,  // Different user's parameter!
            ]),
            [
                'key' => 'hacked-key',
                'value' => 'hacked-data',
            ]
        );

        // Should fail with 404 or 403 (Laravel scoped bindings return 404)
        $response->assertNotFound();

        // Verify user2's parameter was NOT modified
        $user2Param->refresh();
        $this->assertSame('user2-key', $user2Param->key);
        $this->assertSame(['original' => 'value2'], $user2Param->value);
    }

    public function test_cannot_delete_another_users_parameter(): void
    {
        $admin = $this->createAdminUser();

        $user1 = User::factory()->create();
        $user2 = User::factory()->create();

        $user2Param = UserParameter::factory()->create([
            'user_id' => $user2->id,
            'key' => 'secret',
            'value' => ['data' => 'sensitive'],
        ]);

        // Try to delete user2's parameter via user1's route
        $response = $this->actingAs($admin)->delete(
            route('admin.users.parameters.destroy', [
                'user' => $user1->id,
                'parameter' => $user2Param->id,
            ])
        );

        $response->assertNotFound();

        // Verify parameter still exists
        $this->assertDatabaseHas('user_parameters', [
            'id' => $user2Param->id,
            'key' => 'secret',
        ]);
    }

    public function test_can_update_own_users_parameter(): void
    {
        $admin = $this->createAdminUser();

        $user = User::factory()->create();
        $param = UserParameter::factory()->create([
            'user_id' => $user->id,
            'key' => 'config',
            'value' => ['old' => 'value'],
        ]);

        $response = $this->actingAs($admin)->put(
            route('admin.users.parameters.update', [
                'user' => $user->id,
                'parameter' => $param->id,
            ]),
            [
                'key' => 'updated-config',
                'value' => 'new-value',
            ]
        );

        $response->assertRedirect();

        $param->refresh();
        $this->assertSame('updated-config', $param->key);
        $this->assertSame('new-value', $param->value);
    }

    public function test_can_delete_own_users_parameter(): void
    {
        $admin = $this->createAdminUser();

        $user = User::factory()->create();
        $param = UserParameter::factory()->create([
            'user_id' => $user->id,
            'key' => 'to-delete',
        ]);

        $response = $this->actingAs($admin)->delete(
            route('admin.users.parameters.destroy', [
                'user' => $user->id,
                'parameter' => $param->id,
            ])
        );

        $response->assertRedirect();

        $this->assertDatabaseMissing('user_parameters', [
            'id' => $param->id,
        ]);
    }
}
