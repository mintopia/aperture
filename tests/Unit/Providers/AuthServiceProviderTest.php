<?php

namespace Tests\Unit\Providers;

use App\Models\IntegrationConfig;
use App\Models\IpAddress;
use App\Models\Role;
use App\Models\SwitchConfig;
use App\Models\User;
use App\Policies\IntegrationConfigPolicy;
use App\Policies\IpAddressPolicy;
use App\Policies\SwitchConfigPolicy;
use App\Policies\UserPolicy;
use Illuminate\Foundation\Testing\LazilyRefreshDatabase;
use Illuminate\Support\Facades\Gate;
use Tests\TestCase;

class AuthServiceProviderTest extends TestCase
{
    use LazilyRefreshDatabase;

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

    public function test_user_policy_is_registered(): void
    {
        $this->assertInstanceOf(UserPolicy::class, Gate::getPolicyFor(User::class));
    }

    public function test_ip_address_policy_is_registered(): void
    {
        $this->assertInstanceOf(IpAddressPolicy::class, Gate::getPolicyFor(IpAddress::class));
    }

    public function test_switch_config_policy_is_registered(): void
    {
        $this->assertInstanceOf(SwitchConfigPolicy::class, Gate::getPolicyFor(SwitchConfig::class));
    }

    public function test_integration_config_policy_is_registered(): void
    {
        $this->assertInstanceOf(IntegrationConfigPolicy::class, Gate::getPolicyFor(IntegrationConfig::class));
    }
}
