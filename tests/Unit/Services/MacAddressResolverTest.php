<?php

namespace Tests\Unit\Services;

use App\Services\Interfaces\DhcpInterface;
use App\Services\Interfaces\IpMacResolverInterface;
use App\Services\Interfaces\MacAddressResolverInterface;
use App\Services\Kea\KeaClient;
use App\Services\Kea\KeaDhcpService;
use App\Services\MacAddressResolver;
use App\Services\ValueObjects\DhcpLease;
use App\Services\ValueObjects\DhcpSnapshot;
use App\Services\ValueObjects\IpMacEntry;
use Illuminate\Support\Facades\Http;
use Mockery;
use PHPUnit\Framework\Attributes\DataProvider;
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

    public static function resolveMacToIpsProvider(): array
    {
        return [
            'returns matching leases' => [
                [['10.0.0.10', 'aa:bb:cc:dd:ee:ff', 'host1'], ['10.0.0.20', '11:22:33:44:55:66', 'host2']],
                'aa:bb:cc:dd:ee:ff',
                [['ip' => '10.0.0.10', 'hostname' => 'host1']],
            ],
            'returns empty array when no match' => [
                [['10.0.0.10', '11:22:33:44:55:66', 'other']],
                'aa:bb:cc:dd:ee:ff',
                [],
            ],
            'returns multiple ips for same mac' => [
                [
                    ['10.0.0.10', 'aa:bb:cc:dd:ee:ff', 'host1'],
                    ['10.0.0.20', 'aa:bb:cc:dd:ee:ff', 'host2'],
                    ['10.0.0.30', '11:22:33:44:55:66', 'other'],
                ],
                'aa:bb:cc:dd:ee:ff',
                [['ip' => '10.0.0.10', 'hostname' => 'host1'], ['ip' => '10.0.0.20', 'hostname' => 'host2']],
            ],
            'normalizes mac format' => [
                [['10.0.0.10', 'aabb.ccdd.eeff', 'cisco-host']],
                'AA:BB:CC:DD:EE:FF',
                [['ip' => '10.0.0.10', 'hostname' => 'cisco-host']],
            ],
        ];
    }

    /**
     * @param  list<array{0: string, 1: string, 2: string}>  $leaseRows
     * @param  list<array{ip: string, hostname: string}>  $expected
     */
    #[DataProvider('resolveMacToIpsProvider')]
    public function test_resolve_mac_to_ips(array $leaseRows, string $queryMac, array $expected): void
    {
        $dhcp = Mockery::mock(DhcpInterface::class);
        $dhcp->shouldReceive('snapshot')
            ->once()
            ->andReturn(DhcpSnapshot::create(collect(array_map(
                fn (array $row): DhcpLease => new DhcpLease(ip: $row[0], mac: $row[1], hostname: $row[2], expires: ''),
                $leaseRows,
            )), collect()));

        $inventory = Mockery::mock(IpMacResolverInterface::class);

        $resolver = new MacAddressResolver($dhcp, $inventory);
        $result = $resolver->resolveMacToIps($queryMac);

        $this->assertCount(count($expected), $result);
        foreach ($expected as $i => $row) {
            $this->assertSame($row['ip'], $result[$i]['ip']);
            $this->assertSame($row['hostname'], $result[$i]['hostname']);
        }
    }

    public function test_container_binding_resolves_correctly(): void
    {
        $this->app->instance(DhcpInterface::class, Mockery::mock(DhcpInterface::class));
        $this->app->instance(IpMacResolverInterface::class, Mockery::mock(IpMacResolverInterface::class));

        $resolver = $this->app->make(MacAddressResolverInterface::class);

        $this->assertInstanceOf(MacAddressResolver::class, $resolver);
    }
}
