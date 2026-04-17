<?php

declare(strict_types=1);

namespace Tests\Unit\Models;

use App\Models\SwitchConfig;
use App\Models\SwitchPort;
use App\Models\SwitchPortConfig;
use App\Models\SwitchPortMac;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Tests\TestCase;

class SwitchPortTest extends TestCase
{
    use RefreshDatabase;

    public function test_factory_creates_valid_instance(): void
    {
        $port = SwitchPort::factory()->create();

        $this->assertInstanceOf(SwitchPort::class, $port);
        $this->assertNotNull($port->id);
        $this->assertNotNull($port->port_name);
        $this->assertNotNull($port->port_number);
    }

    public function test_fillable_fields(): void
    {
        $switchConfig = SwitchConfig::factory()->create();

        $port = SwitchPort::factory()->create([
            'switch_config_id' => $switchConfig->id,
            'port_name' => 'GigabitEthernet1/0/24',
            'port_number' => 'Gi1/0/24',
            'switch_description' => 'Uplink to core',
            'admin_notes' => 'Reserved for server rack',
            'access_vlan' => 100,
            'switchport_mode' => 'access',
            'speed' => '1000',
            'status' => 'up',
            'admin_status' => 'up',
            'duplex' => 'full',
            'poe_status' => 'on',
            'last_synced_at' => now(),
        ]);

        $this->assertDatabaseHas('switch_ports', [
            'id' => $port->id,
            'switch_config_id' => $switchConfig->id,
            'port_name' => 'GigabitEthernet1/0/24',
            'port_number' => 'Gi1/0/24',
            'switch_description' => 'Uplink to core',
            'admin_notes' => 'Reserved for server rack',
            'access_vlan' => 100,
            'switchport_mode' => 'access',
            'speed' => '1000',
            'status' => 'up',
            'admin_status' => 'up',
            'duplex' => 'full',
            'poe_status' => 'on',
        ]);
    }

    public function test_last_synced_at_cast_to_datetime(): void
    {
        $port = SwitchPort::factory()->create([
            'last_synced_at' => '2025-06-15 12:00:00',
        ]);

        $port->refresh();

        $this->assertInstanceOf(Carbon::class, $port->last_synced_at);
    }

    public function test_belongs_to_switch_config(): void
    {
        $switchConfig = SwitchConfig::factory()->create();
        $port = SwitchPort::factory()->create([
            'switch_config_id' => $switchConfig->id,
        ]);

        $this->assertInstanceOf(SwitchConfig::class, $port->switchConfig);
        $this->assertTrue($port->switchConfig->is($switchConfig));
    }

    public function test_has_many_switch_port_macs(): void
    {
        $port = SwitchPort::factory()->create();
        $mac1 = SwitchPortMac::factory()->create(['switch_port_id' => $port->id]);
        $mac2 = SwitchPortMac::factory()->create(['switch_port_id' => $port->id]);

        $this->assertCount(2, $port->switchPortMacs);
        $this->assertTrue($port->switchPortMacs->contains($mac1));
        $this->assertTrue($port->switchPortMacs->contains($mac2));
    }

    public function test_has_one_switch_port_config(): void
    {
        $port = SwitchPort::factory()->create();
        $config = SwitchPortConfig::factory()->create([
            'switch_port_id' => $port->id,
        ]);

        $this->assertInstanceOf(SwitchPortConfig::class, $port->switchPortConfig);
        $this->assertTrue($port->switchPortConfig->is($config));
    }

    public function test_unique_constraint_on_switch_config_id_and_port_name(): void
    {
        $switchConfig = SwitchConfig::factory()->create();

        SwitchPort::factory()->create([
            'switch_config_id' => $switchConfig->id,
            'port_name' => 'GigabitEthernet1/0/1',
        ]);

        $this->expectException(QueryException::class);

        SwitchPort::factory()->create([
            'switch_config_id' => $switchConfig->id,
            'port_name' => 'GigabitEthernet1/0/1',
        ]);
    }

    public function test_same_port_name_allowed_on_different_switches(): void
    {
        $switch1 = SwitchConfig::factory()->create();
        $switch2 = SwitchConfig::factory()->create();

        $port1 = SwitchPort::factory()->create([
            'switch_config_id' => $switch1->id,
            'port_name' => 'GigabitEthernet1/0/1',
        ]);

        $port2 = SwitchPort::factory()->create([
            'switch_config_id' => $switch2->id,
            'port_name' => 'GigabitEthernet1/0/1',
        ]);

        $this->assertDatabaseHas('switch_ports', ['id' => $port1->id]);
        $this->assertDatabaseHas('switch_ports', ['id' => $port2->id]);
    }

    public function test_cascading_delete_when_switch_config_deleted(): void
    {
        $switchConfig = SwitchConfig::factory()->create();
        $port = SwitchPort::factory()->create([
            'switch_config_id' => $switchConfig->id,
        ]);

        $switchConfig->delete();

        $this->assertDatabaseMissing('switch_ports', ['id' => $port->id]);
    }

    public function test_nullable_fields_accept_null(): void
    {
        $port = SwitchPort::factory()->create([
            'switch_description' => null,
            'admin_notes' => null,
            'access_vlan' => null,
            'switchport_mode' => null,
            'speed' => null,
            'duplex' => null,
            'poe_status' => null,
        ]);

        $port->refresh();

        $this->assertNull($port->switch_description);
        $this->assertNull($port->admin_notes);
        $this->assertNull($port->access_vlan);
        $this->assertNull($port->switchport_mode);
        $this->assertNull($port->speed);
        $this->assertNull($port->duplex);
        $this->assertNull($port->poe_status);
    }

    public function test_factory_down_state(): void
    {
        $port = SwitchPort::factory()->down()->create();

        $this->assertSame('down', $port->status);
    }

    public function test_factory_admin_down_state(): void
    {
        $port = SwitchPort::factory()->adminDown()->create();

        $this->assertSame('down', $port->admin_status);
        $this->assertSame('down', $port->status);
    }

    public function test_factory_trunk_state(): void
    {
        $port = SwitchPort::factory()->trunk()->create();

        $this->assertSame('trunk', $port->switchport_mode);
        $this->assertNull($port->access_vlan);
    }

    public function test_factory_access_state(): void
    {
        $port = SwitchPort::factory()->access()->create();

        $this->assertSame('access', $port->switchport_mode);
        $this->assertNotNull($port->access_vlan);
    }
}
