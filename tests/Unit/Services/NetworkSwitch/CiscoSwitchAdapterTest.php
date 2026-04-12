<?php

declare(strict_types=1);

namespace Tests\Unit\Services\NetworkSwitch;

use App\Services\CiscoService;
use App\Services\NetworkSwitch\CiscoSwitchAdapter;
use App\Services\NetworkSwitch\IosOutputParser;
use Mockery;
use Tests\TestCase;

class CiscoSwitchAdapterTest extends TestCase
{
    private function createAdapter(CiscoService $ciscoService): CiscoSwitchAdapter
    {
        return new CiscoSwitchAdapter($ciscoService, new IosOutputParser);
    }

    public function test_get_port_status_returns_structured_data(): void
    {
        $cisco = Mockery::mock(CiscoService::class);
        $cisco->shouldReceive('showInterface')
            ->with('Gi1/0/1')
            ->once()
            ->andReturn(implode("\r\n", [
                'GigabitEthernet1/0/1 is up, line protocol is up (connected)',
                '  Hardware is Gigabit Ethernet, address is aabb.ccdd.eeff',
                '  Full-duplex, 1000Mb/s, media type is 10/100/1000BaseTX',
            ]));

        $adapter = $this->createAdapter($cisco);
        $result = $adapter->getPortStatus('Gi1/0/1');

        $this->assertEquals('GigabitEthernet1/0/1', $result['interface']);
        $this->assertEquals('up', $result['status']);
        $this->assertEquals('1000Mb/s', $result['speed']);
        $this->assertEquals('Full-duplex', $result['duplex']);
    }

    public function test_get_all_ports_returns_collection(): void
    {
        $cisco = Mockery::mock(CiscoService::class);
        $cisco->shouldReceive('showInterfaceStatus')
            ->once()
            ->andReturn(implode("\r\n", [
                'Port      Name               Status       Vlan       Duplex  Speed Type',
                'Gi1/0/1   Server-1           connected    100        a-full  a-1000 10/100/1000BaseTX',
                'Gi1/0/2   Server-2           notconnect   100        auto    auto  10/100/1000BaseTX',
            ]));

        $adapter = $this->createAdapter($cisco);
        $result = $adapter->getAllPorts();

        $this->assertCount(2, $result);
        $this->assertEquals('Gi1/0/1', $result[0]['interface']);
        $this->assertEquals('connected', $result[0]['status']);
        $this->assertEquals('a-1000', $result[0]['speed']);
    }

    public function test_shutdown_port_calls_cisco_service(): void
    {
        $cisco = Mockery::mock(CiscoService::class);
        $cisco->shouldReceive('shutInterface')
            ->with('Gi1/0/1')
            ->once();

        $adapter = $this->createAdapter($cisco);
        $result = $adapter->shutdownPort('Gi1/0/1');

        $this->assertTrue($result);
    }

    public function test_enable_port_calls_cisco_service(): void
    {
        $cisco = Mockery::mock(CiscoService::class);
        $cisco->shouldReceive('unshutInterface')
            ->with('Gi1/0/1')
            ->once();

        $adapter = $this->createAdapter($cisco);
        $result = $adapter->enablePort('Gi1/0/1');

        $this->assertTrue($result);
    }

    public function test_get_port_statistics_returns_counters(): void
    {
        $cisco = Mockery::mock(CiscoService::class);
        $cisco->shouldReceive('showInterface')
            ->with('Gi1/0/1')
            ->once()
            ->andReturn(implode("\r\n", [
                'GigabitEthernet1/0/1 is up, line protocol is up (connected)',
                '     12345 packets input, 6789012 bytes, 0 no buffer',
                '     3 input errors, 1 CRC, 0 frame, 0 overrun, 0 ignored',
                '     67890 packets output, 9876543 bytes, 0 underruns',
                '     5 output errors, 0 collisions, 0 interface resets',
            ]));

        $adapter = $this->createAdapter($cisco);
        $result = $adapter->getPortStatistics('Gi1/0/1');

        $this->assertEquals(6789012, $result['in_bytes']);
        $this->assertEquals(9876543, $result['out_bytes']);
        $this->assertEquals(3, $result['in_errors']);
        $this->assertEquals(5, $result['out_errors']);
    }

    public function test_get_forwarding_database_returns_collection(): void
    {
        $cisco = Mockery::mock(CiscoService::class);
        $cisco->shouldReceive('showMacAddressTable')
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

        $adapter = $this->createAdapter($cisco);
        $result = $adapter->getForwardingDatabase();

        $this->assertCount(2, $result);
        $this->assertEquals('aabb.ccdd.eeff', $result[0]['mac']);
        $this->assertEquals('Gi1/0/1', $result[0]['port']);
        $this->assertEquals(100, $result[0]['vlan']);
    }
}
