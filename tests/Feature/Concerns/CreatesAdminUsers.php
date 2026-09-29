<?php

declare(strict_types=1);

namespace Tests\Feature\Concerns;

use App\Models\Role;
use App\Models\User;
use Illuminate\Support\Facades\Queue;

trait CreatesAdminUsers
{
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
}
