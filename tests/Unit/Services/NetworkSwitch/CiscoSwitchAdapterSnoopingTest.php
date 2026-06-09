<?php

declare(strict_types=1);

namespace Tests\Unit\Services\NetworkSwitch;

use App\Services\Interfaces\SupportsDhcpSnooping;
use App\Services\Interfaces\SwitchCommandTransportInterface;
use App\Services\NetworkSwitch\CiscoSwitchAdapter;
use App\Services\NetworkSwitch\IosOutputParser;
use PHPUnit\Framework\TestCase;

class CiscoSwitchAdapterSnoopingTest extends TestCase
{
    public function test_implements_supports_dhcp_snooping(): void
    {
        $transport = $this->createMock(SwitchCommandTransportInterface::class);
        $adapter = new CiscoSwitchAdapter($transport, new IosOutputParser);

        $this->assertInstanceOf(SupportsDhcpSnooping::class, $adapter);
    }

    public function test_get_dhcp_snooping_bindings_returns_collection(): void
    {
        $transport = $this->createMock(SwitchCommandTransportInterface::class);
        $transport->method('execute')
            ->willReturn(implode("\r\n", [
                'MacAddress          IpAddress        Lease(sec)  Type           VLAN  Interface',
                '-----------------   ---------------  ----------  -------------  ----  --------------------',
                '00:11:22:33:44:55   10.0.0.50        86400       dhcp-snooping   100   GigabitEthernet1/0/1',
                'AA:BB:CC:DD:EE:FF   10.0.0.51        86400       dhcp-snooping   100   GigabitEthernet1/0/2',
                'Total number of bindings: 2',
            ]));

        $adapter = new CiscoSwitchAdapter($transport, new IosOutputParser);
        $bindings = $adapter->getDhcpSnoopingBindings();

        $this->assertCount(2, $bindings);
        $this->assertSame('10.0.0.50', $bindings[0]['ip']);
        $this->assertSame('00:11:22:33:44:55', $bindings[0]['mac']);
        $this->assertSame(100, $bindings[0]['vlan']);
        $this->assertSame('GigabitEthernet1/0/1', $bindings[0]['interface']);
        $this->assertSame(86400, $bindings[0]['lease_seconds']);
    }

    public function test_get_dhcp_snooping_bindings_returns_empty_on_error(): void
    {
        $transport = $this->createMock(SwitchCommandTransportInterface::class);
        $transport->method('execute')
            ->willReturn("% Invalid input detected at '^' marker.");

        $adapter = new CiscoSwitchAdapter($transport, new IosOutputParser);
        $bindings = $adapter->getDhcpSnoopingBindings();

        $this->assertCount(0, $bindings);
    }
}
