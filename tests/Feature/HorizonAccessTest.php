<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\Role;
use App\Models\User;
use Illuminate\Foundation\Testing\LazilyRefreshDatabase;
use Tests\TestCase;

class HorizonAccessTest extends TestCase
{
    use LazilyRefreshDatabase;

    public function test_admin_can_open_horizon(): void
    {
        $admin = User::factory()->create();
        $role = new Role;
        $role->code = 'admin';
        $role->name = 'Admin';
        $role->save();
        $admin->roles()->attach($role);

        $this->actingAs($admin)->get('/horizon')->assertOk();
    }

    public function test_non_admin_gets_403(): void
    {
        $this->actingAs(User::factory()->create())->get('/horizon')->assertForbidden();
    }
}
