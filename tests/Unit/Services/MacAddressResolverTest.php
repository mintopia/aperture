<?php

namespace Tests\Unit\Services;

use App\Services\Interfaces\DhcpInterface;
use App\Services\Interfaces\IpMacResolverInterface;
use App\Services\Interfaces\MacAddressResolverInterface;
use App\Services\Kea\KeaClient;
use App\Services\Kea\KeaDhcpService;
use App\Services\MacAddressResolver;
use App\Services\ValueObjects\DhcpLease;
use App\Services\ValueObjects\IpMacEntry;
use Illuminate\Support\Facades\Http;
use Mockery;
use Tests\TestCase;

class MacAddressResolverTest extends TestCase
{
    public function test_resolves_mac_from_kea_lease_without_consulting_ip_mac_fallback(): void
    {
        Http::fake([
            'kea.local' => Http::response([
                [
                    'result' => 0,
                    'arguments' => [
                        'state' => 0,
                        'cltt' => now()->getTimestamp() - 1_000,
                        'valid-lft' => 2_000,
                        'hw-address' => 'aa:bb:cc:dd:ee:ff',
                        'hostname' => 'workstation-1',
                    ],
                ],
            ]),
        ]);

        $dhcp = new KeaDhcpService(new KeaClient(endpoint: 'https://kea.local'));

        $ipMac = Mockery::mock(IpMacResolverInterface::class);
        $ipMac->shouldNotReceive('getIpMacTable');

        $resolver = new MacAddressResolver($dhcp, $ipMac);
        $result = $resolver->resolveIpToMac('192.168.1.50');

        $this->assertSame('AA:BB:CC:DD:EE:FF', $result);
    }

    public function test_falls_back_to_ip_mac_table_when_kea_has_no_active_lease(): void
    {
        Http::fake([
            'kea.local' => Http::response([
                ['result' => 3, 'text' => 'no leases found'],
            ]),
        ]);

        $dhcp = new KeaDhcpService(new KeaClient(endpoint: 'https://kea.local'));

        $ipMac = Mockery::mock(IpMacResolverInterface::class);
        $ipMac->shouldReceive('getIpMacTable')
            ->andReturn(collect([
                new IpMacEntry(ip: '192.168.1.50', mac: '11:22:33:44:55:66'),
            ]));

        $resolver = new MacAddressResolver($dhcp, $ipMac);
        $result = $resolver->resolveIpToMac('192.168.1.50');

        $this->assertSame('11:22:33:44:55:66', $result);
    }

    public function test_resolves_mac_from_dhcp_lease(): void
    {
        $dhcp = Mockery::mock(DhcpInterface::class);
        $dhcp->shouldReceive('getLease')
            ->with('192.168.1.100')
            ->andReturn(new DhcpLease(ip: '192.168.1.100', mac: 'aa:bb:cc:dd:ee:ff', hostname: 'test', expires: ''));

        $inventory = Mockery::mock(IpMacResolverInterface::class);
        $inventory->shouldNotReceive('getIpMacTable');

        $resolver = new MacAddressResolver($dhcp, $inventory);
        $result = $resolver->resolveIpToMac('192.168.1.100');

        $this->assertSame('AA:BB:CC:DD:EE:FF', $result);
    }

    public function test_falls_back_to_ip_mac_table_when_dhcp_returns_null(): void
    {
        $dhcp = Mockery::mock(DhcpInterface::class);
        $dhcp->shouldReceive('getLease')
            ->with('192.168.1.100')
            ->andReturnNull();

        $inventory = Mockery::mock(IpMacResolverInterface::class);
        $inventory->shouldReceive('getIpMacTable')
            ->andReturn(collect([
                new IpMacEntry(ip: '192.168.1.100', mac: 'aa:bb:cc:dd:ee:ff'),
                new IpMacEntry(ip: '192.168.1.101', mac: '11:22:33:44:55:66'),
            ]));

        $resolver = new MacAddressResolver($dhcp, $inventory);
        $result = $resolver->resolveIpToMac('192.168.1.100');

        $this->assertSame('AA:BB:CC:DD:EE:FF', $result);
    }

    public function test_returns_null_when_both_sources_fail(): void
    {
        $dhcp = Mockery::mock(DhcpInterface::class);
        $dhcp->shouldReceive('getLease')->andReturnNull();

        $inventory = Mockery::mock(IpMacResolverInterface::class);
        $inventory->shouldReceive('getIpMacTable')->andReturn(collect([]));

        $resolver = new MacAddressResolver($dhcp, $inventory);
        $result = $resolver->resolveIpToMac('192.168.1.200');

        $this->assertNull($result);
    }

    public function test_normalizes_mac_from_cisco_format(): void
    {
        $dhcp = Mockery::mock(DhcpInterface::class);
        $dhcp->shouldReceive('getLease')
            ->andReturn(new DhcpLease(ip: '10.0.0.1', mac: 'aabb.ccdd.eeff', hostname: '', expires: ''));

        $inventory = Mockery::mock(IpMacResolverInterface::class);

        $resolver = new MacAddressResolver($dhcp, $inventory);
        $result = $resolver->resolveIpToMac('10.0.0.1');

        $this->assertSame('AA:BB:CC:DD:EE:FF', $result);
    }

    public function test_container_binding_resolves_correctly(): void
    {
        $this->app->instance(DhcpInterface::class, Mockery::mock(DhcpInterface::class));
        $this->app->instance(IpMacResolverInterface::class, Mockery::mock(IpMacResolverInterface::class));

        $resolver = $this->app->make(MacAddressResolverInterface::class);

        $this->assertInstanceOf(MacAddressResolver::class, $resolver);
    }
}
