<?php

declare(strict_types=1);

namespace Tests\Unit\Services\Dhcp;

use App\Services\OpnSense\OpnSenseDhcpService;
use GuzzleHttp\Promise\PromiseInterface;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use ReflectionClass;
use Tests\TestCase;

class OpnSenseDhcpServiceTest extends TestCase
{
    /**
     * @param  array<int, PromiseInterface>  $responses
     */
    private function createServiceWithMock(array $responses, int $poolSize = 0): OpnSenseDhcpService
    {
        $mock = Http::sequence($responses);
        Http::fake(['*' => $mock]);
        $client = Http::baseUrl('http://opnsense.local')->throw();

        return new OpnSenseDhcpService($client, $poolSize);
    }

    public function test_get_leases_returns_collection(): void
    {
        $service = $this->createServiceWithMock([
            Http::response([
                'rows' => [
                    ['address' => '10.0.0.10', 'mac' => 'aa:bb:cc:dd:ee:ff', 'hostname' => 'device1', 'ends' => '2026-04-15 12:00:00', 'status' => 'active'],
                    ['address' => '10.0.0.11', 'mac' => '11:22:33:44:55:66', 'hostname' => 'device2', 'ends' => '2026-04-15 13:00:00', 'status' => 'active'],
                ],
                'rowCount' => 2,
                'total' => 2,
                'current' => 1,
            ], 200),
        ]);

        $leases = $service->getLeases();

        $this->assertCount(2, $leases);
        $this->assertEquals('10.0.0.10', $leases[0]->ip);
        $this->assertEquals('aa:bb:cc:dd:ee:ff', $leases[0]->mac);
        $this->assertEquals('device1', $leases[0]->hostname);
        $this->assertEquals('2026-04-15 12:00:00', $leases[0]->expires);
    }

    public function test_get_leases_returns_empty_collection(): void
    {
        $service = $this->createServiceWithMock([
            Http::response([
                'rows' => [],
                'rowCount' => 0,
                'total' => 0,
                'current' => 1,
            ], 200),
        ]);

        $leases = $service->getLeases();
        $this->assertCount(0, $leases);
    }

    public function test_get_pool_status_returns_stats(): void
    {
        $service = $this->createServiceWithMock([
            Http::response([
                'rows' => [
                    ['address' => '10.0.0.10', 'mac' => 'aa:bb:cc:dd:ee:ff', 'hostname' => 'a', 'ends' => '2026-04-15 12:00:00', 'status' => 'active'],
                    ['address' => '10.0.0.11', 'mac' => '11:22:33:44:55:66', 'hostname' => 'b', 'ends' => '2026-04-15 13:00:00', 'status' => 'active'],
                    ['address' => '10.0.0.12', 'mac' => '77:88:99:aa:bb:cc', 'hostname' => 'c', 'ends' => '2026-04-14 12:00:00', 'status' => 'expired'],
                ],
                'rowCount' => 3,
                'total' => 3,
                'current' => 1,
            ], 200),
        ], 254);

        $pool = $service->getPoolStatus();

        $this->assertEquals(254, $pool->total);
        $this->assertEquals(2, $pool->used);
        $this->assertEquals(252, $pool->available);
        $this->assertEqualsWithDelta(2 / 254, $pool->utilisation, 0.001);
    }

    public function test_get_pool_status_handles_zero_pool_size(): void
    {
        $service = $this->createServiceWithMock([
            Http::response([
                'rows' => [],
                'rowCount' => 0,
                'total' => 0,
                'current' => 1,
            ], 200),
        ]);

        config(['aperture.dhcp.pool_size' => 0]);

        $pool = $service->getPoolStatus();

        $this->assertEquals(0, $pool->total);
        $this->assertEquals(0, $pool->used);
        $this->assertEquals(0, $pool->available);
        $this->assertEquals(0.0, $pool->utilisation);
    }

    public function test_get_pool_status_ipv6_returns_zeroed_status(): void
    {
        $service = $this->createServiceWithMock([], 254);

        $pool = $service->getPoolStatus('ipv6');

        $this->assertEquals(0, $pool->total);
        $this->assertEquals(0, $pool->used);
        $this->assertEquals(0, $pool->available);
        $this->assertEquals(0.0, $pool->utilisation);
    }

    public function test_get_lease_returns_matching_lease(): void
    {
        $service = $this->createServiceWithMock([
            Http::response([
                'rows' => [
                    ['address' => '10.0.0.10', 'mac' => 'aa:bb:cc:dd:ee:ff', 'hostname' => 'device1', 'ends' => '2026-04-15 12:00:00', 'status' => 'active'],
                ],
                'rowCount' => 1,
                'total' => 1,
                'current' => 1,
            ], 200),
        ]);

        $lease = $service->getLease('10.0.0.10');

        $this->assertNotNull($lease);
        $this->assertEquals('10.0.0.10', $lease->ip);
        $this->assertEquals('aa:bb:cc:dd:ee:ff', $lease->mac);
    }

    public function test_get_lease_returns_null_when_not_found(): void
    {
        $service = $this->createServiceWithMock([
            Http::response([
                'rows' => [],
                'rowCount' => 0,
                'total' => 0,
                'current' => 1,
            ], 200),
        ]);

        $lease = $service->getLease('10.0.0.99');
        $this->assertNull($lease);
    }

    public function test_get_lease_matches_ipv6_address_case_insensitively(): void
    {
        $service = $this->createServiceWithMock([
            Http::response([
                'rows' => [
                    ['address' => '2001:DB8::100', 'mac' => 'aa:bb:cc:dd:ee:ff', 'hostname' => 'v6device', 'ends' => '2026-04-15 12:00:00', 'status' => 'active'],
                ],
                'rowCount' => 1,
                'total' => 1,
                'current' => 1,
            ], 200),
        ]);

        $lease = $service->getLease('2001:db8::100');

        $this->assertNotNull($lease);
        $this->assertSame('2001:db8::100', $lease->ip);
        $this->assertSame('aa:bb:cc:dd:ee:ff', $lease->mac);
    }

    public function test_fetch_leases_uses_post_when_leases_use_post_is_true(): void
    {
        // Covers lines 338-341: fetchLeases POST branch (leasesUsePost = true)
        $mock = Http::sequence([
            Http::response([
                'rows' => [
                    ['address' => '10.0.0.5', 'mac' => 'aa:bb:cc:dd:ee:01', 'hostname' => 'dev1', 'ends' => '2026-04-15 12:00:00', 'status' => 'active'],
                ],
            ], 200),
        ]);
        Http::fake(['*' => $mock]);
        $client = Http::baseUrl('http://opnsense.local')->throw();

        $service = new OpnSenseDhcpService(
            client: $client,
            poolSize: 10,
            leasesPath: '/api/dhcpv4/leases/search_lease',
            leasesUsePost: true,
        );

        $leases = $service->getLeases();
        $this->assertCount(1, $leases);
        $this->assertEquals('10.0.0.5', $leases[0]->ip);

        Http::assertSent(fn (Request $request): bool => $request->method() === 'POST');
    }

    public function test_get_ranges_returns_ipv4_ranges_with_usage_stats(): void
    {
        // Covers getRanges() with ipv4RangesPath set, buildRangeFromRow(), enrichRangeWithUsage()
        $mock = Http::sequence([
            // First call: IPv4 ranges
            Http::response([
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
            ], 200),
            // Second call: leases (for enrichment)
            Http::response([
                'rows' => [
                    ['address' => '10.0.0.10', 'mac' => 'aa:bb:cc:dd:ee:ff', 'hostname' => 'device1', 'ends' => '2026-04-15 12:00:00', 'status' => 'active'],
                ],
            ], 200),
        ]);
        Http::fake(['*' => $mock]);
        $client = Http::baseUrl('http://opnsense.local')->throw();

        $service = new OpnSenseDhcpService(
            client: $client,
            poolSize: 0,
            ipv4RangesPath: '/api/dhcpv4/ranges',
        );

        $ranges = $service->getRanges();
        $this->assertCount(1, $ranges);
        $this->assertEquals('ipv4', $ranges[0]->type);
        $this->assertEquals('10.0.0.1', $ranges[0]->rangeFrom);
        $this->assertSame('254', $ranges[0]->totalAddresses);
        $this->assertEquals(1, $ranges[0]->usedAddresses);
    }

    public function test_get_ranges_returns_ipv6_ranges_with_usage_stats(): void
    {
        // Covers getRanges() with ipv6RangesPath set and IPv6 enrichment
        $mock = Http::sequence([
            // IPv6 ranges
            Http::response([
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
            ], 200),
            // Leases for enrichment
            Http::response([
                'rows' => [
                    ['address' => 'fd00::10', 'mac' => 'aa:bb:cc:dd:ee:ff', 'hostname' => 'ipv6device', 'ends' => '2026-04-15 12:00:00', 'status' => 'active'],
                ],
            ], 200),
        ]);
        Http::fake(['*' => $mock]);
        $client = Http::baseUrl('http://opnsense.local')->throw();

        $service = new OpnSenseDhcpService(
            client: $client,
            poolSize: 0,
            ipv6RangesPath: '/api/dhcpv6/ranges',
        );

        $ranges = $service->getRanges();
        $this->assertCount(1, $ranges);
        $this->assertEquals('ipv6', $ranges[0]->type);
        $this->assertEquals('fd00::1', $ranges[0]->rangeFrom);
        $this->assertGreaterThan(0, $ranges[0]->usedAddresses);
    }

    public function test_get_ranges_returns_empty_collection_when_ranges_path_not_set(): void
    {
        // When ipv4RangesPath and ipv6RangesPath are both empty, no HTTP calls are made
        $mock = Http::sequence([]);
        Http::fake(['*' => $mock]);
        $client = Http::baseUrl('http://opnsense.local')->throw();

        $service = new OpnSenseDhcpService(client: $client, poolSize: 0);

        $ranges = $service->getRanges();
        $this->assertCount(0, $ranges);
    }

    public function test_get_ranges_handles_fetch_exception_gracefully(): void
    {
        // Covers the catch(Throwable) in getRanges() when HTTP request fails
        $mock = Http::sequence()->pushFailedConnection('Connection refused');
        Http::fake(['*' => $mock]);
        $client = Http::baseUrl('http://opnsense.local')->throw();

        $service = new OpnSenseDhcpService(
            client: $client,
            poolSize: 0,
            ipv4RangesPath: '/api/dhcpv4/ranges',
        );

        // Should return empty collection, not throw
        $ranges = $service->getRanges();
        $this->assertCount(0, $ranges);
    }

    public function test_build_range_from_row_uses_kea_pools_format(): void
    {
        // Covers lines 143-148: Kea pools "START - END" format parsing
        $mock = Http::sequence([
            Http::response([
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
            ], 200),
            Http::response(['rows' => []], 200),
        ]);
        Http::fake(['*' => $mock]);
        $client = Http::baseUrl('http://opnsense.local')->throw();

        $service = new OpnSenseDhcpService(
            client: $client,
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

        $ranges = $service->getRanges();
        $this->assertCount(1, $ranges);
        $this->assertEquals('10.0.1.100', $ranges[0]->rangeFrom);
        $this->assertEquals('10.0.1.200', $ranges[0]->rangeTo);
    }

    public function test_build_range_from_row_calculates_subnet_from_subnet_mask(): void
    {
        // Covers lines 152-156: calculateSubnet() from subnet_mask field (dnsmasq IPv4)
        // The subnet field must NOT be present in the row (or must be absent) so that
        // $subnet remains null and the calculateSubnet branch executes.
        $mock = Http::sequence([
            Http::response([
                'rows' => [
                    [
                        'interface' => 'em0',
                        // Note: 'subnet' key is absent so isset() returns false → $subnet = null
                        'range_from' => '192.168.1.100',
                        'range_to' => '192.168.1.200',
                        'gateway' => '192.168.1.1',
                        'description' => 'dnsmasq pool',
                        'prefix' => '',
                        'subnet_mask' => '255.255.255.0',
                    ],
                ],
            ], 200),
            Http::response(['rows' => []], 200),
        ]);
        Http::fake(['*' => $mock]);
        $client = Http::baseUrl('http://opnsense.local')->throw();

        $service = new OpnSenseDhcpService(
            client: $client,
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

        $ranges = $service->getRanges();
        $this->assertCount(1, $ranges);
        // The subnet should be calculated from the IP + mask
        $this->assertNotNull($ranges[0]->subnet);
        $this->assertStringContainsString('192.168.1.0', (string) $ranges[0]->subnet);
    }

    public function test_build_range_from_row_handles_ipv6_prefix_construction(): void
    {
        // Covers lines 160-165: IPv6 prefix construction from start_addr and prefix_len
        $mock = Http::sequence([
            Http::response([
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
            ], 200),
            Http::response(['rows' => []], 200),
        ]);
        Http::fake(['*' => $mock]);
        $client = Http::baseUrl('http://opnsense.local')->throw();

        $service = new OpnSenseDhcpService(
            client: $client,
            poolSize: 0,
            ipv6RangesPath: '/api/dhcpv6/ranges',
        );

        $ranges = $service->getRanges();
        $this->assertCount(1, $ranges);
        // prefix should have been constructed as "fd00::1/64"
        $this->assertEquals('fd00::1/64', $ranges[0]->prefix);
    }

    public function test_get_ranges_normalizes_ipv6_prefix_to_lowercase(): void
    {
        $mock = Http::sequence([
            Http::response([
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
            ], 200),
            Http::response(['rows' => []], 200),
        ]);
        Http::fake(['*' => $mock]);
        $client = Http::baseUrl('http://opnsense.local')->throw();

        $service = new OpnSenseDhcpService(
            client: $client,
            poolSize: 0,
            ipv6RangesPath: '/api/dhcpv6/ranges',
        );

        $ranges = $service->getRanges();
        $this->assertCount(1, $ranges);
        // Constructed from the raw (uppercase) start address, then normalized
        $this->assertSame('fd00:abcd::1/64', $ranges[0]->prefix);
    }

    public function test_calculate_subnet_returns_null_for_invalid_ip(): void
    {
        // Covers line 185-186 in calculateSubnet(): ip2long returns false for invalid IP
        // The subnet field must NOT be present so $subnet remains null and calculateSubnet is called
        $mock = Http::sequence([
            Http::response([
                'rows' => [
                    [
                        'interface' => 'em0',
                        // 'subnet' key absent so $subnet = null → triggers calculateSubnet branch
                        'range_from' => 'not-an-ip',
                        'range_to' => '192.168.1.200',
                        'gateway' => '',
                        'description' => 'invalid',
                        'prefix' => '',
                        'subnet_mask' => '255.255.255.0',
                    ],
                ],
            ], 200),
            Http::response(['rows' => []], 200),
        ]);
        Http::fake(['*' => $mock]);
        $client = Http::baseUrl('http://opnsense.local')->throw();

        $service = new OpnSenseDhcpService(
            client: $client,
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

        $ranges = $service->getRanges();
        $this->assertCount(1, $ranges);
        // calculateSubnet returned null because ip2long('not-an-ip') returns false
        $this->assertNull($ranges[0]->subnet);
    }

    public function test_enrich_range_returns_unchanged_when_range_from_or_range_to_is_null(): void
    {
        // Covers enrichRangeWithUsage() line 232-233: returns $range early when rangeFrom/rangeTo null
        // To get rangeFrom === null, the 'range_from' key must be absent from the row so isset() returns false
        $mock = Http::sequence([
            Http::response([
                'rows' => [
                    [
                        'interface' => 'em0',
                        'subnet' => '10.0.0.0/24',
                        // 'range_from' and 'range_to' keys are ABSENT → isset() returns false → null
                        'gateway' => '',
                        'description' => 'no range',
                        'prefix' => '',
                    ],
                ],
            ], 200),
            Http::response([
                'rows' => [
                    ['address' => '10.0.0.10', 'mac' => 'aa:bb', 'hostname' => 'h', 'ends' => '', 'status' => 'active'],
                ],
            ], 200),
        ]);
        Http::fake(['*' => $mock]);
        $client = Http::baseUrl('http://opnsense.local')->throw();

        $service = new OpnSenseDhcpService(
            client: $client,
            poolSize: 0,
            ipv4RangesPath: '/api/dhcpv4/ranges',
        );

        $ranges = $service->getRanges();
        $this->assertCount(1, $ranges);
        // rangeFrom is null because the key was absent; enrichRangeWithUsage returned early
        $this->assertNull($ranges[0]->rangeFrom);
        $this->assertNull($ranges[0]->totalAddresses);
    }

    public function test_enrich_ipv6_range_returns_unchanged_when_range_is_invalid_ipv6(): void
    {
        // Covers enrichIpv6RangeWithUsage() line 288-289: inet_pton() returns false for invalid IPv6
        // A range with ':' in rangeFrom triggers ipv6 detection, but if the address is invalid
        // inet_pton() returns false and the range is returned unchanged.
        $mock = Http::sequence([
            Http::response([
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
            ], 200),
            Http::response(['rows' => []], 200),
        ]);
        Http::fake(['*' => $mock]);
        $client = Http::baseUrl('http://opnsense.local')->throw();

        $service = new OpnSenseDhcpService(
            client: $client,
            poolSize: 0,
            ipv6RangesPath: '/api/dhcpv6/ranges',
        );

        $ranges = $service->getRanges();
        $this->assertCount(1, $ranges);
        // inet_pton failed, enrichment skipped
        $this->assertNull($ranges[0]->totalAddresses);
    }

    public function test_enrich_ipv4_range_returns_unchanged_when_range_from_is_invalid_ip(): void
    {
        // Covers enrichIpv4RangeWithUsage() line 251-252: ip2long returns false
        $mock = Http::sequence([
            Http::response([
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
            ], 200),
            Http::response(['rows' => []], 200),
        ]);
        Http::fake(['*' => $mock]);
        $client = Http::baseUrl('http://opnsense.local')->throw();

        $service = new OpnSenseDhcpService(
            client: $client,
            poolSize: 0,
            ipv4RangesPath: '/api/dhcpv4/ranges',
        );

        $ranges = $service->getRanges();
        $this->assertCount(1, $ranges);
        // ip2long returned false, so enrichment was skipped
        $this->assertNull($ranges[0]->totalAddresses);
    }

    public function test_ipv6_diff_returns_php_int_max_for_very_large_range(): void
    {
        // Covers ipv6Diff() line 326: return PHP_INT_MAX when the difference overflows
        // We call ipv6Diff() directly via reflection to avoid downstream TypeError from +1.
        $mock = Http::sequence([]);
        Http::fake(['*' => $mock]);
        $client = Http::baseUrl('http://opnsense.local')->throw();

        $service = new OpnSenseDhcpService(client: $client, poolSize: 0);

        $reflection = new ReflectionClass($service);
        $method = $reflection->getMethod('ipv6Diff');

        // Use from = ::1 and to = ffff::ffff:ffff:ffff:ffff:ffff:ffff
        // The difference in the first byte alone (0xff vs 0x00) immediately causes overflow
        $fromBin = inet_pton('::1');
        $toBin = inet_pton('ffff:ffff:ffff:ffff:ffff:ffff:ffff:ffff');

        $this->assertNotFalse($fromBin);
        $this->assertNotFalse($toBin);

        $result = $method->invoke($service, $fromBin, $toBin);

        $this->assertSame(PHP_INT_MAX, $result);
    }

    public function test_subnet_mask_to_cidr_returns_null_for_invalid_mask(): void
    {
        // Covers subnetMaskToCidr() line 199: return null when ip2long($subnetMask) is false
        // This method is private so we access it via reflection
        $mock = Http::sequence([]);
        Http::fake(['*' => $mock]);
        $client = Http::baseUrl('http://opnsense.local')->throw();

        $service = new OpnSenseDhcpService(client: $client, poolSize: 0);

        $reflection = new ReflectionClass($service);
        $method = $reflection->getMethod('subnetMaskToCidr');

        // Call with an invalid subnet mask string
        $result = $method->invoke($service, 'not-a-valid-mask');

        $this->assertNull($result);
    }

    public function test_both_ipv4_and_ipv6_paths_deduplicated_when_same(): void
    {
        // When ipv4RangesPath === ipv6RangesPath, only one request is made (second is skipped)
        $mock = Http::sequence([
            Http::response([
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
            ], 200),
            Http::response(['rows' => []], 200),
        ]);
        Http::fake(['*' => $mock]);
        $client = Http::baseUrl('http://opnsense.local')->throw();

        $service = new OpnSenseDhcpService(
            client: $client,
            poolSize: 0,
            ipv4RangesPath: '/api/dhcpv4/ranges',
            ipv6RangesPath: '/api/dhcpv4/ranges', // Same path — should not fetch twice
        );

        $ranges = $service->getRanges();
        $this->assertCount(1, $ranges);
    }

    public function test_get_fetch_status_reports_success(): void
    {
        $service = $this->createServiceWithMock([]);

        $this->assertSame(['ipv4' => true, 'ipv6' => true], $service->getFetchStatus());
    }

    public function test_reset_snapshot_is_a_no_op(): void
    {
        $service = $this->createServiceWithMock([]);

        $service->resetSnapshot();

        $this->assertSame(['ipv4' => true, 'ipv6' => true], $service->getFetchStatus());
    }
}
