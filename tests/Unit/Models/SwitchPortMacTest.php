<?php

declare(strict_types=1);

namespace Tests\Unit\Models;

use App\Models\SwitchPort;
use App\Models\SwitchPortMac;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Tests\TestCase;

class SwitchPortMacTest extends TestCase
{
    use RefreshDatabase;

    public function test_factory_creates_valid_instance(): void
    {
        $mac = SwitchPortMac::factory()->create();

        $this->assertInstanceOf(SwitchPortMac::class, $mac);
        $this->assertNotNull($mac->id);
        $this->assertNotNull($mac->mac_address);
    }

    public function test_fillable_fields(): void
    {
        $port = SwitchPort::factory()->create();

        $mac = SwitchPortMac::factory()->create([
            'switch_port_id' => $port->id,
            'mac_address' => 'AA:BB:CC:DD:EE:FF',
            'vlan' => 100,
            'last_seen_at' => now(),
        ]);

        $this->assertDatabaseHas('switch_port_macs', [
            'id' => $mac->id,
            'switch_port_id' => $port->id,
            'mac_address' => 'AA:BB:CC:DD:EE:FF',
            'vlan' => 100,
        ]);
    }

    public function test_last_seen_at_cast_to_datetime(): void
    {
        $mac = SwitchPortMac::factory()->create([
            'last_seen_at' => '2025-06-15 12:00:00',
        ]);

        $mac->refresh();

        $this->assertInstanceOf(Carbon::class, $mac->last_seen_at);
    }

    public function test_belongs_to_switch_port(): void
    {
        $port = SwitchPort::factory()->create();
        $mac = SwitchPortMac::factory()->create([
            'switch_port_id' => $port->id,
        ]);

        $this->assertInstanceOf(SwitchPort::class, $mac->switchPort);
        $this->assertTrue($mac->switchPort->is($port));
    }

    public function test_unique_constraint_on_switch_port_id_mac_address_and_vlan(): void
    {
        $port = SwitchPort::factory()->create();

        SwitchPortMac::factory()->create([
            'switch_port_id' => $port->id,
            'mac_address' => 'AA:BB:CC:DD:EE:FF',
            'vlan' => 100,
        ]);

        $this->expectException(QueryException::class);

        SwitchPortMac::factory()->create([
            'switch_port_id' => $port->id,
            'mac_address' => 'AA:BB:CC:DD:EE:FF',
            'vlan' => 100,
        ]);
    }

    public function test_same_mac_address_allowed_on_different_vlans(): void
    {
        $port = SwitchPort::factory()->create();

        $mac1 = SwitchPortMac::factory()->create([
            'switch_port_id' => $port->id,
            'mac_address' => 'AA:BB:CC:DD:EE:FF',
            'vlan' => 100,
        ]);

        $mac2 = SwitchPortMac::factory()->create([
            'switch_port_id' => $port->id,
            'mac_address' => 'AA:BB:CC:DD:EE:FF',
            'vlan' => 200,
        ]);

        $this->assertDatabaseHas('switch_port_macs', ['id' => $mac1->id]);
        $this->assertDatabaseHas('switch_port_macs', ['id' => $mac2->id]);
    }

    public function test_same_mac_address_allowed_on_different_ports(): void
    {
        $port1 = SwitchPort::factory()->create();
        $port2 = SwitchPort::factory()->create();

        $mac1 = SwitchPortMac::factory()->create([
            'switch_port_id' => $port1->id,
            'mac_address' => 'AA:BB:CC:DD:EE:FF',
            'vlan' => 100,
        ]);

        $mac2 = SwitchPortMac::factory()->create([
            'switch_port_id' => $port2->id,
            'mac_address' => 'AA:BB:CC:DD:EE:FF',
            'vlan' => 100,
        ]);

        $this->assertDatabaseHas('switch_port_macs', ['id' => $mac1->id]);
        $this->assertDatabaseHas('switch_port_macs', ['id' => $mac2->id]);
    }

    public function test_cascading_delete_when_switch_port_deleted(): void
    {
        $port = SwitchPort::factory()->create();
        $mac = SwitchPortMac::factory()->create([
            'switch_port_id' => $port->id,
        ]);

        $port->delete();

        $this->assertDatabaseMissing('switch_port_macs', ['id' => $mac->id]);
    }

    public function test_vlan_is_nullable(): void
    {
        $mac = SwitchPortMac::factory()->create([
            'vlan' => null,
        ]);

        $mac->refresh();

        $this->assertNull($mac->vlan);
    }
}
