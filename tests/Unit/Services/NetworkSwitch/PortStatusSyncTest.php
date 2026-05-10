<?php

declare(strict_types=1);

namespace Tests\Unit\Services\NetworkSwitch;

use App\Models\SwitchConfig;
use App\Models\SwitchPort;
use App\Services\NetworkSwitch\PortStatusSync;
use App\Services\ValueObjects\PortStatus;
use Illuminate\Foundation\Testing\LazilyRefreshDatabase;
use Illuminate\Support\Carbon;
use Tests\TestCase;

class PortStatusSyncTest extends TestCase
{
    use LazilyRefreshDatabase;

    public function test_creates_new_port_when_not_existing(): void
    {
        $switchConfig = SwitchConfig::factory()->create();
        $sync = new PortStatusSync();

        $portStatus = new PortStatus(
            interface: 'Gi1/0/1',
            status: 'connected',
            adminStatus: 'enabled',
            speed: '1000',
            duplex: 'full',
            description: 'Test port',
            vlan: '10',
            switchportMode: 'access',
        );

        $result = $sync->sync(collect([$portStatus]), $switchConfig, Carbon::now());

        $this->assertDatabaseHas('switch_ports', [
            'switch_config_id' => $switchConfig->id,
            'port_name' => 'Gi1/0/1',
            'status' => 'connected',
        ]);
        $this->assertSame(1, $result['created']);
        $this->assertSame(0, $result['updated']);
    }

    public function test_updates_existing_port(): void
    {
        $switchConfig = SwitchConfig::factory()->create();
        SwitchPort::factory()->create([
            'switch_config_id' => $switchConfig->id,
            'port_name' => 'Gi1/0/1',
            'status' => 'notconnect',
        ]);

        $sync = new PortStatusSync();

        $portStatus = new PortStatus(
            interface: 'Gi1/0/1',
            status: 'connected',
            speed: 'a-1000',
            duplex: 'a-full',
            vlan: '10',
        );

        $result = $sync->sync(collect([$portStatus]), $switchConfig, Carbon::now());

        $this->assertDatabaseHas('switch_ports', [
            'switch_config_id' => $switchConfig->id,
            'port_name' => 'Gi1/0/1',
            'status' => 'connected',
        ]);
        $this->assertSame(0, $result['created']);
        $this->assertSame(1, $result['updated']);
    }

    public function test_detects_port_status_state_changes(): void
    {
        $switchConfig = SwitchConfig::factory()->create();
        SwitchPort::factory()->create([
            'switch_config_id' => $switchConfig->id,
            'port_name' => 'Gi1/0/2',
            'status' => 'notconnect',
        ]);

        $sync = new PortStatusSync();

        $portStatus = new PortStatus(
            interface: 'Gi1/0/2',
            status: 'connected',
            speed: 'a-1000',
            duplex: 'a-full',
        );

        $result = $sync->sync(collect([$portStatus]), $switchConfig, Carbon::now());

        $this->assertCount(1, $result['stateChanges']);
        $this->assertSame('notconnect', $result['stateChanges'][0]['oldStatus']);
        $this->assertSame('connected', $result['stateChanges'][0]['newStatus']);
    }

    public function test_no_state_change_reported_when_status_unchanged(): void
    {
        $switchConfig = SwitchConfig::factory()->create();
        SwitchPort::factory()->create([
            'switch_config_id' => $switchConfig->id,
            'port_name' => 'Gi1/0/3',
            'status' => 'connected',
        ]);

        $sync = new PortStatusSync();

        $portStatus = new PortStatus(
            interface: 'Gi1/0/3',
            status: 'connected',
            speed: 'a-1000',
            duplex: 'a-full',
        );

        $result = $sync->sync(collect([$portStatus]), $switchConfig, Carbon::now());

        $this->assertCount(0, $result['stateChanges']);
    }
}
