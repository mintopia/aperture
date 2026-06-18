<?php

declare(strict_types=1);

namespace Tests\Unit\Services\NetworkSwitch;

use App\Models\SwitchConfig;
use App\Models\SwitchPort;
use App\Models\SwitchPortConfig;
use App\Services\NetworkSwitch\PortConfigSync;
use Illuminate\Foundation\Testing\LazilyRefreshDatabase;
use Illuminate\Support\Carbon;
use Tests\TestCase;

class PortConfigSyncTest extends TestCase
{
    use LazilyRefreshDatabase;

    public function test_sync_creates_port_config_for_known_port(): void
    {
        $switchConfig = SwitchConfig::factory()->create();
        $port = SwitchPort::factory()->create([
            'switch_config_id' => $switchConfig->id,
            'port_name' => 'Gi1/0/1',
        ]);

        $sync = new PortConfigSync;
        $portConfigData = [
            'Gi1/0/1' => [
                'rawConfig' => "interface Gi1/0/1\n switchport access vlan 100",
                'rawInterfaceOutput' => null,
            ],
        ];

        $sync->sync($switchConfig, $portConfigData, Carbon::now());

        $this->assertDatabaseHas('switch_port_configs', [
            'switch_port_id' => $port->id,
            'config_text' => "interface Gi1/0/1\n switchport access vlan 100",
            'config_hash' => md5("interface Gi1/0/1\n switchport access vlan 100"),
        ]);
    }

    public function test_sync_updates_port_config_when_config_changes(): void
    {
        $switchConfig = SwitchConfig::factory()->create();
        $port = SwitchPort::factory()->create([
            'switch_config_id' => $switchConfig->id,
            'port_name' => 'Gi1/0/1',
        ]);
        $originalConfig = "interface Gi1/0/1\n description Old";
        SwitchPortConfig::factory()->create([
            'switch_port_id' => $port->id,
            'config_text' => $originalConfig,
            'config_hash' => md5($originalConfig),
            'last_fetched_at' => now()->subDay(),
        ]);

        $sync = new PortConfigSync;
        $updatedConfig = "interface Gi1/0/1\n description New";
        $portConfigData = [
            'Gi1/0/1' => [
                'rawConfig' => $updatedConfig,
                'rawInterfaceOutput' => null,
            ],
        ];

        $sync->sync($switchConfig, $portConfigData, Carbon::now());

        $this->assertDatabaseHas('switch_port_configs', [
            'switch_port_id' => $port->id,
            'config_text' => $updatedConfig,
            'config_hash' => md5($updatedConfig),
        ]);
        $this->assertDatabaseMissing('switch_port_configs', [
            'switch_port_id' => $port->id,
            'config_text' => $originalConfig,
        ]);
    }

    public function test_sync_only_updates_timestamp_when_config_unchanged(): void
    {
        $this->travelTo(now()->startOfSecond()->subMinute());

        $switchConfig = SwitchConfig::factory()->create();
        $port = SwitchPort::factory()->create([
            'switch_config_id' => $switchConfig->id,
            'port_name' => 'Gi1/0/1',
        ]);
        $configText = "interface Gi1/0/1\n description Stable";
        $existingConfig = SwitchPortConfig::factory()->create([
            'switch_port_id' => $port->id,
            'config_text' => $configText,
            'config_hash' => md5($configText),
            'last_fetched_at' => now(),
        ]);
        $originalLastFetchedAt = $existingConfig->last_fetched_at;

        $this->travelTo(now()->addMinute());

        $sync = new PortConfigSync;
        $portConfigData = [
            'Gi1/0/1' => [
                'rawConfig' => $configText,
                'rawInterfaceOutput' => null,
            ],
        ];

        $sync->sync($switchConfig, $portConfigData, Carbon::now());

        $existingConfig->refresh();

        $this->assertSame($configText, $existingConfig->config_text);
        $this->assertSame(md5($configText), $existingConfig->config_hash);
        $this->assertTrue($existingConfig->last_fetched_at->greaterThan($originalLastFetchedAt));
    }

    public function test_sync_skips_port_not_found_in_database(): void
    {
        $switchConfig = SwitchConfig::factory()->create();

        $sync = new PortConfigSync;
        $portConfigData = [
            'Gi1/0/99' => [
                'rawConfig' => "interface Gi1/0/99\n description Ghost",
                'rawInterfaceOutput' => null,
            ],
        ];

        $sync->sync($switchConfig, $portConfigData, Carbon::now());

        $this->assertDatabaseCount('switch_port_configs', 0);
    }

    public function test_sync_skips_port_when_both_raw_config_and_interface_output_are_null(): void
    {
        $switchConfig = SwitchConfig::factory()->create();
        SwitchPort::factory()->create([
            'switch_config_id' => $switchConfig->id,
            'port_name' => 'Gi1/0/1',
        ]);

        $sync = new PortConfigSync;
        $portConfigData = [
            'Gi1/0/1' => [
                'rawConfig' => null,
                'rawInterfaceOutput' => null,
            ],
        ];

        $sync->sync($switchConfig, $portConfigData, Carbon::now());

        $this->assertDatabaseCount('switch_port_configs', 0);
    }

    public function test_sync_trims_running_config_preamble_before_persisting(): void
    {
        $switchConfig = SwitchConfig::factory()->create();
        $port = SwitchPort::factory()->create([
            'switch_config_id' => $switchConfig->id,
            'port_name' => 'Gi1/0/1',
        ]);

        $rawConfig = "Building configuration...\n\nCurrent configuration : 121 bytes\ninterface Gi1/0/1\n switchport access vlan 100\nend";
        $trimmedConfig = "interface Gi1/0/1\n switchport access vlan 100\nend";

        $sync = new PortConfigSync;
        $portConfigData = [
            'Gi1/0/1' => [
                'rawConfig' => $rawConfig,
                'rawInterfaceOutput' => null,
            ],
        ];

        $sync->sync($switchConfig, $portConfigData, Carbon::now());

        $this->assertDatabaseHas('switch_port_configs', [
            'switch_port_id' => $port->id,
            'config_text' => $trimmedConfig,
        ]);
        $this->assertDatabaseMissing('switch_port_configs', [
            'config_text' => $rawConfig,
        ]);
    }

    public function test_sync_does_not_persist_cisco_cli_error_output_as_config(): void
    {
        $switchConfig = SwitchConfig::factory()->create();
        $port = SwitchPort::factory()->create([
            'switch_config_id' => $switchConfig->id,
            'port_name' => 'Gi1/0/24',
        ]);

        $sync = new PortConfigSync;
        $portConfigData = [
            'Gi1/0/24' => [
                'rawConfig' => "% Invalid input detected at '^' marker.",
                'rawInterfaceOutput' => null,
            ],
        ];

        $sync->sync($switchConfig, $portConfigData, Carbon::now());

        $this->assertDatabaseMissing('switch_port_configs', [
            'switch_port_id' => $port->id,
        ]);
    }

    public function test_sync_persists_interface_output_alongside_config_text(): void
    {
        $switchConfig = SwitchConfig::factory()->create();
        $port = SwitchPort::factory()->create([
            'switch_config_id' => $switchConfig->id,
            'port_name' => 'Gi1/0/1',
        ]);

        $runningConfig = "interface Gi1/0/1\n description Uplink";
        $interfaceOutput = 'GigabitEthernet1/0/1 is up, line protocol is up (connected)';

        $sync = new PortConfigSync;
        $portConfigData = [
            'Gi1/0/1' => [
                'rawConfig' => $runningConfig,
                'rawInterfaceOutput' => $interfaceOutput,
            ],
        ];

        $sync->sync($switchConfig, $portConfigData, Carbon::now());

        $this->assertDatabaseHas('switch_port_configs', [
            'switch_port_id' => $port->id,
            'config_text' => $runningConfig,
            'interface_output' => $interfaceOutput,
        ]);
    }

    public function test_sync_returns_immediately_when_port_config_data_is_empty(): void
    {
        $switchConfig = SwitchConfig::factory()->create();
        SwitchPort::factory()->create([
            'switch_config_id' => $switchConfig->id,
            'port_name' => 'Gi1/0/1',
        ]);

        $sync = new PortConfigSync;

        // Should not throw and should not create any config records
        $sync->sync($switchConfig, [], Carbon::now());

        $this->assertDatabaseCount('switch_port_configs', 0);
    }
}
