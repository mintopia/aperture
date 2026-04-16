<?php

declare(strict_types=1);

namespace Tests\Feature\Admin;

use App\Models\Role;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Queue;
use Tests\TestCase;

class UserControllerBlockValidationTest extends TestCase
{
    use RefreshDatabase;

    protected function createAdminUser(): User
    {
        $user = User::factory()->create();
        $role = new Role;
        $role->code = 'admin';
        $role->name = 'Admin';
        $role->save();
        $user->roles()->attach($role);

        return $user;
    }

    public function test_block_rejects_missing_block_field(): void
    {
        Queue::fake();
        $admin = $this->createAdminUser();
        $user = User::factory()->create(['blocked' => false]);

        $response = $this->actingAs($admin)->post(sprintf('/admin/users/%d/block', $user->id), []);

        $response->assertSessionHasErrors('block');
    }

    public function test_block_rejects_non_boolean_value(): void
    {
        Queue::fake();
        $admin = $this->createAdminUser();
        $user = User::factory()->create(['blocked' => false]);

        $response = $this->actingAs($admin)->post(sprintf('/admin/users/%d/block', $user->id), [
            'block' => 'invalid-string',
        ]);

        $response->assertSessionHasErrors('block');
    }

    public function test_block_accepts_boolean_true(): void
    {
        Queue::fake();
        $admin = $this->createAdminUser();
        $user = User::factory()->create(['blocked' => false]);

        $response = $this->actingAs($admin)->post(sprintf('/admin/users/%d/block', $user->id), [
            'block' => true,
        ]);

        $response->assertRedirect();
        $response->assertSessionHasNoErrors();
        $this->assertTrue((bool) $user->fresh()->blocked);
    }

    public function test_block_accepts_boolean_false(): void
    {
        Queue::fake();
        $admin = $this->createAdminUser();
        $user = User::factory()->create(['blocked' => true]);

        $response = $this->actingAs($admin)->post(sprintf('/admin/users/%d/block', $user->id), [
            'block' => false,
        ]);

        $response->assertRedirect();
        $response->assertSessionHasNoErrors();
        $this->assertFalse((bool) $user->fresh()->blocked);
    }
}
