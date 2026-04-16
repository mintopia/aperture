<?php

declare(strict_types=1);

namespace Tests\Unit\Models;

use App\Models\CapabilityAssignment;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Queue;
use Tests\TestCase;

class CapabilityAssignmentTest extends TestCase
{
    use RefreshDatabase;

    public function test_can_assign_capability_to_integration(): void
    {
        Queue::fake();

        $assignment = CapabilityAssignment::assign('dhcp', 'opnsense');

        $this->assertInstanceOf(CapabilityAssignment::class, $assignment);
        $this->assertDatabaseHas('capability_assignments', [
            'capability' => 'dhcp',
            'integration' => 'opnsense',
        ]);
    }

    public function test_assign_replaces_previous_provider(): void
    {
        Queue::fake();

        CapabilityAssignment::assign('dhcp', 'opnsense');
        CapabilityAssignment::assign('dhcp', 'pihole');

        $this->assertTrue(CapabilityAssignment::isActiveProvider('pihole', 'dhcp'));
        $this->assertFalse(CapabilityAssignment::isActiveProvider('opnsense', 'dhcp'));
        $this->assertDatabaseHas('capability_assignments', [
            'capability' => 'dhcp',
            'integration' => 'pihole',
        ]);
        $this->assertDatabaseMissing('capability_assignments', [
            'capability' => 'dhcp',
            'integration' => 'opnsense',
        ]);
        $this->assertSame(1, CapabilityAssignment::query()->count());
    }

    public function test_can_unassign_capability(): void
    {
        Queue::fake();

        CapabilityAssignment::assign('dhcp', 'opnsense');
        CapabilityAssignment::unassign('dhcp');

        $this->assertDatabaseMissing('capability_assignments', [
            'capability' => 'dhcp',
        ]);
    }

    public function test_is_active_provider_returns_true_when_assigned(): void
    {
        Queue::fake();

        CapabilityAssignment::assign('dhcp', 'opnsense');

        $this->assertTrue(CapabilityAssignment::isActiveProvider('opnsense', 'dhcp'));
    }

    public function test_is_active_provider_returns_false_when_not_assigned(): void
    {
        Queue::fake();

        $this->assertFalse(CapabilityAssignment::isActiveProvider('opnsense', 'dhcp'));
    }

    public function test_get_for_integration_returns_all_capabilities(): void
    {
        Queue::fake();

        CapabilityAssignment::assign('dhcp', 'opnsense');
        CapabilityAssignment::assign('firewall', 'opnsense');

        $capabilities = CapabilityAssignment::getForIntegration('opnsense');

        $this->assertCount(2, $capabilities);
        $this->assertEqualsCanonicalizing(['dhcp', 'firewall'], $capabilities->all());
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
