<?php

namespace Tests\Feature\Policies;

use App\Models\IntegrationConfig;
use App\Models\Role;
use App\Models\User;
use App\Policies\IntegrationConfigPolicy;
use Illuminate\Foundation\Testing\LazilyRefreshDatabase;
use Tests\TestCase;

class IntegrationConfigPolicyTest extends TestCase
{
    use LazilyRefreshDatabase;

    private User $admin;

    private User $regularUser;

    private IntegrationConfigPolicy $policy;

    protected function setUp(): void
    {
        parent::setUp();

        $this->policy = new IntegrationConfigPolicy;

        $adminRole = new Role;
        $adminRole->name = 'Admin';
        $adminRole->code = 'admin';
        $adminRole->save();

        $this->admin = User::factory()->create();
        $this->admin->roles()->attach($adminRole);

        $this->regularUser = User::factory()->create();
    }

    private function createIntegrationConfig(): IntegrationConfig
    {
        $config = new IntegrationConfig;
        $config->integration = 'test-service';
        $config->key = 'test-key';
        $config->value = 'test-value';
        $config->save();

        return $config;
    }

    public function test_admin_can_view_any_integration_configs(): void
    {
        $this->assertTrue($this->policy->viewAny($this->admin));
    }

    public function test_non_admin_cannot_view_any_integration_configs(): void
    {
        $this->assertFalse($this->policy->viewAny($this->regularUser));
    }

    public function test_admin_can_view_an_integration_config(): void
    {
        $config = $this->createIntegrationConfig();
        $this->assertTrue($this->policy->view($this->admin, $config));
    }

    public function test_non_admin_cannot_view_an_integration_config(): void
    {
        $config = $this->createIntegrationConfig();
        $this->assertFalse($this->policy->view($this->regularUser, $config));
    }

    public function test_admin_can_create_integration_configs(): void
    {
        $this->assertTrue($this->policy->create($this->admin));
    }

    public function test_non_admin_cannot_create_integration_configs(): void
    {
        $this->assertFalse($this->policy->create($this->regularUser));
    }

    public function test_admin_can_update_an_integration_config(): void
    {
        $config = $this->createIntegrationConfig();
        $this->assertTrue($this->policy->update($this->admin, $config));
    }

    public function test_non_admin_cannot_update_an_integration_config(): void
    {
        $config = $this->createIntegrationConfig();
        $this->assertFalse($this->policy->update($this->regularUser, $config));
    }

    public function test_admin_can_delete_an_integration_config(): void
    {
        $config = $this->createIntegrationConfig();
        $this->assertTrue($this->policy->delete($this->admin, $config));
    }

    public function test_non_admin_cannot_delete_an_integration_config(): void
    {
        $config = $this->createIntegrationConfig();
        $this->assertFalse($this->policy->delete($this->regularUser, $config));
    }

    public function test_admin_can_restore_an_integration_config(): void
    {
        $config = $this->createIntegrationConfig();
        $this->assertTrue($this->policy->restore($this->admin, $config));
    }

    public function test_non_admin_cannot_restore_an_integration_config(): void
    {
        $config = $this->createIntegrationConfig();
        $this->assertFalse($this->policy->restore($this->regularUser, $config));
    }

    public function test_admin_can_force_delete_an_integration_config(): void
    {
        $config = $this->createIntegrationConfig();
        $this->assertTrue($this->policy->forceDelete($this->admin, $config));
    }

    public function test_non_admin_cannot_force_delete_an_integration_config(): void
    {
        $config = $this->createIntegrationConfig();
        $this->assertFalse($this->policy->forceDelete($this->regularUser, $config));
    }
}
