<?php

namespace Tests\Feature\Policies;

use App\Models\Role;
use App\Models\User;
use App\Policies\UserPolicy;
use Illuminate\Foundation\Testing\LazilyRefreshDatabase;
use Tests\TestCase;

class UserPolicyTest extends TestCase
{
    use LazilyRefreshDatabase;

    private User $admin;

    private User $regularUser;

    private UserPolicy $policy;

    protected function setUp(): void
    {
        parent::setUp();

        $this->policy = new UserPolicy;

        $adminRole = new Role;
        $adminRole->name = 'Admin';
        $adminRole->code = 'admin';
        $adminRole->save();

        $this->admin = User::factory()->create();
        $this->admin->roles()->attach($adminRole);

        $this->regularUser = User::factory()->create();
    }

    public function test_admin_can_view_any_users(): void
    {
        $this->assertTrue($this->policy->viewAny($this->admin));
    }

    public function test_non_admin_cannot_view_any_users(): void
    {
        $this->assertFalse($this->policy->viewAny($this->regularUser));
    }

    public function test_admin_can_view_a_user(): void
    {
        $target = User::factory()->create();
        $this->assertTrue($this->policy->view($this->admin, $target));
    }

    public function test_non_admin_cannot_view_a_user(): void
    {
        $target = User::factory()->create();
        $this->assertFalse($this->policy->view($this->regularUser, $target));
    }

    public function test_admin_can_create_users(): void
    {
        $this->assertTrue($this->policy->create($this->admin));
    }

    public function test_non_admin_cannot_create_users(): void
    {
        $this->assertFalse($this->policy->create($this->regularUser));
    }

    public function test_admin_can_update_a_user(): void
    {
        $target = User::factory()->create();
        $this->assertTrue($this->policy->update($this->admin, $target));
    }

    public function test_non_admin_cannot_update_a_user(): void
    {
        $target = User::factory()->create();
        $this->assertFalse($this->policy->update($this->regularUser, $target));
    }

    public function test_admin_can_delete_a_user(): void
    {
        $target = User::factory()->create();
        $this->assertTrue($this->policy->delete($this->admin, $target));
    }

    public function test_non_admin_cannot_delete_a_user(): void
    {
        $target = User::factory()->create();
        $this->assertFalse($this->policy->delete($this->regularUser, $target));
    }

    public function test_admin_can_restore_a_user(): void
    {
        $target = User::factory()->create();
        $this->assertTrue($this->policy->restore($this->admin, $target));
    }

    public function test_non_admin_cannot_restore_a_user(): void
    {
        $target = User::factory()->create();
        $this->assertFalse($this->policy->restore($this->regularUser, $target));
    }

    public function test_admin_can_force_delete_a_user(): void
    {
        $target = User::factory()->create();
        $this->assertTrue($this->policy->forceDelete($this->admin, $target));
    }

    public function test_non_admin_cannot_force_delete_a_user(): void
    {
        $target = User::factory()->create();
        $this->assertFalse($this->policy->forceDelete($this->regularUser, $target));
    }
}
