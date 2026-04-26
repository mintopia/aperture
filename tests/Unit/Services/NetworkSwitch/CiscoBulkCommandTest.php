<?php

declare(strict_types=1);

namespace Tests\Unit\Services\NetworkSwitch;

use App\Services\Interfaces\SupportsBulkOperations;
use App\Services\Interfaces\SwitchCommandTransportInterface;
use App\Services\NetworkSwitch\CiscoSwitchAdapter;
use App\Services\NetworkSwitch\IosOutputParser;
use Mockery;
use Tests\TestCase;

class CiscoBulkCommandTest extends TestCase
{
    private IosOutputParser $parser;

    protected function setUp(): void
    {
        parent::setUp();
        $this->parser = new IosOutputParser;
    }

    // -------------------------------------------------------------------------
    // IosOutputParser: splitBulkShowInterface
    // -------------------------------------------------------------------------

    public function test_split_bulk_show_interface_separates_by_interface(): void
    {
        $output = <<<'OUTPUT'
GigabitEthernet0/1 is up, line protocol is up (connected)
  Hardware is Gigabit Ethernet, address is aabb.ccdd.0001
  Description: Server 1
  5 minute input rate 1000 bits/sec
GigabitEthernet0/2 is down, line protocol is down (notconnect)
  Hardware is Gigabit Ethernet, address is aabb.ccdd.0002
  Description: Server 2
  5 minute input rate 0 bits/sec
OUTPUT;

        $result = $this->parser->splitBulkShowInterface($output);

        $this->assertCount(2, $result);
        $this->assertArrayHasKey('GigabitEthernet0/1', $result);
        $this->assertArrayHasKey('GigabitEthernet0/2', $result);
        $this->assertStringContainsString('Server 1', $result['GigabitEthernet0/1']);
        $this->assertStringContainsString('Server 2', $result['GigabitEthernet0/2']);
    }

    public function test_split_bulk_show_interface_handles_ten_gigabit(): void
    {
        $output = <<<'OUTPUT'
TenGigabitEthernet1/0/1 is up, line protocol is up (connected)
  Hardware is Ten Gigabit Ethernet
TenGigabitEthernet1/0/2 is down, line protocol is down (notconnect)
  Hardware is Ten Gigabit Ethernet
OUTPUT;

        $result = $this->parser->splitBulkShowInterface($output);

        $this->assertCount(2, $result);
        $this->assertArrayHasKey('TenGigabitEthernet1/0/1', $result);
        $this->assertArrayHasKey('TenGigabitEthernet1/0/2', $result);
    }

    public function test_split_bulk_show_interface_preserves_full_block_content(): void
    {
        $output = <<<'OUTPUT'
GigabitEthernet0/1 is up, line protocol is up (connected)
  Hardware is Gigabit Ethernet, address is aabb.ccdd.0001
  Description: Server 1
     12345 packets input, 6789012 bytes, 0 no buffer
     3 input errors, 1 CRC, 0 frame, 0 overrun, 0 ignored
     67890 packets output, 9876543 bytes, 0 underruns
OUTPUT;

        $result = $this->parser->splitBulkShowInterface($output);

        $this->assertCount(1, $result);
        $this->assertStringContainsString('12345 packets input', $result['GigabitEthernet0/1']);
        $this->assertStringContainsString('67890 packets output', $result['GigabitEthernet0/1']);
    }

    // -------------------------------------------------------------------------
    // IosOutputParser: splitBulkRunningConfig
    // -------------------------------------------------------------------------

    public function test_split_bulk_running_config_separates_by_interface(): void
    {
        $output = <<<'OUTPUT'
interface GigabitEthernet0/1
 description Server 1
 switchport mode access
 switchport access vlan 100
!
interface GigabitEthernet0/2
 description Server 2
 switchport mode trunk
!
OUTPUT;

        $result = $this->parser->splitBulkRunningConfig($output);

        $this->assertCount(2, $result);
        $this->assertArrayHasKey('GigabitEthernet0/1', $result);
        $this->assertArrayHasKey('GigabitEthernet0/2', $result);
        $this->assertStringContainsString('switchport mode access', $result['GigabitEthernet0/1']);
        $this->assertStringContainsString('switchport mode trunk', $result['GigabitEthernet0/2']);
    }

    public function test_split_bulk_running_config_handles_empty_output(): void
    {
        $result = $this->parser->splitBulkRunningConfig('');

        $this->assertCount(0, $result);
    }

    public function test_split_bulk_show_interface_handles_empty_output(): void
    {
        $result = $this->parser->splitBulkShowInterface('');

        $this->assertCount(0, $result);
    }

    public function test_split_bulk_running_config_handles_multiple_interfaces_without_trailing_bang(): void
    {
        $output = <<<'OUTPUT'
interface GigabitEthernet0/1
 description Server 1
 switchport access vlan 100
!
interface GigabitEthernet0/2
 description Server 2
 switchport access vlan 200
OUTPUT;

        $result = $this->parser->splitBulkRunningConfig($output);

        $this->assertCount(2, $result);
        $this->assertArrayHasKey('GigabitEthernet0/2', $result);
        $this->assertStringContainsString('switchport access vlan 200', $result['GigabitEthernet0/2']);
    }

    public function test_split_bulk_running_config_handles_48_port_switch(): void
    {
        $lines = [];
        for ($i = 1; $i <= 48; $i++) {
            $lines[] = "interface GigabitEthernet1/0/{$i}";
            $lines[] = " description Port {$i}";
            $lines[] = ' switchport access vlan 100';
            $lines[] = '!';
        }

        $result = $this->parser->splitBulkRunningConfig(implode("\n", $lines));

        $this->assertCount(48, $result);
        $this->assertArrayHasKey('GigabitEthernet1/0/1', $result);
        $this->assertArrayHasKey('GigabitEthernet1/0/48', $result);
    }

    // -------------------------------------------------------------------------
    // CiscoSwitchAdapter: SupportsBulkOperations interface
    // -------------------------------------------------------------------------

    // -------------------------------------------------------------------------
    // IosOutputParser: abbreviateInterfaceName
    // -------------------------------------------------------------------------

    public function test_abbreviate_interface_name_converts_full_names_to_short(): void
    {
        $this->assertSame('Gi1/0/1', $this->parser->abbreviateInterfaceName('GigabitEthernet1/0/1'));
        $this->assertSame('Fa0/1', $this->parser->abbreviateInterfaceName('FastEthernet0/1'));
        $this->assertSame('Te1/0/1', $this->parser->abbreviateInterfaceName('TenGigabitEthernet1/0/1'));
        $this->assertSame('Twe1/0/1', $this->parser->abbreviateInterfaceName('TwentyFiveGigE1/0/1'));
        $this->assertSame('Fo1/1/1', $this->parser->abbreviateInterfaceName('FortyGigabitEthernet1/1/1'));
        $this->assertSame('Hu1/0/1', $this->parser->abbreviateInterfaceName('HundredGigE1/0/1'));
        $this->assertSame('Po1', $this->parser->abbreviateInterfaceName('Port-channel1'));
        $this->assertSame('Vl100', $this->parser->abbreviateInterfaceName('Vlan100'));
        $this->assertSame('Lo0', $this->parser->abbreviateInterfaceName('Loopback0'));
        $this->assertSame('Tu0', $this->parser->abbreviateInterfaceName('Tunnel0'));
        $this->assertSame('Eth1/1', $this->parser->abbreviateInterfaceName('Ethernet1/1'));
    }

    public function test_abbreviate_interface_name_returns_already_short_names_unchanged(): void
    {
        $this->assertSame('Gi1/0/1', $this->parser->abbreviateInterfaceName('Gi1/0/1'));
        $this->assertSame('Fa0/1', $this->parser->abbreviateInterfaceName('Fa0/1'));
        $this->assertSame('Te1/0/1', $this->parser->abbreviateInterfaceName('Te1/0/1'));
        $this->assertSame('Mgmt0', $this->parser->abbreviateInterfaceName('Mgmt0'));
        $this->assertSame('nve1', $this->parser->abbreviateInterfaceName('nve1'));
    }

    // -------------------------------------------------------------------------
    // CiscoSwitchAdapter: SupportsBulkOperations interface
    // -------------------------------------------------------------------------

    public function test_cisco_switch_adapter_implements_supports_bulk_operations(): void
    {
        $transport = Mockery::mock(SwitchCommandTransportInterface::class);
        $adapter = new CiscoSwitchAdapter($transport, new IosOutputParser);

        $this->assertInstanceOf(SupportsBulkOperations::class, $adapter);
    }

    public function test_get_all_port_running_configs_returns_parsed_bulk_config(): void
    {
        $bulkOutput = <<<'OUTPUT'
interface GigabitEthernet0/1
 description Server 1
 switchport access vlan 100
!
interface GigabitEthernet0/2
 description Server 2
 switchport mode trunk
!
OUTPUT;

        $transport = Mockery::mock(SwitchCommandTransportInterface::class);
        $transport->shouldReceive('execute')
            ->with('show running-config | section ^interface')
            ->once()
            ->andReturn($bulkOutput);

        $adapter = new CiscoSwitchAdapter($transport, new IosOutputParser);
        $result = $adapter->getAllPortRunningConfigs();

        // Full names and abbreviated names are both indexed
        $this->assertArrayHasKey('GigabitEthernet0/1', $result);
        $this->assertArrayHasKey('GigabitEthernet0/2', $result);
        $this->assertArrayHasKey('Gi0/1', $result);
        $this->assertArrayHasKey('Gi0/2', $result);
        $this->assertStringContainsString('switchport access vlan 100', $result['GigabitEthernet0/1']);
        $this->assertStringContainsString('switchport mode trunk', $result['GigabitEthernet0/2']);
        // Abbreviated keys contain the same data
        $this->assertSame($result['GigabitEthernet0/1'], $result['Gi0/1']);
        $this->assertSame($result['GigabitEthernet0/2'], $result['Gi0/2']);
    }

    public function test_get_all_port_interface_outputs_returns_parsed_bulk_output(): void
    {
        $bulkOutput = <<<'OUTPUT'
GigabitEthernet0/1 is up, line protocol is up (connected)
  Hardware is Gigabit Ethernet, address is aabb.ccdd.0001
  Description: Server 1
GigabitEthernet0/2 is down, line protocol is down (notconnect)
  Hardware is Gigabit Ethernet, address is aabb.ccdd.0002
OUTPUT;

        $transport = Mockery::mock(SwitchCommandTransportInterface::class);
        $transport->shouldReceive('execute')
            ->with('show interface')
            ->once()
            ->andReturn($bulkOutput);

        $adapter = new CiscoSwitchAdapter($transport, new IosOutputParser);
        $result = $adapter->getAllPortInterfaceOutputs();

        // Full names and abbreviated names are both indexed
        $this->assertArrayHasKey('GigabitEthernet0/1', $result);
        $this->assertArrayHasKey('GigabitEthernet0/2', $result);
        $this->assertArrayHasKey('Gi0/1', $result);
        $this->assertArrayHasKey('Gi0/2', $result);
        $this->assertStringContainsString('Server 1', $result['GigabitEthernet0/1']);
        $this->assertSame($result['GigabitEthernet0/1'], $result['Gi0/1']);
    }

    public function test_get_all_port_running_configs_caches_result(): void
    {
        $transport = Mockery::mock(SwitchCommandTransportInterface::class);
        $transport->shouldReceive('execute')
            ->with('show running-config | section ^interface')
            ->once()
            ->andReturn("interface GigabitEthernet0/1\n switchport access vlan 100\n!");

        $adapter = new CiscoSwitchAdapter($transport, new IosOutputParser);

        $result1 = $adapter->getAllPortRunningConfigs();
        $result2 = $adapter->getAllPortRunningConfigs();

        $this->assertSame($result1, $result2);
    }

    public function test_get_all_port_interface_outputs_caches_result(): void
    {
        $transport = Mockery::mock(SwitchCommandTransportInterface::class);
        $transport->shouldReceive('execute')
            ->with('show interface')
            ->once()
            ->andReturn("GigabitEthernet0/1 is up, line protocol is up (connected)\n  Hardware is Gigabit Ethernet");

        $adapter = new CiscoSwitchAdapter($transport, new IosOutputParser);

        $result1 = $adapter->getAllPortInterfaceOutputs();
        $result2 = $adapter->getAllPortInterfaceOutputs();

        $this->assertSame($result1, $result2);
    }

    public function test_get_all_port_running_configs_returns_empty_array_for_empty_output(): void
    {
        $transport = Mockery::mock(SwitchCommandTransportInterface::class);
        $transport->shouldReceive('execute')
            ->with('show running-config | section ^interface')
            ->once()
            ->andReturn('');

        $adapter = new CiscoSwitchAdapter($transport, new IosOutputParser);
        $result = $adapter->getAllPortRunningConfigs();

        $this->assertSame([], $result);
    }

    public function test_get_all_port_interface_outputs_returns_empty_array_for_empty_output(): void
    {
        $transport = Mockery::mock(SwitchCommandTransportInterface::class);
        $transport->shouldReceive('execute')
            ->with('show interface')
            ->once()
            ->andReturn('');

        $adapter = new CiscoSwitchAdapter($transport, new IosOutputParser);
        $result = $adapter->getAllPortInterfaceOutputs();

        $this->assertSame([], $result);
    }

    public function test_per_port_running_config_uses_bulk_cache(): void
    {
        $bulkOutput = <<<'OUTPUT'
interface GigabitEthernet1/0/1
 description Server 1
 switchport access vlan 100
!
OUTPUT;

        $transport = Mockery::mock(SwitchCommandTransportInterface::class);
        $transport->shouldReceive('execute')
            ->with('show running-config | section ^interface')
            ->once()
            ->andReturn($bulkOutput);
        $transport->shouldNotReceive('execute')
            ->with('show run interface GigabitEthernet1/0/1');

        $adapter = new CiscoSwitchAdapter($transport, new IosOutputParser);
        $result = $adapter->getPortRunningConfig('GigabitEthernet1/0/1');

        $this->assertStringContainsString('switchport access vlan 100', $result);
    }

    public function test_per_port_running_config_falls_back_when_not_in_bulk(): void
    {
        $transport = Mockery::mock(SwitchCommandTransportInterface::class);
        $transport->shouldReceive('execute')
            ->with('show running-config | section ^interface')
            ->once()
            ->andReturn('');
        $transport->shouldReceive('execute')
            ->with('show run interface Gi1/0/99')
            ->once()
            ->andReturn("interface Gi1/0/99\n switchport access vlan 999");

        $adapter = new CiscoSwitchAdapter($transport, new IosOutputParser);
        $result = $adapter->getPortRunningConfig('Gi1/0/99');

        $this->assertStringContainsString('switchport access vlan 999', $result);
    }
}
