<?php

namespace Tests\Unit\Models;

use App\Models\IpAddress;
use App\Models\MacAddress;
use App\Services\Interfaces\NetworkInventoryInterface;
use App\Services\Interfaces\NetworkSwitchInterface;
use App\Services\ValueObjects\PortDetail;
use App\Services\ValueObjects\ResolvedPort;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Mockery;
use RuntimeException;
use Tests\TestCase;

class IpAddressLnmsTest extends TestCase
{
    use RefreshDatabase;

    private function mockInventory(?ResolvedPort $resolveResult = null, ?PortDetail $detailResult = null): void
    {
        $inventory = Mockery::mock(NetworkInventoryInterface::class);
        $inventory->shouldReceive('resolveIpToPort')->andReturn($resolveResult);
        if ($resolveResult instanceof ResolvedPort) {
            $inventory->shouldReceive('getPortDetail')->andReturn($detailResult);
        }

        $this->app->instance(NetworkInventoryInterface::class, $inventory);
    }

    public function test_port_returns_data_from_service(): void
    {
        $this->mockInventory(
            new ResolvedPort(ip: '10.0.0.1', mac: 'aa:bb:cc:dd:ee:ff', port: '42', switch: ''),
            new PortDetail(hostname: 'switch01.example.com', interface: 'GigabitEthernet0/1', status: 'up', adminStatus: 'up', speed: 1000000000),
        );

        $ip = IpAddress::factory()->create(['address' => '10.0.0.1']);

        $port = $ip->port;
        $this->assertNotNull($port);
        $this->assertSame('switch01.example.com', $port->hostname);
        $this->assertSame('GigabitEthernet0/1', $port->interface);
        $this->assertSame('up', $port->status);
        $this->assertSame('up', $port->adminStatus);
        $this->assertSame(1000000000, $port->speed);
    }

    public function test_port_caches_result(): void
    {
        $inventory = Mockery::mock(NetworkInventoryInterface::class);
        $inventory->shouldReceive('resolveIpToPort')
            ->once()
            ->andReturn(new ResolvedPort(ip: '10.0.0.1', mac: 'aa', port: '42', switch: ''));
        $inventory->shouldReceive('getPortDetail')
            ->once()
            ->andReturn(new PortDetail(hostname: 'sw', interface: 'Gi0/1', status: 'up', adminStatus: 'up', speed: 1000));
        $this->app->instance(NetworkInventoryInterface::class, $inventory);

        $ip = IpAddress::factory()->create(['address' => '10.0.0.1']);

        $port1 = $ip->port;
        $port2 = $ip->port;
        $this->assertSame($port1, $port2);
    }

    public function test_port_returns_null_when_resolve_fails(): void
    {
        $this->mockInventory();

        $ip = IpAddress::factory()->create(['address' => '10.0.0.1']);
        $this->assertNull($ip->port);
    }

    public function test_port_returns_null_when_detail_fails(): void
    {
        $this->mockInventory(
            new ResolvedPort(ip: '10.0.0.1', mac: 'aa', port: '42', switch: ''),
        );

        $ip = IpAddress::factory()->create(['address' => '10.0.0.1']);
        $this->assertNull($ip->port);
    }

    public function test_mac_returns_value_from_relationship(): void
    {
        $mac = MacAddress::factory()->create(['mac_address' => 'AA:BB:CC:DD:EE:FF']);
        $ip = IpAddress::factory()->create(['mac_address_id' => $mac->id]);

        $this->assertSame('AA:BB:CC:DD:EE:FF', $ip->mac);
    }

    public function test_mac_returns_null_when_no_relationship(): void
    {
        $ip = IpAddress::factory()->create(['mac_address_id' => null]);
        $this->assertNull($ip->mac);
    }

    public function test_port_updated_at_returns_null(): void
    {
        $ip = IpAddress::factory()->create();
        $this->assertNull($ip->portUpdatedAt);
    }

    public function test_port_returns_null_on_service_exception(): void
    {
        $inventory = Mockery::mock(NetworkInventoryInterface::class);
        $inventory->shouldReceive('resolveIpToPort')->andThrow(new RuntimeException('Service down'));
        $this->app->instance(NetworkInventoryInterface::class, $inventory);

        $ip = IpAddress::factory()->create(['address' => '10.0.0.1']);
        $this->assertNull($ip->port);
    }

    public function test_shut_port_calls_switch_interface(): void
    {
        $this->mockInventory(
            new ResolvedPort(ip: '10.0.0.1', mac: 'aa', port: '42', switch: ''),
            new PortDetail(hostname: 'sw', interface: 'GigabitEthernet0/1', status: 'up', adminStatus: 'up', speed: 1000),
        );

        $switch = Mockery::mock(NetworkSwitchInterface::class);
        $switch->shouldReceive('shutdownPort')->with('GigabitEthernet0/1')->once()->andReturnTrue();
        $this->app->instance(NetworkSwitchInterface::class, $switch);

        $ip = IpAddress::factory()->create(['address' => '10.0.0.1']);
        $ip->shutPort(false);
    }

    public function test_unshut_port_calls_switch_interface(): void
    {
        $this->mockInventory(
            new ResolvedPort(ip: '10.0.0.1', mac: 'aa', port: '42', switch: ''),
            new PortDetail(hostname: 'sw', interface: 'GigabitEthernet0/1', status: 'up', adminStatus: 'up', speed: 1000),
        );

        $switch = Mockery::mock(NetworkSwitchInterface::class);
        $switch->shouldReceive('enablePort')->with('GigabitEthernet0/1')->once()->andReturnTrue();
        $this->app->instance(NetworkSwitchInterface::class, $switch);

        $ip = IpAddress::factory()->create(['address' => '10.0.0.1']);
        $ip->unshutPort(false);
    }
}
