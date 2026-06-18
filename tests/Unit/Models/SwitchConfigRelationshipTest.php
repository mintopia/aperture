<?php

declare(strict_types=1);

namespace Tests\Unit\Models;

use App\Models\SwitchConfig;
use App\Models\SwitchPort;
use App\Models\SwitchPortConfig;
use App\Models\SwitchPortMac;
use App\Models\SwitchSyncRun;
use Illuminate\Foundation\Testing\LazilyRefreshDatabase;
use Tests\TestCase;

class SwitchConfigRelationshipTest extends TestCase
{
    use LazilyRefreshDatabase;

    public function test_has_many_switch_ports(): void
    {
        $switchConfig = SwitchConfig::factory()->create();
        $port1 = SwitchPort::factory()->create([
            'switch_config_id' => $switchConfig->id,
            'port_name' => 'GigabitEthernet1/0/1',
        ]);
        $port2 = SwitchPort::factory()->create([
            'switch_config_id' => $switchConfig->id,
            'port_name' => 'GigabitEthernet1/0/2',
        ]);

        $this->assertCount(2, $switchConfig->switchPorts);
        $this->assertTrue($switchConfig->switchPorts->contains($port1));
        $this->assertTrue($switchConfig->switchPorts->contains($port2));
    }

    public function test_has_many_switch_sync_runs(): void
    {
        $switchConfig = SwitchConfig::factory()->create();
        $run1 = SwitchSyncRun::factory()->create([
            'switch_config_id' => $switchConfig->id,
        ]);
        $run2 = SwitchSyncRun::factory()->create([
            'switch_config_id' => $switchConfig->id,
        ]);

        $this->assertCount(2, $switchConfig->switchSyncRuns);
        $this->assertTrue($switchConfig->switchSyncRuns->contains($run1));
        $this->assertTrue($switchConfig->switchSyncRuns->contains($run2));
    }

    public function test_switch_ports_only_includes_own_ports(): void
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

        $this->assertCount(1, $switch1->switchPorts);
        $this->assertTrue($switch1->switchPorts->contains($port1));
        $this->assertFalse($switch1->switchPorts->contains($port2));
    }

    public function test_cascading_delete_propagates_to_ports(): void
    {
        $switchConfig = SwitchConfig::factory()->create();
        $port = SwitchPort::factory()->create([
            'switch_config_id' => $switchConfig->id,
        ]);

        $switchConfig->delete();

        $this->assertDatabaseMissing('switch_ports', ['id' => $port->id]);
    }

    public function test_cascading_delete_propagates_to_sync_runs(): void
    {
        $switchConfig = SwitchConfig::factory()->create();
        $run = SwitchSyncRun::factory()->create([
            'switch_config_id' => $switchConfig->id,
        ]);

        $switchConfig->delete();

        $this->assertDatabaseMissing('switch_sync_runs', ['id' => $run->id]);
    }

    public function test_cascading_delete_propagates_through_ports_to_macs(): void
    {
        $switchConfig = SwitchConfig::factory()->create();
        $port = SwitchPort::factory()->create([
            'switch_config_id' => $switchConfig->id,
        ]);
        $mac = SwitchPortMac::factory()->create([
            'switch_port_id' => $port->id,
        ]);

        $switchConfig->delete();

        $this->assertDatabaseMissing('switch_ports', ['id' => $port->id]);
        $this->assertDatabaseMissing('switch_port_macs', ['id' => $mac->id]);
    }

    public function test_cascading_delete_propagates_through_ports_to_configs(): void
    {
        $switchConfig = SwitchConfig::factory()->create();
        $port = SwitchPort::factory()->create([
            'switch_config_id' => $switchConfig->id,
        ]);
        $config = SwitchPortConfig::factory()->create([
            'switch_port_id' => $port->id,
        ]);

        $switchConfig->delete();

        $this->assertDatabaseMissing('switch_ports', ['id' => $port->id]);
        $this->assertDatabaseMissing('switch_port_configs', ['id' => $config->id]);
    }
}
