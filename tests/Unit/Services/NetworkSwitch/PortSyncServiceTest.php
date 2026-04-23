<?php

declare(strict_types=1);

namespace Tests\Unit\Services\NetworkSwitch;

use App\Models\SwitchConfig;
use App\Models\SwitchPort;
use App\Models\SwitchPortConfig;
use App\Models\SwitchPortMac;
use App\Models\SwitchSyncRun;
use App\Services\Interfaces\NetworkSwitchInterface;
use App\Services\Interfaces\SwitchCommandTransportInterface;
use App\Services\NetworkSwitch\CiscoSwitchAdapter;
use App\Services\NetworkSwitch\IosOutputParser;
use App\Services\NetworkSwitch\PortSyncService;
use App\Services\NetworkSwitch\SwitchServiceFactory;
use App\Services\ValueObjects\ForwardingEntry;
use App\Services\ValueObjects\PortStatus;
use Illuminate\Foundation\Testing\LazilyRefreshDatabase;
use Mockery;
use Mockery\MockInterface;
use RuntimeException;
use Tests\TestCase;

class PortSyncServiceTest extends TestCase
{
    use LazilyRefreshDatabase;

    private MockInterface&NetworkSwitchInterface $switchAdapter;

    private MockInterface&SwitchServiceFactory $factory;

    private PortSyncService $service;

    private SwitchConfig $switchConfig;

    protected function setUp(): void
    {
        parent::setUp();

        $this->switchConfig = SwitchConfig::factory()->create();

        $this->switchAdapter = Mockery::mock(NetworkSwitchInterface::class);
        $this->factory = Mockery::mock(SwitchServiceFactory::class);
        $this->factory->shouldReceive('make')
            ->with(Mockery::on(fn (SwitchConfig $config): bool => $config->is($this->switchConfig)))
            ->andReturn($this->switchAdapter)
            ->byDefault();
        $this->switchAdapter->shouldReceive('getPortRunningConfig')
            ->andReturnUsing(static fn (string $portId): string => "!\ninterface {$portId}\n end")
            ->byDefault();

        $this->service = new PortSyncService($this->factory);
    }

    public function test_sync_creates_sync_run_record(): void
    {
        $this->switchAdapter->shouldReceive('getAllPorts')->andReturn(collect());
        $this->switchAdapter->shouldReceive('getForwardingDatabase')->andReturn(collect());

        $syncRun = $this->service->syncSwitch($this->switchConfig);

        $this->assertInstanceOf(SwitchSyncRun::class, $syncRun);
        $this->assertSame('completed', $syncRun->status);
        $this->assertSame($this->switchConfig->id, $syncRun->switch_config_id);
        $this->assertNotNull($syncRun->started_at);
        $this->assertNotNull($syncRun->finished_at);
        $this->assertDatabaseHas('switch_sync_runs', [
            'id' => $syncRun->id,
            'switch_config_id' => $this->switchConfig->id,
            'status' => 'completed',
        ]);
    }

    public function test_sync_creates_new_ports(): void
    {
        $ports = collect([
            new PortStatus(interface: 'Gi1/0/1', status: 'connected', speed: 'a-1000', duplex: 'a-full', vlan: '100'),
            new PortStatus(interface: 'Gi1/0/2', status: 'notconnect', speed: 'auto', duplex: 'auto', vlan: '200'),
            new PortStatus(interface: 'Gi1/0/3', status: 'connected', speed: 'a-100', duplex: 'a-full', vlan: '100'),
        ]);

        $this->switchAdapter->shouldReceive('getAllPorts')->once()->andReturn($ports);
        $this->switchAdapter->shouldReceive('getForwardingDatabase')->andReturn(collect());

        $this->service->syncSwitch($this->switchConfig);

        $this->assertDatabaseCount('switch_ports', 3);
        $this->assertDatabaseHas('switch_ports', [
            'switch_config_id' => $this->switchConfig->id,
            'port_name' => 'Gi1/0/1',
            'status' => 'connected',
        ]);
        $this->assertDatabaseHas('switch_ports', [
            'switch_config_id' => $this->switchConfig->id,
            'port_name' => 'Gi1/0/2',
            'status' => 'notconnect',
        ]);
        $this->assertDatabaseHas('switch_ports', [
            'switch_config_id' => $this->switchConfig->id,
            'port_name' => 'Gi1/0/3',
            'status' => 'connected',
        ]);
    }

    public function test_sync_updates_existing_ports(): void
    {
        $existingPort = SwitchPort::factory()->create([
            'switch_config_id' => $this->switchConfig->id,
            'port_name' => 'Gi1/0/1',
            'status' => 'notconnect',
            'speed' => 'auto',
            'duplex' => 'auto',
        ]);

        $ports = collect([
            new PortStatus(interface: 'Gi1/0/1', status: 'connected', speed: 'a-1000', duplex: 'a-full', vlan: '100'),
        ]);

        $this->switchAdapter->shouldReceive('getAllPorts')->once()->andReturn($ports);
        $this->switchAdapter->shouldReceive('getForwardingDatabase')->andReturn(collect());

        $this->service->syncSwitch($this->switchConfig);

        $existingPort->refresh();
        $this->assertSame('connected', $existingPort->status);
        $this->assertSame('a-1000', $existingPort->speed);
        $this->assertSame('a-full', $existingPort->duplex);
    }

    public function test_sync_stores_port_config(): void
    {
        $this->switchAdapter->shouldReceive('getAllPorts')->once()->andReturn(collect([
            new PortStatus(interface: 'Gi1/0/1', status: 'connected', speed: 'a-1000', duplex: 'a-full', vlan: '100'),
        ]));
        $this->switchAdapter->shouldReceive('getPortRunningConfig')
            ->with('Gi1/0/1')
            ->once()
            ->andReturn($configText = "!\ninterface Gi1/0/1\n switchport access vlan 100\n end");
        $this->switchAdapter->shouldReceive('getForwardingDatabase')->andReturn(collect());

        $this->service->syncSwitch($this->switchConfig);

        $port = SwitchPort::where('switch_config_id', $this->switchConfig->id)
            ->where('port_name', 'Gi1/0/1')
            ->firstOrFail();

        $this->assertDatabaseHas('switch_port_configs', [
            'switch_port_id' => $port->id,
            'config_text' => $configText,
            'config_hash' => md5($configText),
        ]);
    }

    public function test_sync_trims_running_config_preamble_before_persisting_config_text(): void
    {
        $rawConfigText = "Building configuration...\n\nCurrent configuration : 121 bytes\ninterface Gi1/0/1\n description Workstation\n switchport access vlan 100\nend";
        $trimmedConfigText = "interface Gi1/0/1\n description Workstation\n switchport access vlan 100\nend";

        $this->switchAdapter->shouldReceive('getAllPorts')->once()->andReturn(collect([
            new PortStatus(interface: 'Gi1/0/1', status: 'connected', speed: 'a-1000', duplex: 'a-full', vlan: '100'),
        ]));
        $this->switchAdapter->shouldReceive('getPortRunningConfig')
            ->with('Gi1/0/1')
            ->once()
            ->andReturn($rawConfigText);
        $this->switchAdapter->shouldReceive('getForwardingDatabase')->andReturn(collect());

        $this->service->syncSwitch($this->switchConfig);

        $port = SwitchPort::where('switch_config_id', $this->switchConfig->id)
            ->where('port_name', 'Gi1/0/1')
            ->firstOrFail();

        $persistedConfig = SwitchPortConfig::query()
            ->where('switch_port_id', $port->id)
            ->firstOrFail();

        $this->assertSame($trimmedConfigText, $persistedConfig->config_text);
        $this->assertSame(md5($trimmedConfigText), $persistedConfig->config_hash);
        $this->assertStringNotContainsString('Building configuration...', $persistedConfig->config_text);
        $this->assertStringNotContainsString('Current configuration :', $persistedConfig->config_text);
    }

    public function test_sync_trims_preamble_and_leading_standalone_bang_line_before_persisting_config_text(): void
    {
        $rawConfigText = "Building configuration...\n\nCurrent configuration : 121 bytes\n!\ninterface Gi1/0/1\n description Workstation\n switchport access vlan 100\nend";
        $trimmedConfigText = "interface Gi1/0/1\n description Workstation\n switchport access vlan 100\nend";

        $this->switchAdapter->shouldReceive('getAllPorts')->once()->andReturn(collect([
            new PortStatus(interface: 'Gi1/0/1', status: 'connected', speed: 'a-1000', duplex: 'a-full', vlan: '100'),
        ]));
        $this->switchAdapter->shouldReceive('getPortRunningConfig')
            ->with('Gi1/0/1')
            ->once()
            ->andReturn($rawConfigText);
        $this->switchAdapter->shouldReceive('getForwardingDatabase')->andReturn(collect());

        $this->service->syncSwitch($this->switchConfig);

        $port = SwitchPort::where('switch_config_id', $this->switchConfig->id)
            ->where('port_name', 'Gi1/0/1')
            ->firstOrFail();

        $persistedConfig = SwitchPortConfig::query()
            ->where('switch_port_id', $port->id)
            ->firstOrFail();

        $this->assertSame($trimmedConfigText, $persistedConfig->config_text);
        $this->assertSame(md5($trimmedConfigText), $persistedConfig->config_hash);
        $this->assertStringStartsNotWith("!\n", $persistedConfig->config_text);
    }

    public function test_sync_updates_config_when_changed(): void
    {
        $port = SwitchPort::factory()->create([
            'switch_config_id' => $this->switchConfig->id,
            'port_name' => 'Gi1/0/1',
        ]);
        $existingConfig = SwitchPortConfig::factory()->create([
            'switch_port_id' => $port->id,
            'config_text' => $originalConfig = "!\ninterface Gi1/0/1\n description Old\n end",
            'config_hash' => md5($originalConfig),
            'last_fetched_at' => now()->subDay(),
        ]);

        $this->switchAdapter->shouldReceive('getAllPorts')->once()->andReturn(collect([
            new PortStatus(interface: 'Gi1/0/1', status: 'connected', speed: 'a-1000', duplex: 'a-full', vlan: '100'),
        ]));
        $this->switchAdapter->shouldReceive('getPortRunningConfig')
            ->with('Gi1/0/1')
            ->once()
            ->andReturn($updatedConfig = "!\ninterface Gi1/0/1\n description New\n end");
        $this->switchAdapter->shouldReceive('getForwardingDatabase')->andReturn(collect());

        $this->service->syncSwitch($this->switchConfig);

        $existingConfig->refresh();

        $this->assertSame($updatedConfig, $existingConfig->config_text);
        $this->assertSame(md5($updatedConfig), $existingConfig->config_hash);
    }

    public function test_sync_only_updates_timestamp_when_config_unchanged(): void
    {
        $this->travelTo(now()->startOfSecond()->subMinute());

        $port = SwitchPort::factory()->create([
            'switch_config_id' => $this->switchConfig->id,
            'port_name' => 'Gi1/0/1',
        ]);
        $configText = "!\ninterface Gi1/0/1\n description Stable\n end";
        $existingConfig = SwitchPortConfig::factory()->create([
            'switch_port_id' => $port->id,
            'config_text' => $configText,
            'config_hash' => md5($configText),
            'last_fetched_at' => now(),
        ]);
        $originalLastFetchedAt = $existingConfig->last_fetched_at;

        $this->travelTo(now()->addMinute());

        $this->switchAdapter->shouldReceive('getAllPorts')->once()->andReturn(collect([
            new PortStatus(interface: 'Gi1/0/1', status: 'connected', speed: 'a-1000', duplex: 'a-full', vlan: '100'),
        ]));
        $this->switchAdapter->shouldReceive('getPortRunningConfig')
            ->with('Gi1/0/1')
            ->once()
            ->andReturn($configText);
        $this->switchAdapter->shouldReceive('getForwardingDatabase')->andReturn(collect());

        $this->service->syncSwitch($this->switchConfig);

        $existingConfig->refresh();

        $this->assertSame($configText, $existingConfig->config_text);
        $this->assertSame(md5($configText), $existingConfig->config_hash);
        $this->assertTrue($existingConfig->last_fetched_at->greaterThan($originalLastFetchedAt));
    }

    public function test_sync_continues_when_config_fetch_fails(): void
    {
        $this->switchAdapter->shouldReceive('getAllPorts')->once()->andReturn(collect([
            new PortStatus(interface: 'Gi1/0/1', status: 'connected', speed: 'a-1000', duplex: 'a-full', vlan: '100'),
            new PortStatus(interface: 'Gi1/0/2', status: 'connected', speed: 'a-1000', duplex: 'a-full', vlan: '200'),
        ]));
        $this->switchAdapter->shouldReceive('getPortRunningConfig')
            ->with('Gi1/0/1')
            ->once()
            ->andThrow(new RuntimeException('Config fetch failed'));
        $this->switchAdapter->shouldReceive('getPortRunningConfig')
            ->with('Gi1/0/2')
            ->once()
            ->andReturn($configText = "!\ninterface Gi1/0/2\n description OK\n end");
        $this->switchAdapter->shouldReceive('getForwardingDatabase')->andReturn(collect());

        $syncRun = $this->service->syncSwitch($this->switchConfig);

        $failedPort = SwitchPort::where('switch_config_id', $this->switchConfig->id)
            ->where('port_name', 'Gi1/0/1')
            ->firstOrFail();
        $successfulPort = SwitchPort::where('switch_config_id', $this->switchConfig->id)
            ->where('port_name', 'Gi1/0/2')
            ->firstOrFail();

        $this->assertSame('completed', $syncRun->status);
        $this->assertDatabaseMissing('switch_port_configs', [
            'switch_port_id' => $failedPort->id,
        ]);
        $this->assertDatabaseHas('switch_port_configs', [
            'switch_port_id' => $successfulPort->id,
            'config_text' => $configText,
            'config_hash' => md5($configText),
        ]);
    }

    public function test_sync_treats_cisco_cli_invalid_input_output_as_config_fetch_failure(): void
    {
        $this->switchAdapter->shouldReceive('getAllPorts')->once()->andReturn(collect([
            new PortStatus(interface: 'Gi1/0/24', status: 'connected', speed: 'a-1000', duplex: 'a-full', vlan: '100'),
        ]));
        $this->switchAdapter->shouldReceive('getPortRunningConfig')
            ->with('Gi1/0/24')
            ->once()
            ->andReturn("% Invalid input detected at '^' marker.\nshow running-config interface Gi1/0/24");
        $this->switchAdapter->shouldReceive('getForwardingDatabase')->andReturn(collect());

        $syncRun = $this->service->syncSwitch($this->switchConfig);

        $port = SwitchPort::where('switch_config_id', $this->switchConfig->id)
            ->where('port_name', 'Gi1/0/24')
            ->firstOrFail();

        $this->assertSame('completed', $syncRun->status);
        $this->assertDatabaseMissing('switch_port_configs', [
            'switch_port_id' => $port->id,
        ]);
    }

    public function test_sync_treats_invalid_input_phrase_inside_regular_config_text_as_valid_config(): void
    {
        $this->switchAdapter->shouldReceive('getAllPorts')->once()->andReturn(collect([
            new PortStatus(interface: 'Gi1/0/23', status: 'connected', speed: 'a-1000', duplex: 'a-full', vlan: '100'),
        ]));
        $this->switchAdapter->shouldReceive('getPortRunningConfig')
            ->with('Gi1/0/23')
            ->once()
            ->andReturn($configText = "!\ninterface Gi1/0/23\n description audit-note: % Invalid input detected was seen in old logs\n switchport access vlan 100\n end");
        $this->switchAdapter->shouldReceive('getForwardingDatabase')->andReturn(collect());

        $this->service->syncSwitch($this->switchConfig);

        $port = SwitchPort::where('switch_config_id', $this->switchConfig->id)
            ->where('port_name', 'Gi1/0/23')
            ->firstOrFail();

        $this->assertDatabaseHas('switch_port_configs', [
            'switch_port_id' => $port->id,
            'config_text' => $configText,
            'config_hash' => md5($configText),
        ]);
    }

    public function test_sync_removes_legacy_invalid_config_when_cisco_cli_returns_invalid_input_output(): void
    {
        $port = SwitchPort::factory()->create([
            'switch_config_id' => $this->switchConfig->id,
            'port_name' => 'Gi1/0/24',
        ]);
        SwitchPortConfig::factory()->create([
            'switch_port_id' => $port->id,
            'config_text' => $legacyInvalidConfig = "% Invalid input detected at '^' marker.\nshow running-config interface Gi1/0/24",
            'config_hash' => md5($legacyInvalidConfig),
            'last_fetched_at' => now()->subDay(),
        ]);

        $this->switchAdapter->shouldReceive('getAllPorts')->once()->andReturn(collect([
            new PortStatus(interface: 'Gi1/0/24', status: 'connected', speed: 'a-1000', duplex: 'a-full', vlan: '100'),
        ]));
        $this->switchAdapter->shouldReceive('getPortRunningConfig')
            ->with('Gi1/0/24')
            ->once()
            ->andReturn("% Invalid input detected at '^' marker.\nshow running-config interface Gi1/0/24");
        $this->switchAdapter->shouldReceive('getForwardingDatabase')->andReturn(collect());

        $this->service->syncSwitch($this->switchConfig);

        $this->assertDatabaseMissing('switch_port_configs', [
            'switch_port_id' => $port->id,
            'config_text' => $legacyInvalidConfig,
        ]);
    }

    public function test_sync_persists_interface_output_separately_from_running_config(): void
    {
        $runningConfig = "!\ninterface Gi1/0/50\n description Uplink\n end";
        $interfaceOutput = 'GigabitEthernet1/0/50 is up, line protocol is up (connected)';

        $this->switchAdapter->shouldReceive('getAllPorts')->once()->andReturn(collect([
            new PortStatus(interface: 'Gi1/0/50', status: 'connected', speed: 'a-1000', duplex: 'a-full', vlan: '100'),
        ]));
        $this->switchAdapter->shouldReceive('getPortRunningConfig')
            ->with('Gi1/0/50')
            ->once()
            ->andReturn($runningConfig);
        $this->switchAdapter->shouldReceive('getPortStatus')
            ->with('Gi1/0/50')
            ->once()
            ->andReturn(new PortStatus(
                interface: 'Gi1/0/50',
                status: 'connected',
                speed: '1000Mb/s',
                duplex: 'Full-duplex',
                description: $interfaceOutput,
            ));
        $this->switchAdapter->shouldReceive('getForwardingDatabase')->andReturn(collect());

        $this->service->syncSwitch($this->switchConfig);

        $port = SwitchPort::where('switch_config_id', $this->switchConfig->id)
            ->where('port_name', 'Gi1/0/50')
            ->firstOrFail();

        $this->assertDatabaseHas('switch_port_configs', [
            'switch_port_id' => $port->id,
            'config_text' => $runningConfig,
            'interface_output' => $interfaceOutput,
        ]);
    }

    public function test_sync_captures_interface_output_from_show_interface_command_not_status_description(): void
    {
        $statusDescription = 'Cached status description should not be used as interface output';
        $runningConfig = "!\ninterface Gi1/0/7\n description Uplink\n end";
        $rawShowInterfaceOutput = implode("\r\n", [
            'GigabitEthernet1/0/7 is up, line protocol is up (connected)',
            '  Hardware is Gigabit Ethernet, address is aabb.ccdd.ee07',
            '  Full-duplex, 1000Mb/s, media type is 10/100/1000BaseTX',
            '  Last input never, output 00:00:00, output hang never',
        ]);

        $transport = Mockery::mock(SwitchCommandTransportInterface::class);
        $transport->shouldReceive('execute')
            ->with('show interface status')
            ->once()
            ->ordered()
            ->andReturn(implode("\r\n", [
                'Port      Name               Status       Vlan       Duplex  Speed Type',
                sprintf('Gi1/0/7   %s  connected    100        a-full  a-1000 10/100/1000BaseTX', $statusDescription),
            ]));
        // CiscoSwitchAdapter fetches bulk running configs first; return empty so it falls back to per-port call
        $transport->shouldReceive('execute')
            ->with('show running-config | section ^interface')
            ->once()
            ->ordered()
            ->andReturn('');
        $transport->shouldReceive('execute')
            ->with('show run interface Gi1/0/7')
            ->once()
            ->ordered()
            ->andReturn($runningConfig);
        // CiscoSwitchAdapter fetches bulk interface output first; return empty so it falls back to per-port call
        $transport->shouldReceive('execute')
            ->with('show interface')
            ->once()
            ->ordered()
            ->andReturn('');
        $transport->shouldReceive('execute')
            ->with('show interface Gi1/0/7')
            ->once()
            ->ordered()
            ->andReturn($rawShowInterfaceOutput);
        $transport->shouldReceive('execute')
            ->with('show mac address-table')
            ->once()
            ->ordered()
            ->andReturn('');

        $adapter = new CiscoSwitchAdapter($transport, new IosOutputParser);
        $this->factory->shouldReceive('make')
            ->with(Mockery::on(fn (SwitchConfig $config): bool => $config->is($this->switchConfig)))
            ->once()
            ->andReturn($adapter);

        $this->service->syncSwitch($this->switchConfig);

        $port = SwitchPort::where('switch_config_id', $this->switchConfig->id)
            ->where('port_name', 'Gi1/0/7')
            ->firstOrFail();

        $this->assertDatabaseHas('switch_port_configs', [
            'switch_port_id' => $port->id,
            'config_text' => $runningConfig,
            'interface_output' => $rawShowInterfaceOutput,
        ]);
        $this->assertDatabaseMissing('switch_port_configs', [
            'switch_port_id' => $port->id,
            'interface_output' => $statusDescription,
        ]);
    }

    public function test_sync_does_not_store_switchport_fallback_output_in_running_config_field(): void
    {
        $fallbackSwitchportOutput = "Name: Gi1/0/24\nSwitchport: Enabled\nAdministrative Mode: static access";
        $interfaceOutput = 'GigabitEthernet1/0/24 is up, line protocol is up (connected)';

        $this->switchAdapter->shouldReceive('getAllPorts')->once()->andReturn(collect([
            new PortStatus(interface: 'Gi1/0/24', status: 'connected', speed: 'a-1000', duplex: 'a-full', vlan: '100'),
        ]));
        $this->switchAdapter->shouldReceive('getPortRunningConfig')
            ->with('Gi1/0/24')
            ->once()
            ->andReturn($fallbackSwitchportOutput);
        $this->switchAdapter->shouldReceive('getPortStatus')
            ->with('Gi1/0/24')
            ->once()
            ->andReturn(new PortStatus(
                interface: 'Gi1/0/24',
                status: 'connected',
                speed: '1000Mb/s',
                duplex: 'Full-duplex',
                description: $interfaceOutput,
            ));
        $this->switchAdapter->shouldReceive('getForwardingDatabase')->andReturn(collect());

        $this->service->syncSwitch($this->switchConfig);

        $port = SwitchPort::where('switch_config_id', $this->switchConfig->id)
            ->where('port_name', 'Gi1/0/24')
            ->firstOrFail();

        $this->assertDatabaseMissing('switch_port_configs', [
            'switch_port_id' => $port->id,
            'config_text' => $fallbackSwitchportOutput,
        ]);
        $this->assertDatabaseHas('switch_port_configs', [
            'switch_port_id' => $port->id,
            'interface_output' => $interfaceOutput,
        ]);
    }

    public function test_sync_preserves_existing_config_text_when_running_config_is_invalid_but_interface_output_is_available(): void
    {
        $port = SwitchPort::factory()->create([
            'switch_config_id' => $this->switchConfig->id,
            'port_name' => 'Gi1/0/24',
        ]);
        $existingConfigText = "!\ninterface Gi1/0/24\n description Existing valid config\n switchport access vlan 100\n end";
        $existingConfig = SwitchPortConfig::factory()->create([
            'switch_port_id' => $port->id,
            'config_text' => $existingConfigText,
            'config_hash' => md5($existingConfigText),
            'interface_output' => 'old interface output',
            'last_fetched_at' => now()->subDay(),
        ]);

        $newInterfaceOutput = 'GigabitEthernet1/0/24 is up, line protocol is up (connected)';
        $invalidRunningConfigOutput = "Name: Gi1/0/24\nSwitchport: Enabled\nAdministrative Mode: static access";

        $this->switchAdapter->shouldReceive('getAllPorts')->once()->andReturn(collect([
            new PortStatus(interface: 'Gi1/0/24', status: 'connected', speed: 'a-1000', duplex: 'a-full', vlan: '100'),
        ]));
        $this->switchAdapter->shouldReceive('getPortRunningConfig')
            ->with('Gi1/0/24')
            ->once()
            ->andReturn($invalidRunningConfigOutput);
        $this->switchAdapter->shouldReceive('getPortStatus')
            ->with('Gi1/0/24')
            ->once()
            ->andReturn(new PortStatus(
                interface: 'Gi1/0/24',
                status: 'connected',
                speed: '1000Mb/s',
                duplex: 'Full-duplex',
                description: $newInterfaceOutput,
            ));
        $this->switchAdapter->shouldReceive('getForwardingDatabase')->andReturn(collect());

        $this->service->syncSwitch($this->switchConfig);

        $existingConfig->refresh();

        $this->assertSame($existingConfigText, $existingConfig->config_text);
        $this->assertSame(md5($existingConfigText), $existingConfig->config_hash);
        $this->assertSame($newInterfaceOutput, $existingConfig->interface_output);
    }

    public function test_sync_stores_switch_description(): void
    {
        $this->switchAdapter->shouldReceive('getAllPorts')->andReturn(collect([
            new PortStatus(
                interface: 'Gi1/0/10',
                status: 'connected',
                speed: 'a-1000',
                duplex: 'a-full',
                vlan: '100',
                description: 'Conference Room',
            ),
        ]));
        $this->switchAdapter->shouldReceive('getForwardingDatabase')->andReturn(collect());

        $this->service->syncSwitch($this->switchConfig);

        $this->assertDatabaseHas('switch_ports', [
            'switch_config_id' => $this->switchConfig->id,
            'port_name' => 'Gi1/0/10',
            'switch_description' => 'Conference Room',
        ]);
    }

    public function test_sync_stores_switchport_mode_access(): void
    {
        $this->switchAdapter->shouldReceive('getAllPorts')->andReturn(collect([
            new PortStatus(
                interface: 'Gi1/0/11',
                status: 'connected',
                speed: 'a-1000',
                duplex: 'a-full',
                vlan: '100',
                switchportMode: 'access',
            ),
        ]));
        $this->switchAdapter->shouldReceive('getForwardingDatabase')->andReturn(collect());

        $this->service->syncSwitch($this->switchConfig);

        $this->assertDatabaseHas('switch_ports', [
            'switch_config_id' => $this->switchConfig->id,
            'port_name' => 'Gi1/0/11',
            'switchport_mode' => 'access',
            'access_vlan' => 100,
        ]);
    }

    public function test_sync_stores_switchport_mode_trunk(): void
    {
        $this->switchAdapter->shouldReceive('getAllPorts')->andReturn(collect([
            new PortStatus(
                interface: 'Gi1/0/12',
                status: 'connected',
                speed: 'a-1000',
                duplex: 'a-full',
                vlan: '',
                switchportMode: 'trunk',
            ),
        ]));
        $this->switchAdapter->shouldReceive('getForwardingDatabase')->andReturn(collect());

        $this->service->syncSwitch($this->switchConfig);

        $port = SwitchPort::where('switch_config_id', $this->switchConfig->id)
            ->where('port_name', 'Gi1/0/12')
            ->first();

        $this->assertNotNull($port);
        $this->assertSame('trunk', $port->switchport_mode);
        $this->assertNull($port->access_vlan);
    }

    public function test_sync_stores_switchport_mode_routed(): void
    {
        $this->switchAdapter->shouldReceive('getAllPorts')->andReturn(collect([
            new PortStatus(
                interface: 'Gi1/0/13',
                status: 'connected',
                speed: 'a-1000',
                duplex: 'a-full',
                vlan: '',
                switchportMode: 'routed',
            ),
        ]));
        $this->switchAdapter->shouldReceive('getForwardingDatabase')->andReturn(collect());

        $this->service->syncSwitch($this->switchConfig);

        $port = SwitchPort::where('switch_config_id', $this->switchConfig->id)
            ->where('port_name', 'Gi1/0/13')
            ->first();

        $this->assertNotNull($port);
        $this->assertSame('routed', $port->switchport_mode);
        $this->assertNull($port->access_vlan);
    }

    public function test_sync_updates_description_on_existing_port(): void
    {
        $existingPort = SwitchPort::factory()->create([
            'switch_config_id' => $this->switchConfig->id,
            'port_name' => 'Gi1/0/14',
            'switch_description' => 'Old description',
        ]);

        $this->switchAdapter->shouldReceive('getAllPorts')->andReturn(collect([
            new PortStatus(
                interface: 'Gi1/0/14',
                status: 'connected',
                speed: 'a-1000',
                duplex: 'a-full',
                vlan: '100',
                description: 'New description',
            ),
        ]));
        $this->switchAdapter->shouldReceive('getForwardingDatabase')->andReturn(collect());

        $this->service->syncSwitch($this->switchConfig);

        $existingPort->refresh();
        $this->assertSame('New description', $existingPort->switch_description);
    }

    public function test_sync_stores_duplex_value(): void
    {
        $this->switchAdapter->shouldReceive('getAllPorts')->andReturn(collect([
            new PortStatus(
                interface: 'Gi1/0/15',
                status: 'connected',
                speed: 'a-1000',
                duplex: 'a-full',
                vlan: '100',
            ),
        ]));
        $this->switchAdapter->shouldReceive('getForwardingDatabase')->andReturn(collect());

        $this->service->syncSwitch($this->switchConfig);

        $this->assertDatabaseHas('switch_ports', [
            'switch_config_id' => $this->switchConfig->id,
            'port_name' => 'Gi1/0/15',
            'duplex' => 'a-full',
        ]);
    }

    public function test_sync_creates_mac_entries(): void
    {
        $ports = collect([
            new PortStatus(interface: 'Gi1/0/1', status: 'connected', speed: 'a-1000', duplex: 'a-full', vlan: '100'),
            new PortStatus(interface: 'Gi1/0/2', status: 'connected', speed: 'a-1000', duplex: 'a-full', vlan: '200'),
        ]);

        $macs = collect([
            new ForwardingEntry(mac: 'aabb.ccdd.ee01', port: 'Gi1/0/1', vlan: 100),
            new ForwardingEntry(mac: 'aabb.ccdd.ee02', port: 'Gi1/0/2', vlan: 200),
        ]);

        $this->switchAdapter->shouldReceive('getAllPorts')->once()->andReturn($ports);
        $this->switchAdapter->shouldReceive('getForwardingDatabase')->once()->andReturn($macs);

        $this->service->syncSwitch($this->switchConfig);

        $port1 = SwitchPort::where('port_name', 'Gi1/0/1')
            ->where('switch_config_id', $this->switchConfig->id)
            ->first();
        $port2 = SwitchPort::where('port_name', 'Gi1/0/2')
            ->where('switch_config_id', $this->switchConfig->id)
            ->first();

        $this->assertDatabaseHas('switch_port_macs', [
            'switch_port_id' => $port1->id,
            'mac_address' => 'aabb.ccdd.ee01',
            'vlan' => 100,
        ]);
        $this->assertDatabaseHas('switch_port_macs', [
            'switch_port_id' => $port2->id,
            'mac_address' => 'aabb.ccdd.ee02',
            'vlan' => 200,
        ]);
    }

    public function test_sync_updates_mac_last_seen(): void
    {
        $port = SwitchPort::factory()->create([
            'switch_config_id' => $this->switchConfig->id,
            'port_name' => 'Gi1/0/1',
        ]);

        $existingMac = SwitchPortMac::factory()->create([
            'switch_port_id' => $port->id,
            'mac_address' => 'aabb.ccdd.ee01',
            'vlan' => 100,
            'last_seen_at' => now()->subDay(),
        ]);

        $originalLastSeen = $existingMac->last_seen_at;

        $this->switchAdapter->shouldReceive('getAllPorts')->andReturn(collect([
            new PortStatus(interface: 'Gi1/0/1', status: 'connected', speed: 'a-1000', duplex: 'a-full', vlan: '100'),
        ]));
        $this->switchAdapter->shouldReceive('getForwardingDatabase')->andReturn(collect([
            new ForwardingEntry(mac: 'aabb.ccdd.ee01', port: 'Gi1/0/1', vlan: 100),
        ]));

        $this->service->syncSwitch($this->switchConfig);

        $existingMac->refresh();
        $this->assertTrue($existingMac->last_seen_at->greaterThan($originalLastSeen));
    }

    public function test_sync_removes_stale_macs(): void
    {
        $port = SwitchPort::factory()->create([
            'switch_config_id' => $this->switchConfig->id,
            'port_name' => 'Gi1/0/1',
        ]);

        $staleMac = SwitchPortMac::factory()->create([
            'switch_port_id' => $port->id,
            'mac_address' => 'dead.beef.0001',
            'vlan' => 100,
            'last_seen_at' => now()->subDay(),
        ]);

        $this->switchAdapter->shouldReceive('getAllPorts')->andReturn(collect([
            new PortStatus(interface: 'Gi1/0/1', status: 'connected', speed: 'a-1000', duplex: 'a-full', vlan: '100'),
        ]));
        $this->switchAdapter->shouldReceive('getForwardingDatabase')->andReturn(collect([
            new ForwardingEntry(mac: 'aabb.ccdd.ee01', port: 'Gi1/0/1', vlan: 100),
        ]));

        $this->service->syncSwitch($this->switchConfig);

        $this->assertDatabaseMissing('switch_port_macs', ['id' => $staleMac->id]);
        $this->assertDatabaseHas('switch_port_macs', ['mac_address' => 'aabb.ccdd.ee01']);
    }

    public function test_sync_tracks_created_and_updated_counts(): void
    {
        // Pre-create one port so it will be updated
        SwitchPort::factory()->create([
            'switch_config_id' => $this->switchConfig->id,
            'port_name' => 'Gi1/0/1',
            'status' => 'notconnect',
        ]);

        // Pre-create one MAC so it will be updated
        $existingPort = SwitchPort::where('port_name', 'Gi1/0/1')
            ->where('switch_config_id', $this->switchConfig->id)
            ->first();
        SwitchPortMac::factory()->create([
            'switch_port_id' => $existingPort->id,
            'mac_address' => 'aabb.ccdd.ee01',
            'vlan' => 100,
            'last_seen_at' => now()->subDay(),
        ]);

        $ports = collect([
            new PortStatus(interface: 'Gi1/0/1', status: 'connected', speed: 'a-1000', duplex: 'a-full', vlan: '100'),
            new PortStatus(interface: 'Gi1/0/2', status: 'connected', speed: 'a-1000', duplex: 'a-full', vlan: '200'),
        ]);

        $macs = collect([
            new ForwardingEntry(mac: 'aabb.ccdd.ee01', port: 'Gi1/0/1', vlan: 100),
            new ForwardingEntry(mac: 'aabb.ccdd.ee02', port: 'Gi1/0/2', vlan: 200),
        ]);

        $this->switchAdapter->shouldReceive('getAllPorts')->once()->andReturn($ports);
        $this->switchAdapter->shouldReceive('getForwardingDatabase')->once()->andReturn($macs);

        $syncRun = $this->service->syncSwitch($this->switchConfig);

        $this->assertSame(1, $syncRun->ports_created);
        $this->assertSame(1, $syncRun->ports_updated);
        $this->assertSame(1, $syncRun->macs_created);
        $this->assertSame(1, $syncRun->macs_updated);
    }

    public function test_sync_records_failure(): void
    {
        $this->switchAdapter->shouldReceive('getAllPorts')
            ->once()
            ->andThrow(new RuntimeException('SSH connection timeout'));

        try {
            $this->service->syncSwitch($this->switchConfig);
            $this->fail('Expected RuntimeException was not thrown');
        } catch (RuntimeException $runtimeException) {
            $this->assertSame('SSH connection timeout', $runtimeException->getMessage());
        }

        $syncRun = SwitchSyncRun::where('switch_config_id', $this->switchConfig->id)->first();

        $this->assertSame('failed', $syncRun->status);
        $this->assertStringContainsString('SSH connection timeout', $syncRun->error);
        $this->assertNotNull($syncRun->finished_at);
        $this->assertDatabaseHas('switch_sync_runs', [
            'id' => $syncRun->id,
            'status' => 'failed',
        ]);
    }

    public function test_sync_sets_last_synced_at_on_ports(): void
    {
        $this->travelTo(now()->startOfSecond());

        $this->switchAdapter->shouldReceive('getAllPorts')->andReturn(collect([
            new PortStatus(interface: 'Gi1/0/1', status: 'connected', speed: 'a-1000', duplex: 'a-full', vlan: '100'),
            new PortStatus(interface: 'Gi1/0/2', status: 'connected', speed: 'a-1000', duplex: 'a-full', vlan: '200'),
        ]));
        $this->switchAdapter->shouldReceive('getForwardingDatabase')->andReturn(collect());

        $this->service->syncSwitch($this->switchConfig);

        $ports = SwitchPort::where('switch_config_id', $this->switchConfig->id)->get();

        $this->assertCount(2, $ports);
        foreach ($ports as $port) {
            $this->assertNotNull($port->last_synced_at);
            $this->assertTrue($port->last_synced_at->equalTo(now()));
        }
    }

    public function test_sync_maps_port_status_to_model(): void
    {
        $this->switchAdapter->shouldReceive('getAllPorts')->andReturn(collect([
            new PortStatus(
                interface: 'Gi1/0/5',
                status: 'connected',
                speed: 'a-1000',
                duplex: 'a-full',
                vlan: '150',
            ),
        ]));
        $this->switchAdapter->shouldReceive('getForwardingDatabase')->andReturn(collect());

        $this->service->syncSwitch($this->switchConfig);

        $port = SwitchPort::where('switch_config_id', $this->switchConfig->id)->first();

        $this->assertSame('Gi1/0/5', $port->port_name);
        $this->assertSame('connected', $port->status);
        $this->assertSame('a-1000', $port->speed);
        $this->assertSame('a-full', $port->duplex);
        $this->assertSame(150, $port->access_vlan);
    }

    public function test_sync_uses_switch_service_factory(): void
    {
        $this->switchAdapter->shouldReceive('getAllPorts')->andReturn(collect());
        $this->switchAdapter->shouldReceive('getForwardingDatabase')->andReturn(collect());

        $this->factory->shouldReceive('make')
            ->with(Mockery::on(fn (SwitchConfig $config): bool => $config->is($this->switchConfig)))
            ->once()
            ->andReturn($this->switchAdapter);

        $this->service->syncSwitch($this->switchConfig);
    }

    public function test_sync_wraps_in_transaction(): void
    {
        // Pre-create a port to ensure it exists before sync
        SwitchPort::factory()->create([
            'switch_config_id' => $this->switchConfig->id,
            'port_name' => 'Gi1/0/1',
            'status' => 'notconnect',
            'speed' => 'auto',
        ]);

        $this->switchAdapter->shouldReceive('getAllPorts')->andReturn(collect([
            new PortStatus(interface: 'Gi1/0/1', status: 'connected', speed: 'a-1000', duplex: 'a-full', vlan: '100'),
        ]));

        // Simulate getForwardingDatabase throwing an exception after ports are processed
        $this->switchAdapter->shouldReceive('getForwardingDatabase')
            ->andThrow(new RuntimeException('MAC table fetch failed'));

        try {
            $this->service->syncSwitch($this->switchConfig);
            $this->fail('Expected RuntimeException was not thrown');
        } catch (RuntimeException $runtimeException) {
            $this->assertSame('MAC table fetch failed', $runtimeException->getMessage());
        }

        $syncRun = SwitchSyncRun::where('switch_config_id', $this->switchConfig->id)->first();

        $this->assertSame('failed', $syncRun->status);

        // Port should NOT have been updated because transaction was rolled back
        $port = SwitchPort::where('port_name', 'Gi1/0/1')
            ->where('switch_config_id', $this->switchConfig->id)
            ->first();
        $this->assertSame('notconnect', $port->status);
        $this->assertSame('auto', $port->speed);
    }

    public function test_sync_handles_empty_port_list(): void
    {
        $this->switchAdapter->shouldReceive('getAllPorts')->andReturn(collect());
        $this->switchAdapter->shouldReceive('getForwardingDatabase')->andReturn(collect());

        $syncRun = $this->service->syncSwitch($this->switchConfig);

        $this->assertSame('completed', $syncRun->status);
        $this->assertSame(0, $syncRun->ports_created);
        $this->assertSame(0, $syncRun->ports_updated);
        $this->assertSame(0, $syncRun->macs_created);
        $this->assertSame(0, $syncRun->macs_updated);
        $this->assertDatabaseCount('switch_ports', 0);
    }

    public function test_sync_handles_empty_mac_table(): void
    {
        // Pre-create a port with an existing MAC
        $port = SwitchPort::factory()->create([
            'switch_config_id' => $this->switchConfig->id,
            'port_name' => 'Gi1/0/1',
        ]);

        $existingMac = SwitchPortMac::factory()->create([
            'switch_port_id' => $port->id,
            'mac_address' => 'aabb.ccdd.ee01',
            'vlan' => 100,
        ]);

        $this->switchAdapter->shouldReceive('getAllPorts')->andReturn(collect([
            new PortStatus(interface: 'Gi1/0/1', status: 'connected', speed: 'a-1000', duplex: 'a-full', vlan: '100'),
        ]));
        $this->switchAdapter->shouldReceive('getForwardingDatabase')->andReturn(collect());

        $syncRun = $this->service->syncSwitch($this->switchConfig);

        $this->assertSame('completed', $syncRun->status);

        // Stale MACs should still be removed after a successful sync with empty table
        $this->assertDatabaseMissing('switch_port_macs', ['id' => $existingMac->id]);
    }

    public function test_sync_cleans_up_stale_running_runs(): void
    {
        // Create a stale run that has been "running" for 10 minutes
        $staleRun = SwitchSyncRun::factory()->create([
            'switch_config_id' => $this->switchConfig->id,
            'status' => 'running',
            'started_at' => now()->subMinutes(10),
        ]);

        $this->switchAdapter->shouldReceive('getAllPorts')->andReturn(collect());
        $this->switchAdapter->shouldReceive('getForwardingDatabase')->andReturn(collect());

        $this->service->syncSwitch($this->switchConfig);

        $staleRun->refresh();

        $this->assertSame('failed', $staleRun->status);
        $this->assertNotNull($staleRun->finished_at);
        $this->assertSame('Sync timed out (stale run cleanup)', $staleRun->error);
    }

    public function test_sync_does_not_clean_up_recent_running_runs(): void
    {
        // Create a recent run that has been "running" for only 2 minutes
        $recentRun = SwitchSyncRun::factory()->create([
            'switch_config_id' => $this->switchConfig->id,
            'status' => 'running',
            'started_at' => now()->subMinutes(2),
        ]);

        $this->switchAdapter->shouldReceive('getAllPorts')->andReturn(collect());
        $this->switchAdapter->shouldReceive('getForwardingDatabase')->andReturn(collect());

        $this->service->syncSwitch($this->switchConfig);

        $recentRun->refresh();

        // Should still be running — not cleaned up because it's less than 5 minutes old
        $this->assertSame('running', $recentRun->status);
        $this->assertNull($recentRun->finished_at);
    }

    public function test_sync_cleanup_only_affects_same_switch(): void
    {
        $otherSwitch = SwitchConfig::factory()->create();

        // Create a stale run on a different switch
        $otherStaleRun = SwitchSyncRun::factory()->create([
            'switch_config_id' => $otherSwitch->id,
            'status' => 'running',
            'started_at' => now()->subMinutes(10),
        ]);

        $this->switchAdapter->shouldReceive('getAllPorts')->andReturn(collect());
        $this->switchAdapter->shouldReceive('getForwardingDatabase')->andReturn(collect());

        $this->service->syncSwitch($this->switchConfig);

        $otherStaleRun->refresh();

        // The other switch's stale run should NOT be affected
        $this->assertSame('running', $otherStaleRun->status);
    }

    public function test_sync_skips_forwarding_entry_when_port_not_in_switch_ports(): void
    {
        // Only Gi1/0/1 exists as a port; forwarding entry references Gi1/0/99 which doesn't exist
        $ports = collect([
            new PortStatus(interface: 'Gi1/0/1', status: 'connected', speed: 'a-1000', duplex: 'a-full', vlan: '100'),
        ]);

        $macs = collect([
            new ForwardingEntry(mac: 'aabb.ccdd.ee01', port: 'Gi1/0/1', vlan: 100),
            new ForwardingEntry(mac: 'aabb.ccdd.ee02', port: 'Gi1/0/99', vlan: 200), // port not in switch
        ]);

        $this->switchAdapter->shouldReceive('getAllPorts')->once()->andReturn($ports);
        $this->switchAdapter->shouldReceive('getForwardingDatabase')->once()->andReturn($macs);

        $syncRun = $this->service->syncSwitch($this->switchConfig);

        // Only the valid MAC should be stored
        $this->assertDatabaseCount('switch_port_macs', 1);
        $this->assertDatabaseHas('switch_port_macs', ['mac_address' => 'aabb.ccdd.ee01']);
        $this->assertDatabaseMissing('switch_port_macs', ['mac_address' => 'aabb.ccdd.ee02']);
        $this->assertSame('completed', $syncRun->status);
    }
}
