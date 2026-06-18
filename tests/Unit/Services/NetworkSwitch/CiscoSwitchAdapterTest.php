<?php

declare(strict_types=1);

namespace Tests\Unit\Services\NetworkSwitch;

use App\Exceptions\InvalidPortIdentifierException;
use App\Services\Interfaces\SwitchCommandTransportInterface;
use App\Services\NetworkSwitch\CiscoSwitchAdapter;
use App\Services\NetworkSwitch\IosOutputParser;
use Mockery;
use PHPUnit\Framework\Attributes\DataProvider;
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
            ->with('show interface')
            ->andReturn('');
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
            ->with('show interface')
            ->andReturn('');
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
            ->with('show interface')
            ->andReturn('');
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
            ->with('show running-config | section ^interface')
            ->andReturn('');
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
            ->with('show running-config | section ^interface')
            ->andReturn('');
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
            ->with('show running-config | section ^interface')
            ->andReturn('');
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

    // -------------------------------------------------------------------------
    // Port identifier validation — command injection prevention (#9)
    // -------------------------------------------------------------------------

    /**
     * @return array<string, array{0: string}>
     */
    public static function validCiscoPortIdentifiers(): array
    {
        return [
            'gigabit short' => ['Gi1/0/1'],
            'gigabit full' => ['GigabitEthernet1/0/1'],
            'fast ethernet short' => ['Fa0/1'],
            'fast ethernet full' => ['FastEthernet0/1'],
            'ten gigabit short' => ['Te1/1/1'],
            'ten gigabit full' => ['TenGigabitEthernet1/1/1'],
            'port-channel' => ['Po1'],
            'port-channel full' => ['Port-channel1'],
            'vlan' => ['Vl100'],
            'vlan full' => ['Vlan100'],
            'loopback' => ['Lo0'],
            'loopback full' => ['Loopback0'],
            'two-digit slot' => ['Gi1/0/24'],
            'two-digit stack' => ['Gi12/0/1'],
            'twenty-five-gig short' => ['Twe1/0/1'],
            'twenty-five-gig full' => ['TwentyFiveGigE1/0/1'],
            'forty-gig short' => ['Fo1/1/1'],
            'forty-gig full' => ['FortyGigabitEthernet1/1/1'],
            'hundred-gig short' => ['Hu1/0/1'],
            'hundred-gig full' => ['HundredGigE1/0/1'],
            'management' => ['Mgmt0'],
            'nve interface' => ['nve1'],
            'tunnel interface' => ['Tu0'],
            'tunnel full' => ['Tunnel0'],
            'ethernet' => ['Eth1/1'],
            'ethernet full' => ['Ethernet1/1'],
        ];
    }

    /**
     * @return array<string, array{0: string}>
     */
    public static function maliciousPortIdentifiers(): array
    {
        return [
            'semicolon injection' => ['Gi1/0/1; show running-config'],
            'newline injection' => ["Gi1/0/1\nshow running-config"],
            'carriage return injection' => ["Gi1/0/1\rshow running-config"],
            'pipe injection' => ['Gi1/0/1 | include password'],
            'backtick injection' => ['Gi1/0/1`show run`'],
            'ampersand injection' => ['Gi1/0/1 && show run'],
            'dollar injection' => ['Gi1/0/1$(show run)'],
            'exclamation injection' => ['Gi1/0/1!'],
            'bare command' => ['show running-config'],
            'empty string' => [''],
            'space only' => [' '],
            'special chars' => ['../../../etc/passwd'],
            'config terminal injection' => ['Gi1/0/1; configure terminal'],
            'question mark' => ['Gi1/0/1?'],
        ];
    }

    #[DataProvider('validCiscoPortIdentifiers')]
    public function test_shutdown_port_accepts_valid_port_identifiers(string $portId): void
    {
        $transport = Mockery::mock(SwitchCommandTransportInterface::class);
        $transport->shouldReceive('executeMultiple')->once();

        $adapter = $this->createAdapter($transport);
        $result = $adapter->shutdownPort($portId);

        $this->assertTrue($result);
    }

    #[DataProvider('maliciousPortIdentifiers')]
    public function test_shutdown_port_rejects_malicious_port_identifiers(string $portId): void
    {
        $transport = Mockery::mock(SwitchCommandTransportInterface::class);
        $transport->shouldNotReceive('executeMultiple');
        $transport->shouldNotReceive('execute');

        $adapter = $this->createAdapter($transport);

        $this->expectException(InvalidPortIdentifierException::class);
        $adapter->shutdownPort($portId);
    }

    #[DataProvider('maliciousPortIdentifiers')]
    public function test_enable_port_rejects_malicious_port_identifiers(string $portId): void
    {
        $transport = Mockery::mock(SwitchCommandTransportInterface::class);
        $transport->shouldNotReceive('executeMultiple');
        $transport->shouldNotReceive('execute');

        $adapter = $this->createAdapter($transport);

        $this->expectException(InvalidPortIdentifierException::class);
        $adapter->enablePort($portId);
    }

    #[DataProvider('maliciousPortIdentifiers')]
    public function test_get_port_status_rejects_malicious_port_identifiers(string $portId): void
    {
        $transport = Mockery::mock(SwitchCommandTransportInterface::class);
        $transport->shouldReceive('execute')
            ->with('show interface')
            ->andReturn('');

        $adapter = $this->createAdapter($transport);

        $this->expectException(InvalidPortIdentifierException::class);
        $adapter->getPortStatus($portId);
    }

    #[DataProvider('maliciousPortIdentifiers')]
    public function test_get_port_statistics_rejects_malicious_port_identifiers(string $portId): void
    {
        $transport = Mockery::mock(SwitchCommandTransportInterface::class);
        $transport->shouldReceive('execute')
            ->with('show interface')
            ->andReturn('');

        $adapter = $this->createAdapter($transport);

        $this->expectException(InvalidPortIdentifierException::class);
        $adapter->getPortStatistics($portId);
    }

    #[DataProvider('maliciousPortIdentifiers')]
    public function test_get_port_running_config_rejects_malicious_port_identifiers(string $portId): void
    {
        $transport = Mockery::mock(SwitchCommandTransportInterface::class);
        $transport->shouldReceive('execute')
            ->with('show running-config | section ^interface')
            ->andReturn('');

        $adapter = $this->createAdapter($transport);

        $this->expectException(InvalidPortIdentifierException::class);
        $adapter->getPortRunningConfig($portId);
    }

    #[DataProvider('maliciousPortIdentifiers')]
    public function test_get_port_interface_output_rejects_malicious_port_identifiers(string $portId): void
    {
        $transport = Mockery::mock(SwitchCommandTransportInterface::class);
        $transport->shouldReceive('execute')
            ->with('show interface')
            ->andReturn('');

        $adapter = $this->createAdapter($transport);

        $this->expectException(InvalidPortIdentifierException::class);
        $adapter->getPortInterfaceOutput($portId);
    }
}
