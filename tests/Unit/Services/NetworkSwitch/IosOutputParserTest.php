<?php

declare(strict_types=1);

namespace Tests\Unit\Services\NetworkSwitch;

use App\Services\NetworkSwitch\IosOutputParser;
use App\Services\ValueObjects\PortStatus;
use Tests\TestCase;

class IosOutputParserTest extends TestCase
{
    private IosOutputParser $parser;

    protected function setUp(): void
    {
        parent::setUp();
        $this->parser = new IosOutputParser;
    }

    public function test_parse_show_interface_extracts_status_up(): void
    {
        $output = implode("\r\n", [
            'GigabitEthernet1/0/1 is up, line protocol is up (connected)',
            '  Hardware is Gigabit Ethernet, address is aabb.ccdd.eeff (bia aabb.ccdd.eeff)',
            '  MTU 1500 bytes, BW 1000000 Kbit/sec, DLY 10 usec,',
            '     reliability 255/255, txload 1/255, rxload 1/255',
            '  Encapsulation ARPA, loopback not set',
            '  Keepalive set (10 sec)',
            '  Full-duplex, 1000Mb/s, media type is 10/100/1000BaseTX',
            '  input flow-control is off, output flow-control is unsupported',
        ]);

        $result = $this->parser->parseShowInterface($output);

        $this->assertEquals('GigabitEthernet1/0/1', $result->interface);
        $this->assertEquals('up', $result->status);
        $this->assertEquals('1000Mb/s', $result->speed);
        $this->assertEquals('Full-duplex', $result->duplex);
        $this->assertEquals('up', $result->adminStatus);
    }

    public function test_parse_show_interface_extracts_status_down(): void
    {
        $output = implode("\r\n", [
            'GigabitEthernet1/0/2 is administratively down, line protocol is down (disabled)',
            '  Hardware is Gigabit Ethernet, address is aabb.ccdd.ee00 (bia aabb.ccdd.ee00)',
            '  Auto-duplex, Auto-speed, media type is 10/100/1000BaseTX',
        ]);

        $result = $this->parser->parseShowInterface($output);

        $this->assertEquals('GigabitEthernet1/0/2', $result->interface);
        $this->assertEquals('administratively down', $result->status);
        $this->assertEquals('Auto-speed', $result->speed);
        $this->assertEquals('Auto-duplex', $result->duplex);
        $this->assertEquals('down', $result->adminStatus);
    }

    public function test_parse_show_interface_extracts_vlan_empty_by_default(): void
    {
        $output = implode("\r\n", [
            'GigabitEthernet1/0/1 is up, line protocol is up (connected)',
            '  Full-duplex, 1000Mb/s, media type is 10/100/1000BaseTX',
        ]);

        $result = $this->parser->parseShowInterface($output);
        $this->assertEquals('', $result->vlan);
    }

    public function test_parse_show_interface_extracts_description(): void
    {
        $output = implode("\r\n", [
            'GigabitEthernet0/1 is up, line protocol is up (connected)',
            '  Hardware is Gigabit Ethernet, address is 0000.0000.0001',
            '  Description: Seat 24 - Row A',
            '  MTU 1500 bytes, BW 1000000 Kbit/sec, DLY 10 usec,',
            '  Full-duplex, 1000Mb/s, media type is 10/100/1000BaseTX',
        ]);

        $result = $this->parser->parseShowInterface($output);

        $this->assertEquals('Seat 24 - Row A', $result->description);
    }

    public function test_parse_interface_counters_extracts_values(): void
    {
        $output = implode("\r\n", [
            'GigabitEthernet1/0/1 is up, line protocol is up (connected)',
            '     12345 packets input, 6789012 bytes, 0 no buffer',
            '     3 input errors, 1 CRC, 0 frame, 0 overrun, 0 ignored',
            '     67890 packets output, 9876543 bytes, 0 underruns',
            '     5 output errors, 0 collisions, 0 interface resets',
        ]);

        $counters = $this->parser->parseInterfaceCounters($output);

        $this->assertEquals(6789012, $counters->inBytes);
        $this->assertEquals(9876543, $counters->outBytes);
        $this->assertEquals(3, $counters->inErrors);
        $this->assertEquals(5, $counters->outErrors);
    }

    public function test_parse_interface_counters_defaults_to_zero(): void
    {
        $output = 'GigabitEthernet1/0/1 is up, line protocol is up (connected)';

        $counters = $this->parser->parseInterfaceCounters($output);

        $this->assertEquals(0, $counters->inBytes);
        $this->assertEquals(0, $counters->outBytes);
        $this->assertEquals(0, $counters->inErrors);
        $this->assertEquals(0, $counters->outErrors);
    }

    public function test_parse_interface_status_table(): void
    {
        $output = implode("\r\n", [
            'Port      Name               Status       Vlan       Duplex  Speed Type',
            'Gi1/0/1   Server-1           connected    100        a-full  a-1000 10/100/1000BaseTX',
            'Gi1/0/2   Server-2           notconnect   100        auto    auto  10/100/1000BaseTX',
            'Gi1/0/3                      disabled     1          auto    auto  10/100/1000BaseTX',
            'Gi1/0/4   Uplink             connected    trunk      a-full  a-1000 10/100/1000BaseTX',
        ]);

        $ports = $this->parser->parseInterfaceStatusTable($output);

        $this->assertCount(4, $ports);
        $this->assertContainsOnlyInstancesOf(PortStatus::class, $ports);

        $this->assertEquals('Gi1/0/1', $ports[0]->interface);
        $this->assertEquals('connected', $ports[0]->status);
        $this->assertEquals('a-1000', $ports[0]->speed);
        $this->assertEquals('Server-1', $ports[0]->description);
        $this->assertEquals('a-full', $ports[0]->duplex);
        $this->assertEquals('100', $ports[0]->vlan);
        $this->assertEquals('access', $ports[0]->switchportMode);
        $this->assertEquals('up', $ports[0]->adminStatus);

        $this->assertEquals('Gi1/0/2', $ports[1]->interface);
        $this->assertEquals('notconnect', $ports[1]->status);
        $this->assertEquals('auto', $ports[1]->speed);
        $this->assertEquals('Server-2', $ports[1]->description);
        $this->assertEquals('auto', $ports[1]->duplex);
        $this->assertEquals('100', $ports[1]->vlan);
        $this->assertEquals('access', $ports[1]->switchportMode);
        $this->assertEquals('up', $ports[1]->adminStatus);

        $this->assertEquals('Gi1/0/3', $ports[2]->interface);
        $this->assertEquals('disabled', $ports[2]->status);
        $this->assertEquals('', $ports[2]->description);
        $this->assertEquals('auto', $ports[2]->duplex);
        $this->assertEquals('1', $ports[2]->vlan);
        $this->assertEquals('access', $ports[2]->switchportMode);
        $this->assertEquals('down', $ports[2]->adminStatus);

        $this->assertEquals('Gi1/0/4', $ports[3]->interface);
        $this->assertEquals('Uplink', $ports[3]->description);
        $this->assertEquals('a-full', $ports[3]->duplex);
        $this->assertEquals('', $ports[3]->vlan);
        $this->assertEquals('trunk', $ports[3]->switchportMode);
    }

    public function test_parse_interface_status_table_extracts_long_description(): void
    {
        $output = implode("\r\n", [
            'Port      Name               Status       Vlan       Duplex  Speed Type',
            'Gi0/24    Seat 24 - Row A    connected    400        a-full  a-1000 10/100/1000BaseTX',
        ]);

        $ports = $this->parser->parseInterfaceStatusTable($output);

        $this->assertCount(1, $ports);
        $this->assertEquals('Seat 24 - Row A', $ports[0]->description);
    }

    public function test_parse_interface_status_table_maps_trunk_vlan_to_switchport_mode(): void
    {
        $output = implode("\r\n", [
            'Port      Name               Status       Vlan       Duplex  Speed Type',
            'Gi0/3     Uplink to Core     connected    trunk      a-full  a-1000 10/100/1000BaseTX',
        ]);

        $ports = $this->parser->parseInterfaceStatusTable($output);

        $this->assertCount(1, $ports);
        $this->assertEquals('trunk', $ports[0]->switchportMode);
        $this->assertEquals('', $ports[0]->vlan);
    }

    public function test_parse_interface_status_table_maps_routed_vlan_to_switchport_mode(): void
    {
        $output = implode("\r\n", [
            'Port      Name               Status       Vlan       Duplex  Speed Type',
            'Gi0/4     Management         connected    routed     a-full  a-1000 10/100/1000BaseTX',
        ]);

        $ports = $this->parser->parseInterfaceStatusTable($output);

        $this->assertCount(1, $ports);
        $this->assertEquals('routed', $ports[0]->switchportMode);
        $this->assertEquals('', $ports[0]->vlan);
    }

    public function test_parse_interface_status_table_handles_unassigned_vlan(): void
    {
        $output = implode("\r\n", [
            'Port      Name               Status       Vlan       Duplex  Speed Type',
            'Gi0/5     Guest Desk         connected    unassigned a-full  a-1000 10/100/1000BaseTX',
        ]);

        $ports = $this->parser->parseInterfaceStatusTable($output);

        $this->assertCount(1, $ports);
        $this->assertEquals('unassigned', $ports[0]->switchportMode);
        $this->assertEquals('', $ports[0]->vlan);
    }

    public function test_parse_interface_status_table_handles_suspended_vlan(): void
    {
        $output = implode("\r\n", [
            'Port      Name               Status       Vlan       Duplex  Speed Type',
            'Gi0/6     Quarantine         connected    suspended  a-full  a-1000 10/100/1000BaseTX',
        ]);

        $ports = $this->parser->parseInterfaceStatusTable($output);

        $this->assertCount(1, $ports);
        $this->assertEquals('suspended', $ports[0]->switchportMode);
        $this->assertEquals('', $ports[0]->vlan);
    }

    public function test_parse_interface_status_table_handles_monitoring_status(): void
    {
        $output = implode("\r\n", [
            'Port      Name               Status       Vlan       Duplex  Speed Type',
            'Gi0/7     SPAN Session       monitoring   300        a-full  a-1000 10/100/1000BaseTX',
        ]);

        $ports = $this->parser->parseInterfaceStatusTable($output);

        $this->assertCount(1, $ports);
        $this->assertEquals('Gi0/7', $ports[0]->interface);
        $this->assertEquals('SPAN Session', $ports[0]->description);
        $this->assertEquals('monitoring', $ports[0]->status);
    }

    public function test_parse_interface_status_table_handles_ten_gigabit_interface(): void
    {
        $output = implode("\r\n", [
            'Port      Name               Status       Vlan       Duplex  Speed Type',
            'Te1/0/49   Uplink-Core        connected    trunk      a-full  10G   10GBase-LR',
        ]);

        $ports = $this->parser->parseInterfaceStatusTable($output);

        $this->assertCount(1, $ports);
        $this->assertEquals('Te1/0/49', $ports[0]->interface);
        $this->assertEquals('Uplink-Core', $ports[0]->description);
        $this->assertEquals('trunk', $ports[0]->switchportMode);
        $this->assertEquals('10G', $ports[0]->speed);
    }

    public function test_parse_interface_status_table_handles_fast_ethernet_interface(): void
    {
        $output = implode("\r\n", [
            'Port      Name               Status       Vlan       Duplex  Speed Type',
            'Fa0/1     Printer            connected    200        a-full  a-100  10/100BaseTX',
        ]);

        $ports = $this->parser->parseInterfaceStatusTable($output);

        $this->assertCount(1, $ports);
        $this->assertEquals('Fa0/1', $ports[0]->interface);
        $this->assertEquals('a-100', $ports[0]->speed);
        $this->assertEquals('a-full', $ports[0]->duplex);
        $this->assertEquals('200', $ports[0]->vlan);
        $this->assertEquals('access', $ports[0]->switchportMode);
    }

    public function test_parse_interface_status_table_maps_access_vlan_to_switchport_mode(): void
    {
        $output = implode("\r\n", [
            'Port      Name               Status       Vlan       Duplex  Speed Type',
            'Gi0/2     Server Room        connected    400        a-full  a-1000 10/100/1000BaseTX',
        ]);

        $ports = $this->parser->parseInterfaceStatusTable($output);

        $this->assertCount(1, $ports);
        $this->assertEquals('access', $ports[0]->switchportMode);
        $this->assertEquals('400', $ports[0]->vlan);
    }

    public function test_parse_interface_status_table_handles_single_space_before_status(): void
    {
        $output = implode("\r\n", [
            'Port         Name               Status       Vlan       Duplex  Speed Type',
            'Gi1/0/1      *** WAN ***        notconnect   200          auto   auto 10/100/1000BaseTX',
            'Gi1/0/5      *** Customer (VLAN notconnect   440          auto   auto 10/100/1000BaseTX',
            'Te1/0/46     *** Access Point * connected    400        a-full a-1000 100/1000/2.5G/5G/10GBaseTX',
            'Te1/0/48     *** Uplink ***     connected    trunk      a-full  a-10G 100/1000/2.5G/5G/10GBaseTX',
        ]);

        $ports = $this->parser->parseInterfaceStatusTable($output);

        $this->assertCount(4, $ports);

        $this->assertEquals('Gi1/0/1', $ports[0]->interface);
        $this->assertEquals('*** WAN ***', $ports[0]->description);
        $this->assertEquals('notconnect', $ports[0]->status);
        $this->assertEquals('200', $ports[0]->vlan);

        $this->assertEquals('Gi1/0/5', $ports[1]->interface);
        $this->assertEquals('*** Customer (VLAN', $ports[1]->description);
        $this->assertEquals('notconnect', $ports[1]->status);
        $this->assertEquals('440', $ports[1]->vlan);

        $this->assertEquals('Te1/0/46', $ports[2]->interface);
        $this->assertEquals('*** Access Point *', $ports[2]->description);
        $this->assertEquals('connected', $ports[2]->status);
        $this->assertEquals('400', $ports[2]->vlan);

        $this->assertEquals('Te1/0/48', $ports[3]->interface);
        $this->assertEquals('*** Uplink ***', $ports[3]->description);
        $this->assertEquals('connected', $ports[3]->status);
        $this->assertEquals('trunk', $ports[3]->switchportMode);
    }

    public function test_parse_interface_status_table_empty_output(): void
    {
        $output = 'Port      Name               Status       Vlan       Duplex  Speed Type';

        $ports = $this->parser->parseInterfaceStatusTable($output);
        $this->assertCount(0, $ports);
    }

    public function test_parse_mac_address_table(): void
    {
        $output = implode("\r\n", [
            '          Mac Address Table',
            '-------------------------------------------',
            '',
            'Vlan    Mac Address       Type        Ports',
            '----    -----------       --------    -----',
            ' 100    aabb.ccdd.eeff    DYNAMIC     Gi1/0/1',
            ' 100    1122.3344.5566    DYNAMIC     Gi1/0/2',
            ' 200    aabb.ccdd.0011    STATIC      Gi1/0/3',
            'Total Mac Addresses for this criterion: 3',
        ]);

        $entries = $this->parser->parseMacAddressTable($output);

        $this->assertCount(3, $entries);

        $this->assertEquals('aabb.ccdd.eeff', $entries[0]->mac);
        $this->assertEquals('Gi1/0/1', $entries[0]->port);
        $this->assertEquals(100, $entries[0]->vlan);

        $this->assertEquals('1122.3344.5566', $entries[1]->mac);
        $this->assertEquals('Gi1/0/2', $entries[1]->port);

        $this->assertEquals(200, $entries[2]->vlan);
    }

    public function test_parse_mac_address_table_empty(): void
    {
        $output = implode("\r\n", [
            '          Mac Address Table',
            '-------------------------------------------',
            '',
            'Vlan    Mac Address       Type        Ports',
            '----    -----------       --------    -----',
            'Total Mac Addresses for this criterion: 0',
        ]);

        $entries = $this->parser->parseMacAddressTable($output);
        $this->assertCount(0, $entries);
    }
}
