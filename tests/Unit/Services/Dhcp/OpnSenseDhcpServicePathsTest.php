<?php

declare(strict_types=1);

namespace Tests\Unit\Services\Dhcp;

use App\Enums\AddressFamily;
use App\Services\OpnSense\OpnSenseClient;
use App\Services\OpnSense\OpnSenseDhcpService;
use GuzzleHttp\Promise\PromiseInterface;
use Tests\Support\Fake;
use Tests\TestCase;
use Throwable;

class OpnSenseDhcpServicePathsTest extends TestCase
{
    /**
     * @param  array<int, PromiseInterface|Throwable>  $responses
     */
    private function createServiceWithHistory(
        array $responses,
        string $leasesPath = '/api/dhcpv4/leases/search_lease',
        string $ipv4RangesPath = '',
        string $ipv6RangesPath = '',
    ): OpnSenseDhcpService {
        Fake::sequence($responses);

        return new OpnSenseDhcpService(OpnSenseClient::fromConfig(['endpoint' => 'http://opnsense.test', 'key' => 'key', 'secret' => 'secret'])->request(), 254, $leasesPath, $ipv4RangesPath, $ipv6RangesPath);
    }

    public function test_uses_custom_leases_path(): void
    {
        $service = $this->createServiceWithHistory(
            [
                Fake::response(200, [], (string) json_encode([
                    'rows' => [
                        ['address' => '10.0.0.1', 'mac' => 'aa:bb:cc:dd:ee:ff', 'hostname' => 'h1', 'ends' => '2026-01-01', 'status' => 'active'],
                    ],
                ])),
            ],
            leasesPath: '/api/kea/leases/search',
        );

        $service->snapshot()->leases;

        $this->assertCount(1, Fake::requests());
        $this->assertEquals('/api/kea/leases/search', parse_url(Fake::requests()[0]->url(), PHP_URL_PATH));
    }

    public function test_uses_custom_ipv4_ranges_path(): void
    {
        $service = $this->createServiceWithHistory(
            [
                Fake::response(200, [], (string) json_encode(['rows' => []])),
            ],
            ipv4RangesPath: '/api/kea/dhcpv4/search_subnet',
        );

        $service->snapshot()->ranges;

        $this->assertCount(1, Fake::requests());
        $this->assertEquals('/api/kea/dhcpv4/search_subnet', parse_url(Fake::requests()[0]->url(), PHP_URL_PATH));
    }

    public function test_uses_custom_ipv6_ranges_path(): void
    {
        $service = $this->createServiceWithHistory(
            [
                Fake::response(200, [], (string) json_encode(['rows' => []])),
            ],
            ipv6RangesPath: '/api/kea/dhcpv6/search_subnet',
        );

        $service->snapshot()->ranges;

        $this->assertCount(1, Fake::requests());
        $this->assertEquals('/api/kea/dhcpv6/search_subnet', parse_url(Fake::requests()[0]->url(), PHP_URL_PATH));
    }

    public function test_skips_ipv4_when_path_is_empty(): void
    {
        $service = $this->createServiceWithHistory(
            [
                Fake::response(200, [], (string) json_encode([
                    'rows' => [
                        ['interface' => 'lan', 'prefix' => 'fd00::/64', 'description' => 'LAN IPv6'],
                    ],
                ])),
                Fake::response(200, [], (string) json_encode(['rows' => []])),
            ],
            ipv4RangesPath: '',
            ipv6RangesPath: '/api/kea/dhcpv6/search_subnet',
        );

        $ranges = $service->snapshot()->ranges;

        $this->assertCount(2, Fake::requests());
        $this->assertCount(1, $ranges);
        $this->assertEquals(AddressFamily::IPv6, $ranges->first()->type);
    }

    public function test_skips_ipv6_when_path_is_empty(): void
    {
        $service = $this->createServiceWithHistory(
            [
                Fake::response(200, [], (string) json_encode([
                    'rows' => [
                        ['interface' => 'lan', 'range_from' => '10.0.0.100', 'range_to' => '10.0.0.200', 'subnet' => '10.0.0.0/24'],
                    ],
                ])),
                Fake::response(200, [], (string) json_encode(['rows' => []])),
            ],
            ipv4RangesPath: '/api/kea/dhcpv4/search_subnet',
            ipv6RangesPath: '',
        );

        $ranges = $service->snapshot()->ranges;

        $this->assertCount(2, Fake::requests());
        $this->assertCount(1, $ranges);
        $this->assertEquals(AddressFamily::IPv4, $ranges->first()->type);
    }

    public function test_skips_both_ranges_when_paths_are_empty(): void
    {
        $service = $this->createServiceWithHistory(
            [],
            ipv4RangesPath: '',
            ipv6RangesPath: '',
        );

        $ranges = $service->snapshot()->ranges;

        $this->assertCount(0, Fake::requests());
        $this->assertCount(0, $ranges);
    }

    public function test_leases_use_get_method(): void
    {
        $service = $this->createServiceWithHistory(
            [
                Fake::response(200, [], (string) json_encode([
                    'rows' => [
                        ['address' => '10.0.0.1', 'mac' => 'aa:bb:cc:dd:ee:ff', 'hostname' => 'h1', 'ends' => '2026-01-01', 'status' => 'active'],
                    ],
                ])),
            ],
        );

        $service->snapshot()->leases;

        $this->assertCount(1, Fake::requests());
        $this->assertEquals('GET', Fake::requests()[0]->method());
    }

    public function test_default_paths_use_isc_endpoints(): void
    {
        $service = $this->createServiceWithHistory(
            [
                Fake::response(200, [], (string) json_encode(['rows' => [['address' => '10.0.0.1', 'mac' => 'aa:bb:cc:dd:ee:ff', 'hostname' => 'h1', 'ends' => '2026-01-01', 'status' => 'active']]])),
            ],
        );

        $snapshot = $service->snapshot();

        $ranges = $snapshot->ranges;

        $this->assertCount(1, Fake::requests(), 'Only the leases request should be made; ISC has no range endpoints');
        $this->assertEquals('/api/dhcpv4/leases/search_lease', parse_url(Fake::requests()[0]->url(), PHP_URL_PATH));
        $this->assertEquals('GET', Fake::requests()[0]->method());
        $this->assertCount(0, $ranges);
    }

    public function test_does_not_fetch_duplicates_when_ipv4_and_ipv6_paths_are_identical(): void
    {
        $service = $this->createServiceWithHistory(
            [
                Fake::response(200, [], (string) json_encode([
                    'rows' => [
                        ['interface' => 'lan', 'start_addr' => '10.0.0.100', 'end_addr' => '10.0.0.200', 'subnet_mask' => '255.255.255.0'],
                        ['interface' => 'dmz', 'start_addr' => '10.1.0.100', 'end_addr' => '10.1.0.200', 'subnet_mask' => '255.255.255.0'],
                    ],
                ])),
                Fake::response(200, [], (string) json_encode(['rows' => []])),
            ],
            ipv4RangesPath: '/api/dnsmasq/settings/search_range',
            ipv6RangesPath: '/api/dnsmasq/settings/search_range',
        );

        $ranges = $service->snapshot()->ranges;

        $this->assertCount(2, Fake::requests());
        $this->assertEquals('/api/dnsmasq/settings/search_range', parse_url(Fake::requests()[0]->url(), PHP_URL_PATH));
        $this->assertEquals('/api/dhcpv4/leases/search_lease', parse_url(Fake::requests()[1]->url(), PHP_URL_PATH));
        $this->assertCount(2, $ranges, 'Should not duplicate ranges when both IPv4 and IPv6 use the same endpoint');
    }
}
