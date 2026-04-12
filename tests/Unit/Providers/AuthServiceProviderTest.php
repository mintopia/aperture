<?php

namespace Tests\Unit\Providers;

use App\Models\Role;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Gate;
use Tests\TestCase;

class AuthServiceProviderTest extends TestCase
{
    use RefreshDatabase;

    public function test_admin_gate_returns_true_for_admin_user(): void
    {
        $user = User::factory()->create();
        $role = new Role;
        $role->name = 'Admin';
        $role->code = 'admin';
        $role->save();
        $user->roles()->attach($role);

        $this->assertTrue(Gate::forUser($user)->allows('admin'));
    }

    public function test_admin_gate_returns_false_for_non_admin_user(): void
    {
        $user = User::factory()->create();
        $this->assertFalse(Gate::forUser($user)->allows('admin'));
    }

    public function test_view_web_sockets_dashboard_gate_for_admin(): void
    {
        $user = User::factory()->create();
        $role = new Role;
        $role->name = 'Admin';
        $role->code = 'admin';
        $role->save();
        $user->roles()->attach($role);

        $this->assertTrue(Gate::forUser($user)->allows('viewWebSocketsDashboard'));
    }

    public function test_view_web_sockets_dashboard_gate_for_non_admin(): void
    {
        $user = User::factory()->create();
        $this->assertFalse(Gate::forUser($user)->allows('viewWebSocketsDashboard'));
    }
}
