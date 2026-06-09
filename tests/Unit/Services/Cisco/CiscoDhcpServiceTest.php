<?php

declare(strict_types=1);

namespace Tests\Unit\Services\Cisco;

use App\Services\Cisco\CiscoDhcpService;
use App\Services\Interfaces\SwitchCommandTransportInterface;
use App\Services\NetworkSwitch\IosOutputParser;
use App\Services\ValueObjects\DhcpLease;
use App\Services\ValueObjects\DhcpPoolStatus;
use App\Services\ValueObjects\DhcpRange;
use Mockery;
use Mockery\MockInterface;
use RuntimeException;
use Tests\TestCase;

class CiscoDhcpServiceTest extends TestCase
{
    private SwitchCommandTransportInterface&MockInterface $transport;

    private IosOutputParser $parser;

    protected function setUp(): void
    {
        parent::setUp();
        $this->transport = Mockery::mock(SwitchCommandTransportInterface::class);
        $this->parser = new IosOutputParser;
    }

    // -------------------------------------------------------------------------
    // Fixture helpers
    // -------------------------------------------------------------------------

    private function ipv4BindingOutput(): string
    {
        return implode("\r\n", [
            'Bindings from all pools not associated with VRF:',
            'IP address          Client-ID/              Lease expiration        Type       State      Interface',
            '                    Hardware address/',
            '                    User name',
            '10.0.0.50           0100.1122.3344.55       Jun 08 2026 12:00 AM    Automatic  Active     Vlan100',
            '10.0.0.51           0100.aabb.ccdd.ee       Jun 08 2026 01:00 AM    Automatic  Active     Vlan100',
        ]);
    }

    private function ipv4PoolStatsOutput(): string
    {
        return implode("\n", [
            'Pool LAN :',
            ' Utilization mark (high/low)    : 100 / 0',
            ' Subnet size (first/next)       : 0 / 0',
            ' Total addresses                : 254',
            ' Leased addresses               : 2',
            ' Pending event                  : none',
        ]);
    }

    private function ipv4PoolConfigOutput(): string
    {
        return implode("\n", [
            'ip dhcp excluded-address 10.0.0.1 10.0.0.9',
            '!',
            'ip dhcp pool LAN',
            ' network 10.0.0.0 255.255.255.0',
            ' default-router 10.0.0.1',
            '!',
        ]);
    }

    private function ipv6BindingOutput(): string
    {
        return implode("\n", [
            'Client: FE80::1',
            '  DUID: 00030001AABBCCDDEEFF',
            '  Username : unassigned',
            '  VRF : default',
            '  IA NA: IA ID 0x00000001, T1 43200, T2 69120',
            '    Address: 2001:DB8::100',
            '            preferred lifetime 86400, valid lifetime 172800',
            '            expires at Jun 09 2026 12:00 AM (172800 seconds)',
        ]);
    }

    private function ipv6PoolStatsOutput(): string
    {
        return implode("\n", [
            'DHCPv6 pool: LAN6',
            '  Address allocation prefix: 2001:DB8::/64',
            '  DNS server: 2001:4860:4860::8888',
            '  Active clients: 1',
        ]);
    }

    private function ipv6PoolConfigOutput(): string
    {
        return implode("\n", [
            'ipv6 dhcp pool LAN6',
            ' address prefix 2001:DB8::/64',
            '!',
        ]);
    }

    /**
     * @return array<string, string>
     */
    private function defaultCommandOutputs(bool $ipv6 = true): array
    {
        $outputs = [
            'show ip dhcp binding' => $this->ipv4BindingOutput(),
            'show ip dhcp pool' => $this->ipv4PoolStatsOutput(),
            'show running-config | section ip dhcp' => $this->ipv4PoolConfigOutput(),
        ];

        if ($ipv6) {
            $outputs['show ipv6 dhcp binding'] = $this->ipv6BindingOutput();
            $outputs['show ipv6 dhcp pool'] = $this->ipv6PoolStatsOutput();
            $outputs['show running-config | section ipv6 dhcp pool'] = $this->ipv6PoolConfigOutput();
        }

        return $outputs;
    }

    private function createService(string $poolSize = '0', bool $ipv6Enabled = true): CiscoDhcpService
    {
        return new CiscoDhcpService($this->transport, $this->parser, $poolSize, $ipv6Enabled);
    }

    private function expectTransportCall(bool $ipv6 = true, array $outputs = []): void
    {
        if ($outputs === []) {
            $outputs = $this->defaultCommandOutputs($ipv6);
        }

        $this->transport
            ->shouldReceive('executeMultiple')
            ->once()
            ->andReturn($outputs);

        $this->transport
            ->shouldReceive('disconnect')
            ->once();
    }

    // -------------------------------------------------------------------------
    // getLeases() — IPv4
    // -------------------------------------------------------------------------

    public function test_get_leases_returns_ipv4_leases(): void
    {
        $this->expectTransportCall();

        $service = $this->createService();
        $leases = $service->getLeases();

        $this->assertCount(3, $leases); // 2 IPv4 + 1 IPv6
        $ipv4Leases = $leases->filter(fn (DhcpLease $l): bool => str_contains($l->ip, '.'));

        $this->assertCount(2, $ipv4Leases);

        $first = $ipv4Leases->first();
        $this->assertInstanceOf(DhcpLease::class, $first);
        $this->assertSame('10.0.0.50', $first->ip);
        $this->assertSame('00:11:22:33:44:55', $first->mac);
        $this->assertSame('', $first->hostname);
        $this->assertSame('Jun 08 2026 12:00 AM', $first->expires);
    }

    // -------------------------------------------------------------------------
    // getLeases() — IPv6 included when enabled
    // -------------------------------------------------------------------------

    public function test_get_leases_includes_ipv6_when_enabled(): void
    {
        $this->expectTransportCall(ipv6: true);

        $service = $this->createService(ipv6Enabled: true);
        $leases = $service->getLeases();

        $ipv6Leases = $leases->filter(fn (DhcpLease $l): bool => str_contains($l->ip, ':'));

        $this->assertCount(1, $ipv6Leases);
        $this->assertSame('2001:DB8::100', $ipv6Leases->first()->ip);
        $this->assertNull($ipv6Leases->first()->mac === 'AA:BB:CC:DD:EE:FF' ? null : false,
            'MAC should match DUID-derived value');
        $this->assertSame('AA:BB:CC:DD:EE:FF', $ipv6Leases->first()->mac);
    }

    // -------------------------------------------------------------------------
    // getLeases() — IPv6 excluded when disabled
    // -------------------------------------------------------------------------

    public function test_get_leases_excludes_ipv6_when_disabled(): void
    {
        $this->expectTransportCall(ipv6: false);

        $service = $this->createService(ipv6Enabled: false);
        $leases = $service->getLeases();

        $this->assertCount(2, $leases);
        $leases->each(function (DhcpLease $l): void {
            $this->assertStringContainsString('.', $l->ip);
        });
    }

    // -------------------------------------------------------------------------
    // getRanges() — effective ranges from pool config
    // -------------------------------------------------------------------------

    public function test_get_ranges_returns_dhcp_range_vos(): void
    {
        $this->expectTransportCall();

        $service = $this->createService();
        $ranges = $service->getRanges();

        $this->assertNotEmpty($ranges);
        $first = $ranges->first();
        $this->assertInstanceOf(DhcpRange::class, $first);
        $this->assertSame('ipv4', $first->type);
        $this->assertSame('LAN', $first->interface);
        $this->assertSame('10.0.0.0/24', $first->subnet);
        $this->assertSame('10.0.0.10', $first->rangeFrom);
        $this->assertSame('10.0.0.254', $first->rangeTo);
        $this->assertSame('10.0.0.1', $first->gateway);
    }

    // -------------------------------------------------------------------------
    // getPoolStatus() — aggregated from pool stats
    // -------------------------------------------------------------------------

    public function test_get_pool_status_aggregates_from_pool_stats(): void
    {
        $this->expectTransportCall();

        $service = $this->createService(poolSize: '0');
        $status = $service->getPoolStatus();

        $this->assertInstanceOf(DhcpPoolStatus::class, $status);
        // Pool stats says total=254, leased=2
        $this->assertSame(254, $status->total);
        $this->assertSame(2, $status->used);
        $this->assertSame(252, $status->available);
        $this->assertEqualsWithDelta(round(2 / 254, 4), $status->utilisation, 0.0001);
    }

    // -------------------------------------------------------------------------
    // getPoolStatus() — uses poolSize override
    // -------------------------------------------------------------------------

    public function test_get_pool_status_uses_pool_size_override(): void
    {
        $this->expectTransportCall();

        $service = $this->createService(poolSize: '500');
        $status = $service->getPoolStatus();

        $this->assertSame(500, $status->total);
        $this->assertSame(2, $status->used);
        $this->assertSame(498, $status->available);
        $this->assertEqualsWithDelta(round(2 / 500, 4), $status->utilisation, 0.0001);
    }

    // -------------------------------------------------------------------------
    // getLease() — found
    // -------------------------------------------------------------------------

    public function test_get_lease_returns_matching_lease(): void
    {
        $this->expectTransportCall();

        $service = $this->createService();
        $lease = $service->getLease('10.0.0.50');

        $this->assertInstanceOf(DhcpLease::class, $lease);
        $this->assertSame('10.0.0.50', $lease->ip);
    }

    // -------------------------------------------------------------------------
    // getLease() — not found
    // -------------------------------------------------------------------------

    public function test_get_lease_returns_null_when_not_found(): void
    {
        $this->expectTransportCall();

        $service = $this->createService();
        $result = $service->getLease('10.0.0.99');

        $this->assertNull($result);
    }

    // -------------------------------------------------------------------------
    // resetSnapshot() — clears cached data and re-fetches
    // -------------------------------------------------------------------------

    public function test_reset_snapshot_clears_cache_and_re_fetches(): void
    {
        // Transport should be called twice: once for first fetch, once after reset
        $this->transport
            ->shouldReceive('executeMultiple')
            ->twice()
            ->andReturn($this->defaultCommandOutputs());

        $this->transport
            ->shouldReceive('disconnect')
            ->twice();

        $service = $this->createService();

        // First call fetches
        $service->getLeases();

        // Reset clears cache
        $service->resetSnapshot();

        // Second call fetches again
        $service->getLeases();
    }

    // -------------------------------------------------------------------------
    // IPv6 failure doesn't block IPv4
    // -------------------------------------------------------------------------

    public function test_ipv6_failure_does_not_block_ipv4_data(): void
    {
        $outputs = [
            'show ip dhcp binding' => $this->ipv4BindingOutput(),
            'show ip dhcp pool' => $this->ipv4PoolStatsOutput(),
            'show running-config | section ip dhcp' => $this->ipv4PoolConfigOutput(),
            // IPv6 commands return error output
            'show ipv6 dhcp binding' => '% Invalid input detected',
            'show ipv6 dhcp pool' => '% Invalid input detected',
            'show running-config | section ipv6 dhcp pool' => '% Invalid input detected',
        ];

        $this->transport
            ->shouldReceive('executeMultiple')
            ->once()
            ->andReturn($outputs);

        $this->transport
            ->shouldReceive('disconnect')
            ->once();

        $service = $this->createService(ipv6Enabled: true);

        // IPv4 leases still available
        $leases = $service->getLeases();
        $this->assertCount(2, $leases);

        // IPv6 fetch status is false
        $status = $service->getFetchStatus();
        $this->assertTrue($status['ipv4']);
        $this->assertFalse($status['ipv6']);
    }

    // -------------------------------------------------------------------------
    // getFetchStatus()
    // -------------------------------------------------------------------------

    public function test_get_fetch_status_returns_per_family_success(): void
    {
        $this->expectTransportCall();

        $service = $this->createService();
        $service->getLeases(); // trigger snapshot

        $status = $service->getFetchStatus();

        $this->assertArrayHasKey('ipv4', $status);
        $this->assertArrayHasKey('ipv6', $status);
        $this->assertTrue($status['ipv4']);
        $this->assertTrue($status['ipv6']);
    }

    // -------------------------------------------------------------------------
    // Transport disconnect() called on exception
    // -------------------------------------------------------------------------

    public function test_transport_disconnect_called_on_exception(): void
    {
        $this->transport
            ->shouldReceive('executeMultiple')
            ->once()
            ->andThrow(new RuntimeException('connection failed'));

        $this->transport
            ->shouldReceive('disconnect')
            ->once();

        $service = $this->createService();

        $this->expectException(RuntimeException::class);
        $service->getLeases();
    }

    // -------------------------------------------------------------------------
    // Empty output — no bindings → empty collection
    // -------------------------------------------------------------------------

    public function test_empty_output_returns_empty_collection(): void
    {
        $outputs = [
            'show ip dhcp binding' => '',
            'show ip dhcp pool' => '',
            'show running-config | section ip dhcp' => '',
            'show ipv6 dhcp binding' => '',
            'show ipv6 dhcp pool' => '',
            'show running-config | section ipv6 dhcp pool' => '',
        ];

        $this->transport
            ->shouldReceive('executeMultiple')
            ->once()
            ->andReturn($outputs);

        $this->transport
            ->shouldReceive('disconnect')
            ->once();

        $service = $this->createService();
        $leases = $service->getLeases();

        $this->assertCount(0, $leases);
    }

    // -------------------------------------------------------------------------
    // Snapshot is lazily fetched only once across multiple getter calls
    // -------------------------------------------------------------------------

    public function test_snapshot_is_fetched_only_once_across_multiple_getters(): void
    {
        $this->expectTransportCall(); // exactly once

        $service = $this->createService();
        $service->getLeases();
        $service->getRanges();
        $service->getPoolStatus();
        $service->getLease('10.0.0.50');
    }

    // -------------------------------------------------------------------------
    // IPv6 exception (not just error output) is caught and status set to false
    // -------------------------------------------------------------------------

    public function test_ipv6_exception_sets_status_to_false_and_does_not_block_ipv4(): void
    {
        // Use a mock parser to throw on parseDhcpv6BindingTable
        $parser = Mockery::mock(IosOutputParser::class)->makePartial();
        $parser->shouldReceive('isErrorOutput')->andReturn(false);
        $parser->shouldReceive('parseDhcpv6BindingTable')->andThrow(new RuntimeException('ipv6 parse error'));

        $outputs = [
            'show ip dhcp binding' => $this->ipv4BindingOutput(),
            'show ip dhcp pool' => $this->ipv4PoolStatsOutput(),
            'show running-config | section ip dhcp' => $this->ipv4PoolConfigOutput(),
            'show ipv6 dhcp binding' => $this->ipv6BindingOutput(),
            'show ipv6 dhcp pool' => $this->ipv6PoolStatsOutput(),
            'show running-config | section ipv6 dhcp pool' => $this->ipv6PoolConfigOutput(),
        ];

        $this->transport
            ->shouldReceive('executeMultiple')
            ->once()
            ->andReturn($outputs);

        $this->transport
            ->shouldReceive('disconnect')
            ->once();

        $service = new CiscoDhcpService($this->transport, $parser, '0', true);

        $leases = $service->getLeases();

        // IPv4 leases still returned
        $this->assertCount(2, $leases);

        $status = $service->getFetchStatus();
        $this->assertTrue($status['ipv4']);
        $this->assertFalse($status['ipv6']);
    }
}
