<?php

declare(strict_types=1);

namespace Tests\Unit\Services\NetworkSwitch;

use App\Services\Interfaces\SwitchCommandTransportInterface;
use App\Services\NetworkSwitch\CiscoSwitchAdapter;
use App\Services\NetworkSwitch\IosOutputParser;
use Mockery;
use Tests\TestCase;

class CiscoSwitchAdapterTest extends TestCase
{
    private function createAdapter(SwitchCommandTransportInterface $transport): CiscoSwitchAdapter
    {
        return new CiscoSwitchAdapter($transport, new IosOutputParser);
    }

    public function test_get_port_status_returns_structured_data(): void
    {
        $transport = Mockery::mock(SwitchCommandTransportInterface::class);
        $transport->shouldReceive('execute')
            ->with('show interface Gi1/0/1')
            ->once()
            ->andReturn(implode("\r\n", [
                'GigabitEthernet1/0/1 is up, line protocol is up (connected)',
                '  Hardware is Gigabit Ethernet, address is aabb.ccdd.eeff',
                '  Full-duplex, 1000Mb/s, media type is 10/100/1000BaseTX',
            ]));

        $adapter = $this->createAdapter($transport);
        $result = $adapter->getPortStatus('Gi1/0/1');

        $this->assertEquals('GigabitEthernet1/0/1', $result->interface);
        $this->assertEquals('up', $result->status);
        $this->assertEquals('1000Mb/s', $result->speed);
        $this->assertEquals('Full-duplex', $result->duplex);
    }

    public function test_get_port_status_exposes_raw_show_interface_output_for_interface_output_capture(): void
    {
        $rawOutput = implode("\r\n", [
            'GigabitEthernet1/0/7 is up, line protocol is up (connected)',
            '  Hardware is Gigabit Ethernet, address is aabb.ccdd.ee07',
            '  Full-duplex, 1000Mb/s, media type is 10/100/1000BaseTX',
            '  Last input never, output 00:00:00, output hang never',
        ]);

        $transport = Mockery::mock(SwitchCommandTransportInterface::class);
        $transport->shouldReceive('execute')
            ->with('show interface Gi1/0/7')
            ->once()
            ->andReturn($rawOutput);

        $adapter = $this->createAdapter($transport);
        $result = $adapter->getPortStatus('Gi1/0/7');

        $this->assertSame($rawOutput, $result->description);
    }

    public function test_get_all_ports_returns_collection(): void
    {
        $transport = Mockery::mock(SwitchCommandTransportInterface::class);
        $transport->shouldReceive('execute')
            ->with('show interface status')
            ->once()
            ->andReturn(implode("\r\n", [
                'Port      Name               Status       Vlan       Duplex  Speed Type',
                'Gi1/0/1   Server-1           connected    100        a-full  a-1000 10/100/1000BaseTX',
                'Gi1/0/2   Server-2           notconnect   100        auto    auto  10/100/1000BaseTX',
            ]));

        $adapter = $this->createAdapter($transport);
        $result = $adapter->getAllPorts();

        $this->assertCount(2, $result);
        $this->assertEquals('Gi1/0/1', $result[0]->interface);
        $this->assertEquals('connected', $result[0]->status);
        $this->assertEquals('a-1000', $result[0]->speed);
    }

    public function test_shutdown_port_calls_cisco_service(): void
    {
        $transport = Mockery::mock(SwitchCommandTransportInterface::class);
        $transport->shouldReceive('executeMultiple')
            ->with([
                'configure terminal',
                'interface Gi1/0/1',
                'shutdown',
                'end',
                'write memory',
            ])
            ->once();

        $adapter = $this->createAdapter($transport);
        $result = $adapter->shutdownPort('Gi1/0/1');

        $this->assertTrue($result);
    }

    public function test_enable_port_calls_cisco_service(): void
    {
        $transport = Mockery::mock(SwitchCommandTransportInterface::class);
        $transport->shouldReceive('executeMultiple')
            ->with([
                'configure terminal',
                'interface Gi1/0/1',
                'no shutdown',
                'end',
                'write memory',
            ])
            ->once();

        $adapter = $this->createAdapter($transport);
        $result = $adapter->enablePort('Gi1/0/1');

        $this->assertTrue($result);
    }

    public function test_get_port_statistics_returns_counters(): void
    {
        $transport = Mockery::mock(SwitchCommandTransportInterface::class);
        $transport->shouldReceive('execute')
            ->with('show interface Gi1/0/1')
            ->once()
            ->andReturn(implode("\r\n", [
                'GigabitEthernet1/0/1 is up, line protocol is up (connected)',
                '     12345 packets input, 6789012 bytes, 0 no buffer',
                '     3 input errors, 1 CRC, 0 frame, 0 overrun, 0 ignored',
                '     67890 packets output, 9876543 bytes, 0 underruns',
                '     5 output errors, 0 collisions, 0 interface resets',
            ]));

        $adapter = $this->createAdapter($transport);
        $result = $adapter->getPortStatistics('Gi1/0/1');

        $this->assertEquals(6789012, $result->inBytes);
        $this->assertEquals(9876543, $result->outBytes);
        $this->assertEquals(3, $result->inErrors);
        $this->assertEquals(5, $result->outErrors);
    }

    public function test_get_port_running_config_sends_correct_command(): void
    {
        $transport = Mockery::mock(SwitchCommandTransportInterface::class);
        $transport->shouldReceive('execute')
            ->with('show run interface Gi1/0/1')
            ->once()
            ->andReturn("interface Gi1/0/1\n description Test");

        $adapter = $this->createAdapter($transport);

        $adapter->getPortRunningConfig('Gi1/0/1');
    }

    public function test_get_port_running_config_returns_output(): void
    {
        $transport = Mockery::mock(SwitchCommandTransportInterface::class);
        $transport->shouldReceive('execute')
            ->with('show run interface Gi1/0/1')
            ->once()
            ->andReturn("interface Gi1/0/1\n switchport access vlan 100");

        $adapter = $this->createAdapter($transport);
        $result = $adapter->getPortRunningConfig('Gi1/0/1');

        $this->assertSame("interface Gi1/0/1\n switchport access vlan 100", $result);
    }

    public function test_get_port_running_config_does_not_fall_back_to_switchport_output_when_running_config_command_is_invalid(): void
    {
        $transport = Mockery::mock(SwitchCommandTransportInterface::class);
        $transport->shouldReceive('execute')
            ->with('show run interface Gi1/0/1')
            ->once()
            ->andReturn("% Invalid input detected at '^' marker.\nshow running-config interface Gi1/0/1");

        $adapter = $this->createAdapter($transport);
        $result = $adapter->getPortRunningConfig('Gi1/0/1');

        $this->assertSame(
            "% Invalid input detected at '^' marker.\nshow running-config interface Gi1/0/1",
            $result
        );
    }

    public function test_get_forwarding_database_returns_collection(): void
    {
        $transport = Mockery::mock(SwitchCommandTransportInterface::class);
        $transport->shouldReceive('execute')
            ->with('show mac address-table')
            ->once()
            ->andReturn(implode("\r\n", [
                '          Mac Address Table',
                '-------------------------------------------',
                '',
                'Vlan    Mac Address       Type        Ports',
                '----    -----------       --------    -----',
                ' 100    aabb.ccdd.eeff    DYNAMIC     Gi1/0/1',
                ' 100    1122.3344.5566    DYNAMIC     Gi1/0/2',
                'Total Mac Addresses for this criterion: 2',
            ]));

        $adapter = $this->createAdapter($transport);
        $result = $adapter->getForwardingDatabase();

        $this->assertCount(2, $result);
        $this->assertEquals('aabb.ccdd.eeff', $result[0]->mac);
        $this->assertEquals('Gi1/0/1', $result[0]->port);
        $this->assertEquals(100, $result[0]->vlan);
    }
}
