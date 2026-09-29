<?php

declare(strict_types=1);

namespace Tests\Feature\Admin;

use App\Enums\Capability;
use App\Models\CapabilityAssignment;
use App\Models\Role;
use App\Models\User;
use Illuminate\Foundation\Testing\LazilyRefreshDatabase;
use Illuminate\Support\Facades\Queue;
use Tests\TestCase;

class IntegrationControllerCapabilityToggleScopeTest extends TestCase
{
    use LazilyRefreshDatabase;

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

    public function test_deactivate_capability_only_removes_for_specified_integration(): void
    {
        Queue::fake();
        $admin = $this->createAdminUser();

        CapabilityAssignment::assign(Capability::Dhcp, 'opnsense');

        $response = $this->actingAs($admin)->putJson('/admin/settings/capabilities', [
            'capability' => 'dhcp',
            'integration' => 'opnsense',
            'active' => false,
        ]);

        $response->assertOk();

        $this->assertDatabaseMissing('capability_assignments', [
            'capability' => 'dhcp',
            'integration' => 'opnsense',
        ]);
    }

    public function test_deactivate_does_not_remove_capability_owned_by_another_integration(): void
    {
        Queue::fake();
        $admin = $this->createAdminUser();

        CapabilityAssignment::assign(Capability::Dhcp, 'opnsense');

        $response = $this->actingAs($admin)->putJson('/admin/settings/capabilities', [
            'capability' => 'dhcp',
            'integration' => 'pihole',
            'active' => false,
        ]);

        $response->assertUnprocessable();

        $this->assertDatabaseHas('capability_assignments', [
            'capability' => 'dhcp',
            'integration' => 'opnsense',
        ]);
    }
}
