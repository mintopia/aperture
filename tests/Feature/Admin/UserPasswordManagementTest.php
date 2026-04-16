<?php

namespace Tests\Feature\Admin;

use App\Models\Role;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Queue;
use Tests\TestCase;

class UserPasswordManagementTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        Queue::fake();
    }

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

    public function test_admin_can_view_user_edit_page(): void
    {
        $admin = $this->createAdminUser();
        $target = User::factory()->create();

        $response = $this->actingAs($admin)->get("/admin/users/{$target->id}/edit");

        $response->assertOk();
        $response->assertInertia(fn ($page) => $page
            ->component('Admin/Users/Edit')
            ->has('user')
            ->where('user.has_password', false)
        );
    }

    public function test_user_edit_shows_has_password_true_when_set(): void
    {
        $admin = $this->createAdminUser();
        $target = User::factory()->withPassword('existing')->create();

        $response = $this->actingAs($admin)->get("/admin/users/{$target->id}/edit");

        $response->assertInertia(fn ($page) => $page
            ->where('user.has_password', true)
        );
    }

    public function test_admin_can_set_user_password(): void
    {
        $admin = $this->createAdminUser();
        $target = User::factory()->create();

        $response = $this->actingAs($admin)->put("/admin/users/{$target->id}", [
            'nickname' => $target->nickname,
            'email' => $target->email,
            'password' => 'newpassword123',
            'password_confirmation' => 'newpassword123',
        ]);

        $response->assertRedirect();
        $target->refresh();
        $this->assertTrue(Hash::check('newpassword123', $target->password));
    }

    public function test_admin_can_update_user_without_changing_password(): void
    {
        $admin = $this->createAdminUser();
        $target = User::factory()->withPassword('existing')->create();

        $response = $this->actingAs($admin)->put("/admin/users/{$target->id}", [
            'nickname' => 'Updated Name',
            'email' => $target->email,
        ]);

        $response->assertRedirect();
        $target->refresh();
        $this->assertEquals('Updated Name', $target->nickname);
        $this->assertTrue(Hash::check('existing', $target->password));
    }

    public function test_admin_can_clear_user_password(): void
    {
        $admin = $this->createAdminUser();
        $target = User::factory()->withPassword('oldpass')->create();

        $response = $this->actingAs($admin)->put("/admin/users/{$target->id}", [
            'nickname' => $target->nickname,
            'email' => $target->email,
            'clear_password' => true,
        ]);

        $response->assertRedirect();
        $target->refresh();
        $this->assertNull($target->password);
    }

    public function test_password_must_be_confirmed(): void
    {
        $admin = $this->createAdminUser();
        $target = User::factory()->create();

        $response = $this->actingAs($admin)->put("/admin/users/{$target->id}", [
            'nickname' => $target->nickname,
            'email' => $target->email,
            'password' => 'newpassword123',
            'password_confirmation' => 'different',
        ]);

        $response->assertSessionHasErrors('password');
    }

    public function test_password_must_be_at_least_8_characters(): void
    {
        $admin = $this->createAdminUser();
        $target = User::factory()->create();

        $response = $this->actingAs($admin)->put("/admin/users/{$target->id}", [
            'nickname' => $target->nickname,
            'email' => $target->email,
            'password' => 'short',
            'password_confirmation' => 'short',
        ]);

        $response->assertSessionHasErrors('password');
    }

    public function test_non_admin_cannot_edit_users(): void
    {
        $user = User::factory()->create();
        $target = User::factory()->create();

        $response = $this->actingAs($user)->get("/admin/users/{$target->id}/edit");

        $response->assertForbidden();
    }
}
