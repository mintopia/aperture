<?php

declare(strict_types=1);

namespace Tests\Unit\Enums;

use App\Enums\Capability;
use App\Enums\Integration;
use App\Enums\PortOperStatus;
use PHPUnit\Framework\TestCase;

class IntegrationTest extends TestCase
{
    public function test_integration_enum_has_all_integrations(): void
    {
        $cases = Integration::cases();
        $values = array_map(fn (Integration $c) => $c->value, $cases);
        $this->assertContains('opnsense', $values);
        $this->assertContains('pihole', $values);
        $this->assertContains('librenms', $values);
        $this->assertContains('borealis', $values);
        $this->assertContains('prometheus', $values);
        $this->assertContains('seatpicker', $values);
        $this->assertContains('vyos', $values);
        $this->assertContains('cisco', $values);
    }

    public function test_cisco_integration_exists(): void
    {
        $this->assertSame('cisco', Integration::Cisco->value);
    }

    public function test_capability_enum_has_known_capabilities(): void
    {
        $values = array_map(fn (Capability $c) => $c->value, Capability::cases());
        foreach (['captive-portal', 'rate-limiting', 'dhcp', 'dns-filtering', 'ip-bandwidth', 'port-bandwidth', 'port-errors', 'ip-mac', 'port-mac', 'authentication'] as $cap) {
            $this->assertContains($cap, $values, 'Missing capability: '.$cap);
        }
    }

    public function test_port_oper_status_up_is_up(): void
    {
        $status = PortOperStatus::from('up');
        $this->assertSame(PortOperStatus::Up, $status);
        $this->assertTrue($status->isUp());
    }

    public function test_port_oper_status_is_case_insensitive_via_try_from(): void
    {
        $this->assertSame(PortOperStatus::Down, PortOperStatus::tryFrom('down'));
        $this->assertNull(PortOperStatus::tryFrom('unknown_value'));
    }

    public function test_integration_try_from_unknown_returns_null(): void
    {
        $this->assertNull(Integration::tryFrom('nonexistent'));
    }
}
