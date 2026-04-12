<?php

namespace Tests\Unit\Models;

use App\Models\IpAddress;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;
use Throwable;

class IpAddressLnmsTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        config([
            'aperture.lnms.enabled' => true,
            'database.connections.lnms' => [
                'driver' => 'sqlite',
                'database' => ':memory:',
                'prefix' => '',
            ],
        ]);

        DB::connection('lnms')->statement('CREATE TABLE devices (device_id INTEGER PRIMARY KEY, hostname TEXT)');
        DB::connection('lnms')->statement('CREATE TABLE ports (port_id INTEGER PRIMARY KEY, device_id INTEGER, ifName TEXT, ifOperStatus TEXT, ifAdminStatus TEXT, ifSpeed INTEGER)');
        DB::connection('lnms')->statement('CREATE TABLE ipv4_mac (id INTEGER PRIMARY KEY, ipv4_address TEXT, mac_address TEXT)');
        DB::connection('lnms')->statement('CREATE TABLE ports_fdb (id INTEGER PRIMARY KEY, mac_address TEXT, port_id INTEGER, updated_at TEXT)');
    }

    protected function seedLnmsData(string $ip = '10.0.0.1'): void
    {
        DB::connection('lnms')->table('devices')->insert(['device_id' => 1, 'hostname' => 'switch01.example.com']);
        DB::connection('lnms')->table('ports')->insert([
            'port_id' => 1,
            'device_id' => 1,
            'ifName' => 'GigabitEthernet0/1',
            'ifOperStatus' => 'up',
            'ifAdminStatus' => 'up',
            'ifSpeed' => 1000000000,
        ]);
        DB::connection('lnms')->table('ipv4_mac')->insert(['ipv4_address' => $ip, 'mac_address' => 'AA:BB:CC:DD:EE:FF']);
        DB::connection('lnms')->table('ports_fdb')->insert(['mac_address' => 'AA:BB:CC:DD:EE:FF', 'port_id' => 1, 'updated_at' => '2024-01-15 10:00:00']);
    }

    public function test_get_lnms_data_returns_data_when_enabled(): void
    {
        $ip = new IpAddress;
        $ip->address = '10.0.0.1';
        $ip->last_seen_at = now();
        $ip->save();

        $this->seedLnmsData();

        $result = $ip->getLNMSData();

        $this->assertIsArray($result);
        $this->assertCount(1, $result);
        $this->assertEquals('AA:BB:CC:DD:EE:FF', $result[0]->mac);
        $this->assertEquals('switch01.example.com', $result[0]->port->switch);
        $this->assertEquals('GigabitEthernet0/1', $result[0]->port->interface);
        $this->assertEquals('up', $result[0]->port->status);
        $this->assertEquals('up', $result[0]->port->adminStatus);
        $this->assertEquals(1000000000, $result[0]->port->speed);
    }

    public function test_get_lnms_data_caches_result(): void
    {
        $ip = new IpAddress;
        $ip->address = '10.0.0.1';
        $ip->last_seen_at = now();
        $ip->save();

        $this->seedLnmsData();

        $result1 = $ip->getLNMSData();
        $result2 = $ip->getLNMSData();

        $this->assertSame($result1, $result2);
    }

    public function test_get_lnms_data_returns_empty_for_unknown_ip(): void
    {
        $ip = new IpAddress;
        $ip->address = '192.168.1.1';
        $ip->last_seen_at = now();
        $ip->save();

        $this->seedLnmsData('10.0.0.1');

        $result = $ip->getLNMSData();
        $this->assertIsArray($result);
        $this->assertCount(0, $result);
    }

    public function test_get_lnms_data_sorts_by_updated_at_descending(): void
    {
        $ip = new IpAddress;
        $ip->address = '10.0.0.1';
        $ip->last_seen_at = now();
        $ip->save();

        DB::connection('lnms')->table('devices')->insert(['device_id' => 1, 'hostname' => 'switch01']);
        DB::connection('lnms')->table('devices')->insert(['device_id' => 2, 'hostname' => 'switch02']);
        DB::connection('lnms')->table('ports')->insert([
            'port_id' => 1, 'device_id' => 1, 'ifName' => 'Gi0/1',
            'ifOperStatus' => 'up', 'ifAdminStatus' => 'up', 'ifSpeed' => 1000,
        ]);
        DB::connection('lnms')->table('ports')->insert([
            'port_id' => 2, 'device_id' => 2, 'ifName' => 'Gi0/2',
            'ifOperStatus' => 'up', 'ifAdminStatus' => 'up', 'ifSpeed' => 2000,
        ]);
        DB::connection('lnms')->table('ipv4_mac')->insert(['ipv4_address' => '10.0.0.1', 'mac_address' => 'AA:BB:CC:DD:EE:01']);
        DB::connection('lnms')->table('ipv4_mac')->insert(['ipv4_address' => '10.0.0.1', 'mac_address' => 'AA:BB:CC:DD:EE:02']);
        DB::connection('lnms')->table('ports_fdb')->insert(['mac_address' => 'AA:BB:CC:DD:EE:01', 'port_id' => 1, 'updated_at' => '2024-01-10 10:00:00']);
        DB::connection('lnms')->table('ports_fdb')->insert(['mac_address' => 'AA:BB:CC:DD:EE:02', 'port_id' => 2, 'updated_at' => '2024-01-15 10:00:00']);

        $result = $ip->getLNMSData();

        $this->assertCount(2, $result);
        $this->assertEquals('switch02', $result[0]->port->switch);
        $this->assertEquals('switch01', $result[1]->port->switch);
    }

    public function test_mac_returns_value_when_lnms_enabled(): void
    {
        $ip = new IpAddress;
        $ip->address = '10.0.0.1';
        $ip->last_seen_at = now();
        $ip->save();

        $this->seedLnmsData();

        $this->assertEquals('AA:BB:CC:DD:EE:FF', $ip->mac);
    }

    public function test_port_returns_object_when_lnms_enabled(): void
    {
        $ip = new IpAddress;
        $ip->address = '10.0.0.1';
        $ip->last_seen_at = now();
        $ip->save();

        $this->seedLnmsData();

        $port = $ip->port;
        $this->assertNotNull($port);
        $this->assertEquals('switch01.example.com', $port->switch);
        $this->assertEquals('GigabitEthernet0/1', $port->interface);
    }

    public function test_port_updated_at_returns_carbon_when_lnms_enabled(): void
    {
        $ip = new IpAddress;
        $ip->address = '10.0.0.1';
        $ip->last_seen_at = now();
        $ip->save();

        $this->seedLnmsData();

        $updatedAt = $ip->portUpdatedAt;
        $this->assertNotNull($updatedAt);
        $this->assertInstanceOf(Carbon::class, $updatedAt);
    }

    public function test_shut_port_with_port_data_throws_cisco_exception(): void
    {
        $ip = new IpAddress;
        $ip->address = '10.0.0.1';
        $ip->last_seen_at = now();
        $ip->save();

        $this->seedLnmsData();

        config([
            'aperture.cisco.username' => 'admin',
            'aperture.cisco.password' => 'pass',
            'aperture.cisco.enable' => 'enable',
        ]);

        // shutPort(false) calls new CiscoService() then shutInterface()
        // which tries SSH connection and fails
        $this->expectException(Throwable::class);
        $ip->shutPort(false);
    }

    public function test_unshut_port_with_port_data_throws_cisco_exception(): void
    {
        $ip = new IpAddress;
        $ip->address = '10.0.0.1';
        $ip->last_seen_at = now();
        $ip->save();

        $this->seedLnmsData();

        config([
            'aperture.cisco.username' => 'admin',
            'aperture.cisco.password' => 'pass',
            'aperture.cisco.enable' => 'enable',
        ]);

        $this->expectException(Throwable::class);
        $ip->unshutPort(false);
    }
}
