<?php

namespace Tests\Unit\Services;

use App\Services\Interfaces\DhcpInterface;
use App\Services\Interfaces\MacAddressResolverInterface;
use App\Services\Interfaces\NetworkInventoryInterface;
use App\Services\MacAddressResolver;
use App\Services\ValueObjects\ArpEntry;
use App\Services\ValueObjects\DhcpLease;
use Mockery;
use Tests\TestCase;

class MacAddressResolverTest extends TestCase
{
    public function test_resolves_mac_from_dhcp_lease(): void
    {
        $dhcp = Mockery::mock(DhcpInterface::class);
        $dhcp->shouldReceive('getLease')
            ->with('192.168.1.100')
            ->andReturn(new DhcpLease(ip: '192.168.1.100', mac: 'aa:bb:cc:dd:ee:ff', hostname: 'test', expires: ''));

        $inventory = Mockery::mock(NetworkInventoryInterface::class);
        $inventory->shouldNotReceive('getArpTable');

        $resolver = new MacAddressResolver($dhcp, $inventory);
        $result = $resolver->resolveIpToMac('192.168.1.100');

        $this->assertSame('AA:BB:CC:DD:EE:FF', $result);
    }

    public function test_falls_back_to_arp_when_dhcp_returns_null(): void
    {
        $dhcp = Mockery::mock(DhcpInterface::class);
        $dhcp->shouldReceive('getLease')
            ->with('192.168.1.100')
            ->andReturnNull();

        $inventory = Mockery::mock(NetworkInventoryInterface::class);
        $inventory->shouldReceive('getArpTable')
            ->andReturn(collect([
                new ArpEntry(ip: '192.168.1.100', mac: 'aa:bb:cc:dd:ee:ff'),
                new ArpEntry(ip: '192.168.1.101', mac: '11:22:33:44:55:66'),
            ]));

        $resolver = new MacAddressResolver($dhcp, $inventory);
        $result = $resolver->resolveIpToMac('192.168.1.100');

        $this->assertSame('AA:BB:CC:DD:EE:FF', $result);
    }

    public function test_returns_null_when_both_sources_fail(): void
    {
        $dhcp = Mockery::mock(DhcpInterface::class);
        $dhcp->shouldReceive('getLease')->andReturnNull();

        $inventory = Mockery::mock(NetworkInventoryInterface::class);
        $inventory->shouldReceive('getArpTable')->andReturn(collect([]));

        $resolver = new MacAddressResolver($dhcp, $inventory);
        $result = $resolver->resolveIpToMac('192.168.1.200');

        $this->assertNull($result);
    }

    public function test_normalizes_mac_from_cisco_format(): void
    {
        $dhcp = Mockery::mock(DhcpInterface::class);
        $dhcp->shouldReceive('getLease')
            ->andReturn(new DhcpLease(ip: '10.0.0.1', mac: 'aabb.ccdd.eeff', hostname: '', expires: ''));

        $inventory = Mockery::mock(NetworkInventoryInterface::class);

        $resolver = new MacAddressResolver($dhcp, $inventory);
        $result = $resolver->resolveIpToMac('10.0.0.1');

        $this->assertSame('AA:BB:CC:DD:EE:FF', $result);
    }

    public function test_resolve_mac_to_ips_returns_matching_leases(): void
    {
        $dhcp = Mockery::mock(DhcpInterface::class);
        $dhcp->shouldReceive('getLeases')
            ->once()
            ->andReturn(collect([
                new DhcpLease(ip: '10.0.0.10', mac: 'aa:bb:cc:dd:ee:ff', hostname: 'host1', expires: ''),
                new DhcpLease(ip: '10.0.0.20', mac: '11:22:33:44:55:66', hostname: 'host2', expires: ''),
            ]));

        $inventory = Mockery::mock(NetworkInventoryInterface::class);

        $resolver = new MacAddressResolver($dhcp, $inventory);
        $result = $resolver->resolveMacToIps('aa:bb:cc:dd:ee:ff');

        $this->assertCount(1, $result);
        $this->assertSame('10.0.0.10', $result[0]['ip']);
        $this->assertSame('host1', $result[0]['hostname']);
    }

    public function test_resolve_mac_to_ips_returns_empty_array_when_no_match(): void
    {
        $dhcp = Mockery::mock(DhcpInterface::class);
        $dhcp->shouldReceive('getLeases')
            ->once()
            ->andReturn(collect([
                new DhcpLease(ip: '10.0.0.10', mac: '11:22:33:44:55:66', hostname: 'other', expires: ''),
            ]));

        $inventory = Mockery::mock(NetworkInventoryInterface::class);

        $resolver = new MacAddressResolver($dhcp, $inventory);
        $result = $resolver->resolveMacToIps('aa:bb:cc:dd:ee:ff');

        $this->assertSame([], $result);
    }

    public function test_resolve_mac_to_ips_returns_multiple_ips_for_same_mac(): void
    {
        $dhcp = Mockery::mock(DhcpInterface::class);
        $dhcp->shouldReceive('getLeases')
            ->once()
            ->andReturn(collect([
                new DhcpLease(ip: '10.0.0.10', mac: 'aa:bb:cc:dd:ee:ff', hostname: 'host1', expires: ''),
                new DhcpLease(ip: '10.0.0.20', mac: 'aa:bb:cc:dd:ee:ff', hostname: 'host2', expires: ''),
                new DhcpLease(ip: '10.0.0.30', mac: '11:22:33:44:55:66', hostname: 'other', expires: ''),
            ]));

        $inventory = Mockery::mock(NetworkInventoryInterface::class);

        $resolver = new MacAddressResolver($dhcp, $inventory);
        $result = $resolver->resolveMacToIps('aa:bb:cc:dd:ee:ff');

        $this->assertCount(2, $result);
        $this->assertSame('10.0.0.10', $result[0]['ip']);
        $this->assertSame('host1', $result[0]['hostname']);
        $this->assertSame('10.0.0.20', $result[1]['ip']);
        $this->assertSame('host2', $result[1]['hostname']);
    }

    public function test_resolve_mac_to_ips_normalizes_mac_format(): void
    {
        $dhcp = Mockery::mock(DhcpInterface::class);
        $dhcp->shouldReceive('getLeases')
            ->once()
            ->andReturn(collect([
                new DhcpLease(ip: '10.0.0.10', mac: 'aabb.ccdd.eeff', hostname: 'cisco-host', expires: ''),
            ]));

        $inventory = Mockery::mock(NetworkInventoryInterface::class);

        $resolver = new MacAddressResolver($dhcp, $inventory);
        $result = $resolver->resolveMacToIps('AA:BB:CC:DD:EE:FF');

        $this->assertCount(1, $result);
        $this->assertSame('10.0.0.10', $result[0]['ip']);
        $this->assertSame('cisco-host', $result[0]['hostname']);
    }

    public function test_container_binding_resolves_correctly(): void
    {
        $this->app->instance(DhcpInterface::class, Mockery::mock(DhcpInterface::class));
        $this->app->instance(NetworkInventoryInterface::class, Mockery::mock(NetworkInventoryInterface::class));

        $resolver = $this->app->make(MacAddressResolverInterface::class);

        $this->assertInstanceOf(MacAddressResolver::class, $resolver);
    }
}
