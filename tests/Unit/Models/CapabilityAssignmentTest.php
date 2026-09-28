<?php

declare(strict_types=1);

namespace Tests\Unit\Models;

use App\Enums\Capability;
use App\Models\CapabilityAssignment;
use Illuminate\Foundation\Testing\LazilyRefreshDatabase;
use Illuminate\Support\Facades\Queue;
use Tests\TestCase;

class CapabilityAssignmentTest extends TestCase
{
    use LazilyRefreshDatabase;

    public function test_can_assign_capability_to_integration(): void
    {
        Queue::fake();

        $assignment = CapabilityAssignment::assign(Capability::Dhcp, 'opnsense');

        $this->assertInstanceOf(CapabilityAssignment::class, $assignment);
        $this->assertDatabaseHas('capability_assignments', [
            'capability' => Capability::Dhcp,
            'integration' => 'opnsense',
        ]);
    }

    public function test_assign_replaces_previous_provider(): void
    {
        Queue::fake();

        CapabilityAssignment::assign(Capability::Dhcp, 'opnsense');
        CapabilityAssignment::assign(Capability::Dhcp, 'pihole');

        $this->assertTrue(CapabilityAssignment::isActiveProvider('pihole', Capability::Dhcp));
        $this->assertFalse(CapabilityAssignment::isActiveProvider('opnsense', Capability::Dhcp));
        $this->assertDatabaseHas('capability_assignments', [
            'capability' => Capability::Dhcp,
            'integration' => 'pihole',
        ]);
        $this->assertDatabaseMissing('capability_assignments', [
            'capability' => Capability::Dhcp,
            'integration' => 'opnsense',
        ]);
        $this->assertSame(1, CapabilityAssignment::query()->where('capability', Capability::Dhcp)->count());
    }

    public function test_can_unassign_capability(): void
    {
        Queue::fake();

        CapabilityAssignment::assign(Capability::Dhcp, 'opnsense');
        CapabilityAssignment::unassign(Capability::Dhcp);

        $this->assertDatabaseMissing('capability_assignments', [
            'capability' => Capability::Dhcp,
        ]);
    }

    public function test_is_active_provider_returns_true_when_assigned(): void
    {
        Queue::fake();

        CapabilityAssignment::assign(Capability::Dhcp, 'opnsense');

        $this->assertTrue(CapabilityAssignment::isActiveProvider('opnsense', Capability::Dhcp));
    }

    public function test_is_active_provider_returns_false_when_not_assigned(): void
    {
        Queue::fake();

        $this->assertFalse(CapabilityAssignment::isActiveProvider('opnsense', Capability::Dhcp));
    }

    public function test_get_for_integration_returns_all_capabilities(): void
    {
        Queue::fake();

        CapabilityAssignment::assign(Capability::Dhcp, 'opnsense');
        CapabilityAssignment::assign(Capability::Authentication, 'opnsense');

        $capabilities = CapabilityAssignment::getForIntegration('opnsense');

        $this->assertCount(2, $capabilities);
        $this->assertEqualsCanonicalizing([Capability::Dhcp, Capability::Authentication], $capabilities->all());
    }

    public function test_get_for_integration_returns_empty_when_none(): void
    {
        Queue::fake();

        $capabilities = CapabilityAssignment::getForIntegration('opnsense');

        $this->assertTrue($capabilities->isEmpty());
    }

    public function test_factory_creates_valid_model(): void
    {
        Queue::fake();

        $assignment = CapabilityAssignment::factory()->create();

        $this->assertInstanceOf(CapabilityAssignment::class, $assignment);
        $this->assertNotNull($assignment->id);
        $this->assertNotNull($assignment->capability);
        $this->assertNotNull($assignment->integration);
    }
}
