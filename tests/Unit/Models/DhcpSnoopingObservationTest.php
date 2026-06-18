<?php

declare(strict_types=1);

namespace Tests\Unit\Models;

use App\Models\DhcpSnoopingObservation;
use App\Models\SwitchConfig;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\LazilyRefreshDatabase;
use Tests\TestCase;

class DhcpSnoopingObservationTest extends TestCase
{
    use LazilyRefreshDatabase;

    public function test_can_create_observation(): void
    {
        $observation = DhcpSnoopingObservation::factory()->create();

        $this->assertDatabaseHas('dhcp_snooping_observations', [
            'id' => $observation->id,
            'vlan' => 100,
            'ip' => '10.0.0.50',
            'mac' => 'AA:BB:CC:DD:EE:FF',
        ]);
    }

    public function test_belongs_to_switch_config(): void
    {
        $switchConfig = SwitchConfig::factory()->create();
        $observation = DhcpSnoopingObservation::factory()->create([
            'switch_config_id' => $switchConfig->id,
        ]);

        $this->assertInstanceOf(SwitchConfig::class, $observation->switchConfig);
        $this->assertSame($switchConfig->id, $observation->switchConfig->id);
    }

    public function test_unique_constraint(): void
    {
        $switchConfig = SwitchConfig::factory()->create();

        DhcpSnoopingObservation::factory()->create([
            'switch_config_id' => $switchConfig->id,
            'vlan' => 100,
            'ip' => '10.0.0.50',
            'mac' => 'AA:BB:CC:DD:EE:FF',
        ]);

        $this->expectException(QueryException::class);

        DhcpSnoopingObservation::factory()->create([
            'switch_config_id' => $switchConfig->id,
            'vlan' => 100,
            'ip' => '10.0.0.50',
            'mac' => 'AA:BB:CC:DD:EE:FF',
        ]);
    }

    public function test_cascade_delete_with_switch_config(): void
    {
        $switchConfig = SwitchConfig::factory()->create();
        DhcpSnoopingObservation::factory()->create([
            'switch_config_id' => $switchConfig->id,
            'ip' => '10.0.1.1',
        ]);
        DhcpSnoopingObservation::factory()->create([
            'switch_config_id' => $switchConfig->id,
            'ip' => '10.0.1.2',
        ]);
        DhcpSnoopingObservation::factory()->create([
            'switch_config_id' => $switchConfig->id,
            'ip' => '10.0.1.3',
        ]);

        $this->assertDatabaseCount('dhcp_snooping_observations', 3);

        $switchConfig->delete();

        $this->assertDatabaseCount('dhcp_snooping_observations', 0);
    }
}
