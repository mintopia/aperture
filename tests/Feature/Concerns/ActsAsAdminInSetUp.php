<?php

declare(strict_types=1);

namespace Tests\Feature\Concerns;

use App\Models\Role;
use App\Models\User;
use Illuminate\Support\Facades\Queue;

/**
 * Shared setUp() boilerplate duplicated across several Admin settings-controller
 * Feature tests that eagerly authenticate as an admin for every test (as
 * opposed to CreatesAdminUsers, whose createAdminUser() is called per-test).
 * Not a test itself — consuming classes must still `use LazilyRefreshDatabase, ActsAsAdminInSetUp;`.
 */
trait ActsAsAdminInSetUp
{
    protected User $admin;

    protected function setUp(): void
    {
        parent::setUp();
        Queue::fake();
        $this->admin = $this->createAdminUser();
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
}
