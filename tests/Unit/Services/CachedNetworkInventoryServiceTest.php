<?php

declare(strict_types=1);

namespace Tests\Unit\Services;

use App\Services\CachedNetworkInventoryService;
use App\Services\Interfaces\NetworkInventoryInterface;
use App\Services\ValueObjects\PortDetail;
use App\Services\ValueObjects\ResolvedPort;
use Illuminate\Cache\ArrayStore;
use Illuminate\Cache\Repository;
use Mockery;
use Tests\TestCase;

class CachedNetworkInventoryServiceTest extends TestCase
{
    private Repository $cache;

    private $inner;

    private CachedNetworkInventoryService $service;

    protected function setUp(): void
    {
        parent::setUp();
        $this->cache = new Repository(new ArrayStore);
        $this->inner = Mockery::mock(NetworkInventoryInterface::class);
        $this->service = new CachedNetworkInventoryService($this->inner, $this->cache, 5);
    }

    public function test_get_arp_table_caches_result(): void
    {
        $expected = collect([['ip' => '10.0.0.1', 'mac' => 'aa:bb:cc:dd:ee:ff']]);
        $this->inner->shouldReceive('getArpTable')->once()->andReturn($expected);

        $result1 = $this->service->getArpTable();
        $result2 = $this->service->getArpTable();

        $this->assertEquals($expected, $result1);
        $this->assertEquals($expected, $result2);
    }

    public function test_get_forwarding_database_caches_result(): void
    {
        $expected = collect([['mac' => 'aa:bb:cc:dd:ee:ff', 'port' => '1', 'vlan' => 1]]);
        $this->inner->shouldReceive('getForwardingDatabase')->once()->andReturn($expected);

        $result1 = $this->service->getForwardingDatabase();
        $result2 = $this->service->getForwardingDatabase();

        $this->assertEquals($expected, $result1);
        $this->assertEquals($expected, $result2);
    }

    public function test_get_ipv6_neighbors_caches_result(): void
    {
        $expected = collect([['ip' => 'fe80::1', 'mac' => '11:22:33:44:55:66']]);
        $this->inner->shouldReceive('getIpv6Neighbors')->once()->andReturn($expected);

        $result1 = $this->service->getIpv6Neighbors();
        $result2 = $this->service->getIpv6Neighbors();

        $this->assertEquals($expected, $result1);
        $this->assertEquals($expected, $result2);
    }

    public function test_get_device_list_caches_result(): void
    {
        $expected = collect([['hostname' => 'switch1', 'ip' => '10.0.0.100', 'type' => 'network']]);
        $this->inner->shouldReceive('getDeviceList')->once()->andReturn($expected);

        $result1 = $this->service->getDeviceList();
        $result2 = $this->service->getDeviceList();

        $this->assertEquals($expected, $result1);
        $this->assertEquals($expected, $result2);
    }

    public function test_resolve_ip_to_port_caches_per_ip(): void
    {
        $result1Data = new ResolvedPort(ip: '10.0.0.1', mac: 'aa', port: '1', switch: 's1');
        $result2Data = new ResolvedPort(ip: '10.0.0.2', mac: 'bb', port: '2', switch: 's1');

        $this->inner->shouldReceive('resolveIpToPort')->with('10.0.0.1')->once()->andReturn($result1Data);
        $this->inner->shouldReceive('resolveIpToPort')->with('10.0.0.2')->once()->andReturn($result2Data);

        $r1a = $this->service->resolveIpToPort('10.0.0.1');
        $r1b = $this->service->resolveIpToPort('10.0.0.1');
        $r2a = $this->service->resolveIpToPort('10.0.0.2');

        $this->assertEquals($result1Data, $r1a);
        $this->assertEquals($result1Data, $r1b);
        $this->assertEquals($result2Data, $r2a);
    }

    public function test_container_resolves_network_inventory_interface(): void
    {
        config([
            'aperture.librenms.endpoint' => 'http://localhost',
            'aperture.librenms.api_token' => 'test-token',
        ]);

        $resolved = $this->app->make(NetworkInventoryInterface::class);
        $this->assertInstanceOf(CachedNetworkInventoryService::class, $resolved);
    }

    public function test_get_port_detail_caches_per_port_id(): void
    {
        $portData = new PortDetail(hostname: 'switch01', interface: 'Gi0/1', status: 'up', adminStatus: 'up', speed: 1000);

        $this->inner->shouldReceive('getPortDetail')->with('42')->once()->andReturn($portData);

        $r1 = $this->service->getPortDetail('42');
        $r2 = $this->service->getPortDetail('42');

        $this->assertEquals($portData, $r1);
        $this->assertEquals($portData, $r2);
    }
}
