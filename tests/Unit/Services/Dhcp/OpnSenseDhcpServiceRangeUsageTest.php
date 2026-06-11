<?php

declare(strict_types=1);

namespace Tests\Unit\Services\Dhcp;

use App\Services\OpnSense\OpnSenseDhcpService;
use GuzzleHttp\Client;
use GuzzleHttp\Handler\MockHandler;
use GuzzleHttp\HandlerStack;
use GuzzleHttp\Psr7\Response;
use Tests\TestCase;

class OpnSenseDhcpServiceRangeUsageTest extends TestCase
{
    /** @var array<string, string> */
    private const DEFAULT_RANGE_MAP = [
        'interface' => 'interface',
        'subnet' => 'subnet',
        'range_from' => 'range_from',
        'range_to' => 'range_to',
        'gateway' => 'gateway',
        'description' => 'description',
        'prefix' => 'prefix',
    ];

    /**
     * @param  list<Response>  $responses
     * @param  array<string, string>  $rangeFieldMap
     */
    private function createService(
        array $responses,
        string $leasesPath = '/api/dhcpv4/leases/search_lease',
        string $ipv4RangesPath = '',
        string $ipv6RangesPath = '',
        array $rangeFieldMap = self::DEFAULT_RANGE_MAP,
    ): OpnSenseDhcpService {
        $mock = new MockHandler($responses);
        $handler = HandlerStack::create($mock);
        $client = new Client(['handler' => $handler]);

        return new OpnSenseDhcpService(
            client: $client,
            poolSize: 254,
            leasesPath: $leasesPath,
            ipv4RangesPath: $ipv4RangesPath,
            ipv6RangesPath: $ipv6RangesPath,
            rangeFieldMap: $rangeFieldMap,
        );
    }

    public function test_ipv4_range_calculates_total_addresses(): void
    {
        $service = $this->createService(
            responses: [
                new Response(200, [], (string) json_encode([
                    'rows' => [
                        ['interface' => 'lan', 'range_from' => '10.0.0.100', 'range_to' => '10.0.0.200', 'subnet' => '10.0.0.0/24'],
                    ],
                ])),
                new Response(200, [], (string) json_encode(['rows' => []])),
            ],
            ipv4RangesPath: '/api/kea/dhcpv4/search_subnet',
        );

        $ranges = $service->getRanges();

        $this->assertCount(1, $ranges);
        // Totals are exact decimal numeric strings on the DhcpRange VO
        $this->assertSame('101', $ranges->first()->totalAddresses);
    }

    public function test_ipv4_range_counts_leases_within_range(): void
    {
        $service = $this->createService(
            responses: [
                new Response(200, [], (string) json_encode([
                    'rows' => [
                        ['interface' => 'lan', 'range_from' => '10.0.0.100', 'range_to' => '10.0.0.200', 'subnet' => '10.0.0.0/24'],
                    ],
                ])),
                new Response(200, [], (string) json_encode([
                    'rows' => [
                        ['address' => '10.0.0.100', 'mac' => 'aa:bb:cc:00:00:01', 'hostname' => 'h1', 'ends' => '2026-01-01', 'status' => 'active'],
                        ['address' => '10.0.0.150', 'mac' => 'aa:bb:cc:00:00:02', 'hostname' => 'h2', 'ends' => '2026-01-01', 'status' => 'active'],
                        ['address' => '10.0.0.200', 'mac' => 'aa:bb:cc:00:00:03', 'hostname' => 'h3', 'ends' => '2026-01-01', 'status' => 'active'],
                        ['address' => '10.0.0.50', 'mac' => 'aa:bb:cc:00:00:04', 'hostname' => 'h4', 'ends' => '2026-01-01', 'status' => 'active'],
                    ],
                ])),
            ],
            ipv4RangesPath: '/api/kea/dhcpv4/search_subnet',
        );

        $ranges = $service->getRanges();

        $this->assertEquals(3, $ranges->first()->usedAddresses);
    }

    public function test_ipv4_range_calculates_utilisation(): void
    {
        $service = $this->createService(
            responses: [
                new Response(200, [], (string) json_encode([
                    'rows' => [
                        ['interface' => 'lan', 'range_from' => '10.0.0.100', 'range_to' => '10.0.0.109', 'subnet' => '10.0.0.0/24'],
                    ],
                ])),
                new Response(200, [], (string) json_encode([
                    'rows' => [
                        ['address' => '10.0.0.100', 'mac' => 'aa:bb:cc:00:00:01', 'hostname' => 'h1', 'ends' => '2026-01-01', 'status' => 'active'],
                        ['address' => '10.0.0.105', 'mac' => 'aa:bb:cc:00:00:02', 'hostname' => 'h2', 'ends' => '2026-01-01', 'status' => 'active'],
                        ['address' => '10.0.0.109', 'mac' => 'aa:bb:cc:00:00:03', 'hostname' => 'h3', 'ends' => '2026-01-01', 'status' => 'active'],
                    ],
                ])),
            ],
            ipv4RangesPath: '/api/kea/dhcpv4/search_subnet',
        );

        $ranges = $service->getRanges();

        $this->assertSame('10', $ranges->first()->totalAddresses);
        $this->assertEquals(3, $ranges->first()->usedAddresses);
        $this->assertEqualsWithDelta(0.3, $ranges->first()->utilisation, 0.001);
    }

    public function test_range_without_from_to_has_null_usage(): void
    {
        $service = $this->createService(
            responses: [
                new Response(200, [], (string) json_encode([
                    'rows' => [
                        ['interface' => 'lan', 'prefix' => 'fd00::/64', 'description' => 'LAN IPv6'],
                    ],
                ])),
                new Response(200, [], (string) json_encode(['rows' => []])),
            ],
            ipv6RangesPath: '/api/kea/dhcpv6/search_subnet',
        );

        $ranges = $service->getRanges();

        $this->assertCount(1, $ranges);
        $this->assertNull($ranges->first()->totalAddresses);
        $this->assertNull($ranges->first()->usedAddresses);
        $this->assertNull($ranges->first()->utilisation);
    }

    public function test_multiple_ranges_get_independent_usage(): void
    {
        $service = $this->createService(
            responses: [
                new Response(200, [], (string) json_encode([
                    'rows' => [
                        ['interface' => 'lan', 'range_from' => '10.0.0.100', 'range_to' => '10.0.0.200', 'subnet' => '10.0.0.0/24'],
                        ['interface' => 'guest', 'range_from' => '192.168.1.10', 'range_to' => '192.168.1.50', 'subnet' => '192.168.1.0/24'],
                    ],
                ])),
                new Response(200, [], (string) json_encode([
                    'rows' => [
                        ['address' => '10.0.0.100', 'mac' => 'aa:bb:cc:00:00:01', 'hostname' => 'h1', 'ends' => '2026-01-01', 'status' => 'active'],
                        ['address' => '10.0.0.150', 'mac' => 'aa:bb:cc:00:00:02', 'hostname' => 'h2', 'ends' => '2026-01-01', 'status' => 'active'],
                        ['address' => '192.168.1.20', 'mac' => 'aa:bb:cc:00:00:03', 'hostname' => 'h3', 'ends' => '2026-01-01', 'status' => 'active'],
                    ],
                ])),
            ],
            ipv4RangesPath: '/api/kea/dhcpv4/search_subnet',
        );

        $ranges = $service->getRanges();

        $this->assertCount(2, $ranges);

        $lanRange = $ranges->first();
        $this->assertSame('101', $lanRange->totalAddresses);
        $this->assertEquals(2, $lanRange->usedAddresses);

        $guestRange = $ranges->last();
        $this->assertSame('41', $guestRange->totalAddresses);
        $this->assertEquals(1, $guestRange->usedAddresses);
    }

    public function test_range_with_zero_leases_has_zero_usage(): void
    {
        $service = $this->createService(
            responses: [
                new Response(200, [], (string) json_encode([
                    'rows' => [
                        ['interface' => 'lan', 'range_from' => '10.0.0.100', 'range_to' => '10.0.0.200', 'subnet' => '10.0.0.0/24'],
                    ],
                ])),
                new Response(200, [], (string) json_encode(['rows' => []])),
            ],
            ipv4RangesPath: '/api/kea/dhcpv4/search_subnet',
        );

        $ranges = $service->getRanges();

        $this->assertSame('101', $ranges->first()->totalAddresses);
        $this->assertEquals(0, $ranges->first()->usedAddresses);
        $this->assertEquals(0.0, $ranges->first()->utilisation);
    }

    public function test_detects_ipv4_type_from_range_address(): void
    {
        $service = $this->createService(
            responses: [
                new Response(200, [], (string) json_encode([
                    'rows' => [
                        ['interface' => 'lan', 'range_from' => '10.0.0.100', 'range_to' => '10.0.0.200', 'subnet' => '10.0.0.0/24'],
                    ],
                ])),
                new Response(200, [], (string) json_encode(['rows' => []])),
            ],
            ipv4RangesPath: '/api/kea/dhcpv4/search_subnet',
        );

        $ranges = $service->getRanges();
        $this->assertEquals('ipv4', $ranges->first()->type);
    }

    public function test_detects_ipv6_type_from_subnet_containing_colon(): void
    {
        $service = $this->createService(
            responses: [
                new Response(200, [], (string) json_encode([
                    'rows' => [
                        ['interface' => 'lan', 'subnet' => 'fd00::/64', 'prefix' => 'fd00::/64', 'description' => 'LAN IPv6'],
                    ],
                ])),
                new Response(200, [], (string) json_encode(['rows' => []])),
            ],
            ipv4RangesPath: '/api/kea/dhcpv4/search_subnet',
        );

        $ranges = $service->getRanges();
        $this->assertEquals('ipv6', $ranges->first()->type);
    }

    public function test_detects_ipv6_type_from_range_from_containing_colon(): void
    {
        $service = $this->createService(
            responses: [
                new Response(200, [], (string) json_encode([
                    'rows' => [
                        ['interface' => 'lan', 'range_from' => 'fd00::100', 'range_to' => 'fd00::200', 'description' => 'LAN IPv6'],
                    ],
                ])),
                new Response(200, [], (string) json_encode(['rows' => []])),
            ],
            ipv6RangesPath: '/api/kea/dhcpv6/search_subnet',
        );

        $ranges = $service->getRanges();
        $this->assertEquals('ipv6', $ranges->first()->type);
    }

    public function test_ipv6_ranges_from_ipv4_endpoint_detected_correctly(): void
    {
        $service = $this->createService(
            responses: [
                new Response(200, [], (string) json_encode([
                    'rows' => [
                        ['interface' => 'lan', 'range_from' => '10.0.0.100', 'range_to' => '10.0.0.200', 'subnet' => '10.0.0.0/24'],
                        ['interface' => 'lan', 'subnet' => 'fd00::/64', 'prefix' => 'fd00::/64'],
                    ],
                ])),
                new Response(200, [], (string) json_encode(['rows' => []])),
            ],
            ipv4RangesPath: '/api/kea/dhcpv4/search_subnet',
        );

        $ranges = $service->getRanges();

        $this->assertCount(2, $ranges);
        $this->assertEquals('ipv4', $ranges[0]->type);
        $this->assertEquals('ipv6', $ranges[1]->type);
    }

    public function test_dnsmasq_ranges_calculate_usage_with_mapped_fields(): void
    {
        $dnsmasqRangeMap = [
            'interface' => 'interface',
            'subnet' => 'subnet',
            'range_from' => 'from',
            'range_to' => 'to',
            'gateway' => 'gateway',
            'description' => 'domain',
            'prefix' => 'prefix',
        ];

        $service = $this->createService(
            responses: [
                new Response(200, [], (string) json_encode([
                    'rows' => [
                        [
                            'interface' => 'lan',
                            'from' => '10.0.0.100',
                            'to' => '10.0.0.200',
                            'domain' => 'lan.local',
                        ],
                    ],
                ])),
                new Response(200, [], (string) json_encode([
                    'rows' => [
                        ['address' => '10.0.0.100', 'mac' => 'aa:bb:cc:00:00:01', 'hostname' => 'h1', 'ends' => '2026-01-01', 'status' => 'active'],
                        ['address' => '10.0.0.150', 'mac' => 'aa:bb:cc:00:00:02', 'hostname' => 'h2', 'ends' => '2026-01-01', 'status' => 'active'],
                    ],
                ])),
            ],
            ipv4RangesPath: '/api/dnsmasq/settings/search_range',
            rangeFieldMap: $dnsmasqRangeMap,
        );

        $ranges = $service->getRanges();

        $this->assertCount(1, $ranges);
        $this->assertSame('101', $ranges->first()->totalAddresses);
        $this->assertEquals(2, $ranges->first()->usedAddresses);
        $this->assertEqualsWithDelta(2 / 101, $ranges->first()->utilisation, 0.001);
    }

    public function test_no_leases_fetched_when_no_ranges_configured(): void
    {
        $service = $this->createService(
            responses: [],
            ipv4RangesPath: '',
            ipv6RangesPath: '',
        );

        $ranges = $service->getRanges();

        $this->assertCount(0, $ranges);
    }

    public function test_leases_outside_all_ranges_not_counted(): void
    {
        $service = $this->createService(
            responses: [
                new Response(200, [], (string) json_encode([
                    'rows' => [
                        ['interface' => 'lan', 'range_from' => '10.0.0.100', 'range_to' => '10.0.0.110', 'subnet' => '10.0.0.0/24'],
                    ],
                ])),
                new Response(200, [], (string) json_encode([
                    'rows' => [
                        ['address' => '10.0.0.50', 'mac' => 'aa:bb:cc:00:00:01', 'hostname' => 'h1', 'ends' => '2026-01-01', 'status' => 'active'],
                        ['address' => '10.0.0.250', 'mac' => 'aa:bb:cc:00:00:02', 'hostname' => 'h2', 'ends' => '2026-01-01', 'status' => 'active'],
                    ],
                ])),
            ],
            ipv4RangesPath: '/api/kea/dhcpv4/search_subnet',
        );

        $ranges = $service->getRanges();

        $this->assertEquals(0, $ranges->first()->usedAddresses);
    }

    public function test_single_address_range_calculates_correctly(): void
    {
        $service = $this->createService(
            responses: [
                new Response(200, [], (string) json_encode([
                    'rows' => [
                        ['interface' => 'lan', 'range_from' => '10.0.0.100', 'range_to' => '10.0.0.100', 'subnet' => '10.0.0.0/24'],
                    ],
                ])),
                new Response(200, [], (string) json_encode([
                    'rows' => [
                        ['address' => '10.0.0.100', 'mac' => 'aa:bb:cc:00:00:01', 'hostname' => 'h1', 'ends' => '2026-01-01', 'status' => 'active'],
                    ],
                ])),
            ],
            ipv4RangesPath: '/api/kea/dhcpv4/search_subnet',
        );

        $ranges = $service->getRanges();

        $this->assertSame('1', $ranges->first()->totalAddresses);
        $this->assertEquals(1, $ranges->first()->usedAddresses);
        $this->assertEquals(1.0, $ranges->first()->utilisation);
    }

    public function test_ipv6_range_with_from_to_calculates_usage(): void
    {
        $service = $this->createService(
            responses: [
                new Response(200, [], (string) json_encode([
                    'rows' => [
                        ['interface' => 'lan', 'range_from' => 'fd00::100', 'range_to' => 'fd00::110', 'subnet' => 'fd00::/64'],
                    ],
                ])),
                new Response(200, [], (string) json_encode([
                    'rows' => [
                        ['address' => 'fd00::105', 'mac' => 'aa:bb:cc:00:00:01', 'hostname' => 'h1', 'ends' => '2026-01-01', 'status' => 'active'],
                        ['address' => 'fd00::108', 'mac' => 'aa:bb:cc:00:00:02', 'hostname' => 'h2', 'ends' => '2026-01-01', 'status' => 'active'],
                        ['address' => 'fd00::200', 'mac' => 'aa:bb:cc:00:00:03', 'hostname' => 'h3', 'ends' => '2026-01-01', 'status' => 'active'],
                    ],
                ])),
            ],
            ipv6RangesPath: '/api/kea/dhcpv6/search_subnet',
        );

        $ranges = $service->getRanges();

        $this->assertEquals('ipv6', $ranges->first()->type);
        $this->assertSame('17', $ranges->first()->totalAddresses);
        $this->assertEquals(2, $ranges->first()->usedAddresses);
    }
}
