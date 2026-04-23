<?php

declare(strict_types=1);

namespace Tests\Unit\Services\NetworkSwitch;

use App\Services\NetworkSwitch\IosOutputParser;
use Tests\TestCase;

class CiscoBulkCommandTest extends TestCase
{
    private IosOutputParser $parser;

    protected function setUp(): void
    {
        parent::setUp();
        $this->parser = new IosOutputParser;
    }

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
}
