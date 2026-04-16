<?php

declare(strict_types=1);

namespace Tests\Feature\Admin;

use App\Models\Role;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Queue;
use Tests\TestCase;

class UserControllerPerPageValidationTest extends TestCase
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

    public function test_index_rejects_non_integer_per_page(): void
    {
        Queue::fake();
        $admin = $this->createAdminUser();

        $response = $this->actingAs($admin)->get('/admin/users?perPage=abc');

        $response->assertSessionHasErrors('perPage');
    }

    public function test_index_rejects_per_page_below_one(): void
    {
        Queue::fake();
        $admin = $this->createAdminUser();

        $response = $this->actingAs($admin)->get('/admin/users?perPage=0');

        $response->assertSessionHasErrors('perPage');
    }

    public function test_index_rejects_per_page_above_100(): void
    {
        Queue::fake();
        $admin = $this->createAdminUser();

        $response = $this->actingAs($admin)->get('/admin/users?perPage=101');

        $response->assertSessionHasErrors('perPage');
    }

    public function test_index_accepts_valid_per_page(): void
    {
        Queue::fake();
        $admin = $this->createAdminUser();

        $response = $this->actingAs($admin)->get('/admin/users?perPage=50');

        $response->assertOk();
    }

    public function test_index_works_without_per_page(): void
    {
        Queue::fake();
        $admin = $this->createAdminUser();

        $response = $this->actingAs($admin)->get('/admin/users');

        $response->assertOk();
    }
}
