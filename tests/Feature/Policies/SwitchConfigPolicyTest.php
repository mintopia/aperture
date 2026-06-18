<?php

namespace Tests\Feature\Policies;

use App\Models\Role;
use App\Models\SwitchConfig;
use App\Models\User;
use App\Policies\SwitchConfigPolicy;
use Illuminate\Foundation\Testing\LazilyRefreshDatabase;
use Tests\TestCase;

class SwitchConfigPolicyTest extends TestCase
{
    use LazilyRefreshDatabase;

    private User $admin;

    private User $regularUser;

    private SwitchConfigPolicy $policy;

    protected function setUp(): void
    {
        parent::setUp();

        $this->policy = new SwitchConfigPolicy;

        $adminRole = new Role;
        $adminRole->name = 'Admin';
        $adminRole->code = 'admin';
        $adminRole->save();

        $this->admin = User::factory()->create();
        $this->admin->roles()->attach($adminRole);

        $this->regularUser = User::factory()->create();
    }

    private function createSwitchConfig(): SwitchConfig
    {
        $switchConfig = new SwitchConfig;
        $switchConfig->name = 'Test Switch';
        $switchConfig->hostname = 'switch-'.uniqid();
        $switchConfig->type = 'cisco';
        $switchConfig->username = 'admin';
        $switchConfig->password = 'secret';
        $switchConfig->enabled = true;
        $switchConfig->port = 22;
        $switchConfig->timeout = 30;
        $switchConfig->save();

        return $switchConfig;
    }

    public function test_admin_can_view_any_switch_configs(): void
    {
        $this->assertTrue($this->policy->viewAny($this->admin));
    }

    public function test_non_admin_cannot_view_any_switch_configs(): void
    {
        $this->assertFalse($this->policy->viewAny($this->regularUser));
    }

    public function test_admin_can_view_a_switch_config(): void
    {
        $switchConfig = $this->createSwitchConfig();
        $this->assertTrue($this->policy->view($this->admin, $switchConfig));
    }

    public function test_non_admin_cannot_view_a_switch_config(): void
    {
        $switchConfig = $this->createSwitchConfig();
        $this->assertFalse($this->policy->view($this->regularUser, $switchConfig));
    }

    public function test_admin_can_create_switch_configs(): void
    {
        $this->assertTrue($this->policy->create($this->admin));
    }

    public function test_non_admin_cannot_create_switch_configs(): void
    {
        $this->assertFalse($this->policy->create($this->regularUser));
    }

    public function test_admin_can_update_a_switch_config(): void
    {
        $switchConfig = $this->createSwitchConfig();
        $this->assertTrue($this->policy->update($this->admin, $switchConfig));
    }

    public function test_non_admin_cannot_update_a_switch_config(): void
    {
        $switchConfig = $this->createSwitchConfig();
        $this->assertFalse($this->policy->update($this->regularUser, $switchConfig));
    }

    public function test_admin_can_delete_a_switch_config(): void
    {
        $switchConfig = $this->createSwitchConfig();
        $this->assertTrue($this->policy->delete($this->admin, $switchConfig));
    }

    public function test_non_admin_cannot_delete_a_switch_config(): void
    {
        $switchConfig = $this->createSwitchConfig();
        $this->assertFalse($this->policy->delete($this->regularUser, $switchConfig));
    }

    public function test_admin_can_restore_a_switch_config(): void
    {
        $switchConfig = $this->createSwitchConfig();
        $this->assertTrue($this->policy->restore($this->admin, $switchConfig));
    }

    public function test_non_admin_cannot_restore_a_switch_config(): void
    {
        $switchConfig = $this->createSwitchConfig();
        $this->assertFalse($this->policy->restore($this->regularUser, $switchConfig));
    }

    public function test_admin_can_force_delete_a_switch_config(): void
    {
        $switchConfig = $this->createSwitchConfig();
        $this->assertTrue($this->policy->forceDelete($this->admin, $switchConfig));
    }

    public function test_non_admin_cannot_force_delete_a_switch_config(): void
    {
        $switchConfig = $this->createSwitchConfig();
        $this->assertFalse($this->policy->forceDelete($this->regularUser, $switchConfig));
    }
}
