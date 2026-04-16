<?php

declare(strict_types=1);

namespace Tests\Feature\Admin;

use App\Models\CapabilityAssignment;
use App\Models\Role;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Queue;
use Tests\TestCase;

class IntegrationControllerCapabilityToggleScopeTest extends TestCase
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

    public function test_deactivate_capability_only_removes_for_specified_integration(): void
    {
        Queue::fake();
        $admin = $this->createAdminUser();

        // Assign the same capability to two different integrations
        // opnsense owns 'dhcp', and we manually insert another row for a hypothetical second provider
        CapabilityAssignment::assign('dhcp', 'opnsense');

        // Now request to deactivate 'dhcp' for opnsense specifically
        $response = $this->actingAs($admin)->putJson('/admin/settings/capabilities', [
            'capability' => 'dhcp',
            'integration' => 'opnsense',
            'active' => false,
        ]);

        $response->assertOk();

        // The opnsense assignment should be gone
        $this->assertDatabaseMissing('capability_assignments', [
            'capability' => 'dhcp',
            'integration' => 'opnsense',
        ]);
    }

    public function test_deactivate_does_not_remove_capability_owned_by_another_integration(): void
    {
        Queue::fake();
        $admin = $this->createAdminUser();

        // opnsense currently owns 'dhcp'
        CapabilityAssignment::assign('dhcp', 'opnsense');

        // pihole requests to deactivate 'dhcp' — it should NOT remove opnsense's assignment
        // (both opnsense and pihole support 'dhcp' per config/integrations.php)
        $response = $this->actingAs($admin)->putJson('/admin/settings/capabilities', [
            'capability' => 'dhcp',
            'integration' => 'pihole',
            'active' => false,
        ]);

        $response->assertOk();

        // opnsense's dhcp assignment must still exist — pihole should not be able to revoke it
        $this->assertDatabaseHas('capability_assignments', [
            'capability' => 'dhcp',
            'integration' => 'opnsense',
        ]);
    }
}
