<?php

declare(strict_types=1);

namespace Tests\Unit\Services\Dhcp;

use App\Enums\AddressFamily;
use App\Services\OpnSense\OpnSenseClient;
use App\Services\OpnSense\OpnSenseDhcpService;
use GuzzleHttp\Promise\PromiseInterface;
use Illuminate\Http\Client\ConnectionException;
use Tests\Support\DhcpFetchStatusArray;
use Tests\Support\Fake;
use Tests\TestCase;
use Throwable;

class OpnSenseDhcpServiceTest extends TestCase
{
    /**
     * @param  array<int, PromiseInterface|Throwable>  $responses
     */
    private function createServiceWithMock(array $responses, int $poolSize = 0): OpnSenseDhcpService
    {
        Fake::sequence($responses);

        return new OpnSenseDhcpService(OpnSenseClient::fromConfig(['endpoint' => 'http://opnsense.test', 'key' => 'key', 'secret' => 'secret'])->request(), $poolSize);
    }

    public function test_get_leases_returns_collection(): void
    {
        $service = $this->createServiceWithMock([
            Fake::response(200, [], (string) json_encode([
                'rows' => [
                    ['address' => '10.0.0.10', 'mac' => 'aa:bb:cc:dd:ee:ff', 'hostname' => 'device1', 'ends' => '2026-04-15 12:00:00', 'status' => 'active'],
                    ['address' => '10.0.0.11', 'mac' => '11:22:33:44:55:66', 'hostname' => 'device2', 'ends' => '2026-04-15 13:00:00', 'status' => 'active'],
                ],
                'rowCount' => 2,
                'total' => 2,
                'current' => 1,
            ])),
        ]);

        $leases = $service->snapshot()->leases;

        $this->assertCount(2, $leases);
        $this->assertEquals('10.0.0.10', $leases[0]->ip);
        $this->assertEquals('aa:bb:cc:dd:ee:ff', $leases[0]->mac);
        $this->assertEquals('device1', $leases[0]->hostname);
        $this->assertEquals('2026-04-15 12:00:00', $leases[0]->expires);
    }

    public function test_get_leases_returns_empty_collection(): void
    {
        $service = $this->createServiceWithMock([
            Fake::response(200, [], (string) json_encode([
                'rows' => [],
                'rowCount' => 0,
                'total' => 0,
                'current' => 1,
            ])),
        ]);

        $leases = $service->snapshot()->leases;
        $this->assertCount(0, $leases);
    }

    public function test_get_pool_status_returns_stats(): void
    {
        $service = $this->createServiceWithMock([
            Fake::response(200, [], (string) json_encode([
                'rows' => [
                    ['address' => '10.0.0.10', 'mac' => 'aa:bb:cc:dd:ee:ff', 'hostname' => 'a', 'ends' => '2026-04-15 12:00:00', 'status' => 'active'],
                    ['address' => '10.0.0.11', 'mac' => '11:22:33:44:55:66', 'hostname' => 'b', 'ends' => '2026-04-15 13:00:00', 'status' => 'active'],
                    ['address' => '10.0.0.12', 'mac' => '77:88:99:aa:bb:cc', 'hostname' => 'c', 'ends' => '2026-04-14 12:00:00', 'status' => 'expired'],
                ],
                'rowCount' => 3,
                'total' => 3,
                'current' => 1,
            ])),
        ], 254);

        $pool = $service->snapshot()->poolStatus(AddressFamily::IPv4);

        $this->assertEquals(254, $pool->total);
        $this->assertEquals(2, $pool->used);
        $this->assertEquals(252, $pool->available);
        $this->assertEqualsWithDelta(2 / 254, $pool->utilisation, 0.001);
    }

    public function test_get_pool_status_handles_zero_pool_size(): void
    {
        $service = $this->createServiceWithMock([
            Fake::response(200, [], (string) json_encode([
                'rows' => [],
                'rowCount' => 0,
                'total' => 0,
                'current' => 1,
            ])),
        ]);

        config(['aperture.dhcp.pool_size' => 0]);

        $pool = $service->snapshot()->poolStatus(AddressFamily::IPv4);

        $this->assertEquals(0, $pool->total);
        $this->assertEquals(0, $pool->used);
        $this->assertEquals(0, $pool->available);
        $this->assertEquals(0.0, $pool->utilisation);
    }

    public function test_get_pool_status_ipv6_returns_zeroed_status(): void
    {
        $service = $this->createServiceWithMock([], 254);

        $pool = $service->snapshot()->poolStatus(AddressFamily::IPv6);

        $this->assertEquals(0, $pool->total);
        $this->assertEquals(0, $pool->used);
        $this->assertEquals(0, $pool->available);
        $this->assertEquals(0.0, $pool->utilisation);
    }

    public function test_get_lease_returns_matching_lease(): void
    {
        $service = $this->createServiceWithMock([
            Fake::response(200, [], (string) json_encode([
                'rows' => [
                    ['address' => '10.0.0.10', 'mac' => 'aa:bb:cc:dd:ee:ff', 'hostname' => 'device1', 'ends' => '2026-04-15 12:00:00', 'status' => 'active'],
                ],
                'rowCount' => 1,
                'total' => 1,
                'current' => 1,
            ])),
        ]);

        $lease = $service->getLease('10.0.0.10');

        $this->assertNotNull($lease);
        $this->assertEquals('10.0.0.10', $lease->ip);
        $this->assertEquals('aa:bb:cc:dd:ee:ff', $lease->mac);
    }

    public function test_get_lease_returns_null_when_not_found(): void
    {
        $service = $this->createServiceWithMock([
            Fake::response(200, [], (string) json_encode([
                'rows' => [],
                'rowCount' => 0,
                'total' => 0,
                'current' => 1,
            ])),
        ]);

        $lease = $service->getLease('10.0.0.99');
        $this->assertNull($lease);
    }

    public function test_get_lease_matches_ipv6_address_case_insensitively(): void
    {
        $service = $this->createServiceWithMock([
            Fake::response(200, [], (string) json_encode([
                'rows' => [
                    ['address' => '2001:DB8::100', 'mac' => 'aa:bb:cc:dd:ee:ff', 'hostname' => 'v6device', 'ends' => '2026-04-15 12:00:00', 'status' => 'active'],
                ],
                'rowCount' => 1,
                'total' => 1,
                'current' => 1,
            ])),
        ]);

        $lease = $service->getLease('2001:db8::100');

        $this->assertNotNull($lease);
        $this->assertSame('2001:db8::100', $lease->ip);
        $this->assertSame('aa:bb:cc:dd:ee:ff', $lease->mac);
    }

    public function test_fetch_leases_uses_post_when_leases_use_post_is_true(): void
    {
        Fake::sequence([
            Fake::response(200, [], (string) json_encode([
                'rows' => [
                    ['address' => '10.0.0.5', 'mac' => 'aa:bb:cc:dd:ee:01', 'hostname' => 'dev1', 'ends' => '2026-04-15 12:00:00', 'status' => 'active'],
                ],
            ])),
        ]);

        $service = new OpnSenseDhcpService(
            client: OpnSenseClient::fromConfig(['endpoint' => 'http://opnsense.test', 'key' => 'key', 'secret' => 'secret'])->request(),
            poolSize: 10,
            leasesPath: '/api/dhcpv4/leases/search_lease',
            leasesUsePost: true,
        );

        $leases = $service->snapshot()->leases;
        $this->assertCount(1, $leases);
        $this->assertEquals('10.0.0.5', $leases[0]->ip);

        $lastRequest = Fake::requests()[0] ?? null;
        $this->assertNotNull($lastRequest);
        $this->assertEquals('POST', $lastRequest->method());
    }

    public function test_get_ranges_returns_ipv4_ranges_with_usage_stats(): void
    {
        Fake::sequence([
            Fake::response(200, [], (string) json_encode([
                'rows' => [
                    [
                        'interface' => 'em0',
                        'subnet' => '10.0.0.0/24',
                        'range_from' => '10.0.0.1',
                        'range_to' => '10.0.0.254',
                        'gateway' => '10.0.0.1',
                        'description' => 'Main LAN',
                        'prefix' => '',
                    ],
                ],
            ])),
            Fake::response(200, [], (string) json_encode([
                'rows' => [
                    ['address' => '10.0.0.10', 'mac' => 'aa:bb:cc:dd:ee:ff', 'hostname' => 'device1', 'ends' => '2026-04-15 12:00:00', 'status' => 'active'],
                ],
            ])),
        ]);

        $service = new OpnSenseDhcpService(
            client: OpnSenseClient::fromConfig(['endpoint' => 'http://opnsense.test', 'key' => 'key', 'secret' => 'secret'])->request(),
            poolSize: 0,
            ipv4RangesPath: '/api/dhcpv4/ranges',
        );

        $ranges = $service->snapshot()->ranges;
        $this->assertCount(1, $ranges);
        $this->assertEquals(AddressFamily::IPv4, $ranges[0]->type);
        $this->assertEquals('10.0.0.1', $ranges[0]->rangeFrom);
        $this->assertSame('254', $ranges[0]->totalAddresses);
        $this->assertEquals(1, $ranges[0]->usedAddresses);
    }

    public function test_get_ranges_returns_ipv6_ranges_with_usage_stats(): void
    {
        Fake::sequence([
            Fake::response(200, [], (string) json_encode([
                'rows' => [
                    [
                        'interface' => 'em0',
                        'subnet' => 'fd00::/64',
                        'range_from' => 'fd00::1',
                        'range_to' => 'fd00::ff',
                        'gateway' => 'fd00::1',
                        'description' => 'IPv6 LAN',
                        'prefix' => '',
                    ],
                ],
            ])),
            Fake::response(200, [], (string) json_encode([
                'rows' => [
                    ['address' => 'fd00::10', 'mac' => 'aa:bb:cc:dd:ee:ff', 'hostname' => 'ipv6device', 'ends' => '2026-04-15 12:00:00', 'status' => 'active'],
                ],
            ])),
        ]);

        $service = new OpnSenseDhcpService(
            client: OpnSenseClient::fromConfig(['endpoint' => 'http://opnsense.test', 'key' => 'key', 'secret' => 'secret'])->request(),
            poolSize: 0,
            ipv6RangesPath: '/api/dhcpv6/ranges',
        );

        $ranges = $service->snapshot()->ranges;
        $this->assertCount(1, $ranges);
        $this->assertEquals(AddressFamily::IPv6, $ranges[0]->type);
        $this->assertEquals('fd00::1', $ranges[0]->rangeFrom);
        $this->assertGreaterThan(0, $ranges[0]->usedAddresses);
    }

    public function test_get_ranges_returns_empty_collection_when_ranges_path_not_set(): void
    {
        Fake::sequence([]);

        $service = new OpnSenseDhcpService(client: OpnSenseClient::fromConfig(['endpoint' => 'http://opnsense.test', 'key' => 'key', 'secret' => 'secret'])->request(), poolSize: 0);

        $ranges = $service->snapshot()->ranges;
        $this->assertCount(0, $ranges);
    }

    public function test_get_ranges_handles_fetch_exception_gracefully(): void
    {
        Fake::sequence([
            new ConnectionException('Connection refused'),
        ]);

        $service = new OpnSenseDhcpService(
            client: OpnSenseClient::fromConfig(['endpoint' => 'http://opnsense.test', 'key' => 'key', 'secret' => 'secret'])->request(),
            poolSize: 0,
            ipv4RangesPath: '/api/dhcpv4/ranges',
        );

        $ranges = $service->snapshot()->ranges;
        $this->assertCount(0, $ranges);
    }

    public function test_build_range_from_row_uses_kea_pools_format(): void
    {
        Fake::sequence([
            Fake::response(200, [], (string) json_encode([
                'rows' => [
                    [
                        'interface' => 'em0',
                        'subnet' => '10.0.1.0/24',
                        'range_from' => '',
                        'range_to' => '',
                        'gateway' => '10.0.1.1',
                        'description' => 'Kea Pool',
                        'prefix' => '',
                        'pools' => '10.0.1.100 - 10.0.1.200',
                    ],
                ],
            ])),
            Fake::response(200, [], (string) json_encode(['rows' => []])),
        ]);

        $service = new OpnSenseDhcpService(
            client: OpnSenseClient::fromConfig(['endpoint' => 'http://opnsense.test', 'key' => 'key', 'secret' => 'secret'])->request(),
            poolSize: 0,
            ipv4RangesPath: '/api/dhcpv4/ranges',
            rangeFieldMap: [
                'interface' => 'interface',
                'subnet' => 'subnet',
                'range_from' => 'range_from',
                'range_to' => 'range_to',
                'gateway' => 'gateway',
                'description' => 'description',
                'prefix' => 'prefix',
                'pools' => 'pools',
            ],
        );

        $ranges = $service->snapshot()->ranges;
        $this->assertCount(1, $ranges);
        $this->assertEquals('10.0.1.100', $ranges[0]->rangeFrom);
        $this->assertEquals('10.0.1.200', $ranges[0]->rangeTo);
    }

    public function test_build_range_from_row_calculates_subnet_from_subnet_mask(): void
    {
        Fake::sequence([
            Fake::response(200, [], (string) json_encode([
                'rows' => [
                    [
                        'interface' => 'em0',
                        'range_from' => '192.168.1.100',
                        'range_to' => '192.168.1.200',
                        'gateway' => '192.168.1.1',
                        'description' => 'dnsmasq pool',
                        'prefix' => '',
                        'subnet_mask' => '255.255.255.0',
                    ],
                ],
            ])),
            Fake::response(200, [], (string) json_encode(['rows' => []])),
        ]);

        $service = new OpnSenseDhcpService(
            client: OpnSenseClient::fromConfig(['endpoint' => 'http://opnsense.test', 'key' => 'key', 'secret' => 'secret'])->request(),
            poolSize: 0,
            ipv4RangesPath: '/api/dhcpv4/ranges',
            rangeFieldMap: [
                'interface' => 'interface',
                'subnet' => 'subnet',
                'range_from' => 'range_from',
                'range_to' => 'range_to',
                'gateway' => 'gateway',
                'description' => 'description',
                'prefix' => 'prefix',
                'subnet_mask' => 'subnet_mask',
            ],
        );

        $ranges = $service->snapshot()->ranges;
        $this->assertCount(1, $ranges);
        $this->assertNotNull($ranges[0]->subnet);
        $this->assertStringContainsString('192.168.1.0', (string) $ranges[0]->subnet);
    }

    public function test_build_range_from_row_handles_ipv6_prefix_construction(): void
    {
        Fake::sequence([
            Fake::response(200, [], (string) json_encode([
                'rows' => [
                    [
                        'interface' => 'em0',
                        'subnet' => '',
                        'range_from' => 'fd00::1',
                        'range_to' => 'fd00::ff',
                        'gateway' => '',
                        'description' => 'IPv6 prefix',
                        'prefix' => '64',
                    ],
                ],
            ])),
            Fake::response(200, [], (string) json_encode(['rows' => []])),
        ]);

        $service = new OpnSenseDhcpService(
            client: OpnSenseClient::fromConfig(['endpoint' => 'http://opnsense.test', 'key' => 'key', 'secret' => 'secret'])->request(),
            poolSize: 0,
            ipv6RangesPath: '/api/dhcpv6/ranges',
        );

        $ranges = $service->snapshot()->ranges;
        $this->assertCount(1, $ranges);
        $this->assertEquals('fd00::1/64', $ranges[0]->prefix);
    }

    public function test_get_ranges_normalizes_ipv6_prefix_to_lowercase(): void
    {
        Fake::sequence([
            Fake::response(200, [], (string) json_encode([
                'rows' => [
                    [
                        'interface' => 'em0',
                        'subnet' => '',
                        'range_from' => 'FD00:ABCD::1',
                        'range_to' => 'FD00:ABCD::FF',
                        'gateway' => '',
                        'description' => 'IPv6 prefix',
                        'prefix' => '64',
                    ],
                ],
            ])),
            Fake::response(200, [], (string) json_encode(['rows' => []])),
        ]);

        $service = new OpnSenseDhcpService(
            client: OpnSenseClient::fromConfig(['endpoint' => 'http://opnsense.test', 'key' => 'key', 'secret' => 'secret'])->request(),
            poolSize: 0,
            ipv6RangesPath: '/api/dhcpv6/ranges',
        );

        $ranges = $service->snapshot()->ranges;
        $this->assertCount(1, $ranges);
        $this->assertSame('fd00:abcd::1/64', $ranges[0]->prefix);
    }

    public function test_calculate_subnet_returns_null_for_invalid_ip(): void
    {
        Fake::sequence([
            Fake::response(200, [], (string) json_encode([
                'rows' => [
                    [
                        'interface' => 'em0',
                        'range_from' => 'not-an-ip',
                        'range_to' => '192.168.1.200',
                        'gateway' => '',
                        'description' => 'invalid',
                        'prefix' => '',
                        'subnet_mask' => '255.255.255.0',
                    ],
                ],
            ])),
            Fake::response(200, [], (string) json_encode(['rows' => []])),
        ]);

        $service = new OpnSenseDhcpService(
            client: OpnSenseClient::fromConfig(['endpoint' => 'http://opnsense.test', 'key' => 'key', 'secret' => 'secret'])->request(),
            poolSize: 0,
            ipv4RangesPath: '/api/dhcpv4/ranges',
            rangeFieldMap: [
                'interface' => 'interface',
                'subnet' => 'subnet',
                'range_from' => 'range_from',
                'range_to' => 'range_to',
                'gateway' => 'gateway',
                'description' => 'description',
                'prefix' => 'prefix',
                'subnet_mask' => 'subnet_mask',
            ],
        );

        $ranges = $service->snapshot()->ranges;
        $this->assertCount(1, $ranges);
        $this->assertNull($ranges[0]->subnet);
    }

    public function test_enrich_range_returns_unchanged_when_range_from_or_range_to_is_null(): void
    {
        Fake::sequence([
            Fake::response(200, [], (string) json_encode([
                'rows' => [
                    [
                        'interface' => 'em0',
                        'subnet' => '10.0.0.0/24',
                        'gateway' => '',
                        'description' => 'no range',
                        'prefix' => '',
                    ],
                ],
            ])),
            Fake::response(200, [], (string) json_encode([
                'rows' => [
                    ['address' => '10.0.0.10', 'mac' => 'aa:bb', 'hostname' => 'h', 'ends' => '', 'status' => 'active'],
                ],
            ])),
        ]);

        $service = new OpnSenseDhcpService(
            client: OpnSenseClient::fromConfig(['endpoint' => 'http://opnsense.test', 'key' => 'key', 'secret' => 'secret'])->request(),
            poolSize: 0,
            ipv4RangesPath: '/api/dhcpv4/ranges',
        );

        $ranges = $service->snapshot()->ranges;
        $this->assertCount(1, $ranges);
        $this->assertNull($ranges[0]->rangeFrom);
        $this->assertNull($ranges[0]->totalAddresses);
    }

    public function test_enrich_ipv6_range_returns_unchanged_when_range_is_invalid_ipv6(): void
    {
        Fake::sequence([
            Fake::response(200, [], (string) json_encode([
                'rows' => [
                    [
                        'interface' => 'em0',
                        'subnet' => 'invalid:ipv6',
                        'range_from' => 'invalid:ipv6:address',
                        'range_to' => 'invalid:ipv6:address:too',
                        'gateway' => '',
                        'description' => 'invalid ipv6',
                        'prefix' => '',
                    ],
                ],
            ])),
            Fake::response(200, [], (string) json_encode(['rows' => []])),
        ]);

        $service = new OpnSenseDhcpService(
            client: OpnSenseClient::fromConfig(['endpoint' => 'http://opnsense.test', 'key' => 'key', 'secret' => 'secret'])->request(),
            poolSize: 0,
            ipv6RangesPath: '/api/dhcpv6/ranges',
        );

        $ranges = $service->snapshot()->ranges;
        $this->assertCount(1, $ranges);
        $this->assertNull($ranges[0]->totalAddresses);
    }

    public function test_enrich_ipv4_range_returns_unchanged_when_range_from_is_invalid_ip(): void
    {
        Fake::sequence([
            Fake::response(200, [], (string) json_encode([
                'rows' => [
                    [
                        'interface' => 'em0',
                        'subnet' => '10.0.0.0/24',
                        'range_from' => 'invalid-ip',
                        'range_to' => 'also-invalid',
                        'gateway' => '',
                        'description' => 'invalid range',
                        'prefix' => '',
                    ],
                ],
            ])),
            Fake::response(200, [], (string) json_encode(['rows' => []])),
        ]);

        $service = new OpnSenseDhcpService(
            client: OpnSenseClient::fromConfig(['endpoint' => 'http://opnsense.test', 'key' => 'key', 'secret' => 'secret'])->request(),
            poolSize: 0,
            ipv4RangesPath: '/api/dhcpv4/ranges',
        );

        $ranges = $service->snapshot()->ranges;
        $this->assertCount(1, $ranges);
        $this->assertNull($ranges[0]->totalAddresses);
    }

    public function test_both_ipv4_and_ipv6_paths_deduplicated_when_same(): void
    {
        Fake::sequence([
            Fake::response(200, [], (string) json_encode([
                'rows' => [
                    [
                        'interface' => 'em0',
                        'subnet' => '10.0.0.0/24',
                        'range_from' => '10.0.0.1',
                        'range_to' => '10.0.0.254',
                        'gateway' => '10.0.0.1',
                        'description' => 'combined',
                        'prefix' => '',
                    ],
                ],
            ])),
            Fake::response(200, [], (string) json_encode(['rows' => []])),
        ]);

        $service = new OpnSenseDhcpService(
            client: OpnSenseClient::fromConfig(['endpoint' => 'http://opnsense.test', 'key' => 'key', 'secret' => 'secret'])->request(),
            poolSize: 0,
            ipv4RangesPath: '/api/dhcpv4/ranges',
            ipv6RangesPath: '/api/dhcpv4/ranges',
        );

        $ranges = $service->snapshot()->ranges;
        $this->assertCount(1, $ranges);
    }

    public function test_get_fetch_status_reports_success(): void
    {
        $service = $this->createServiceWithMock([Fake::response(200, [], (string) json_encode(['rows' => []]))]);

        $this->assertSame(['ipv4' => true, 'ipv6' => true, 'ipv4_ranges' => true, 'ipv6_ranges' => true], DhcpFetchStatusArray::of($service->snapshot()));
    }
}
