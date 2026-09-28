<?php

declare(strict_types=1);

namespace Tests\Unit\Services\Dhcp;

use App\Services\OpnSense\OpnSenseDhcpService;
use GuzzleHttp\Promise\PromiseInterface;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class OpnSenseDhcpServicePathsTest extends TestCase
{
    /**
     * @param  array<int, PromiseInterface>  $responses
     *      */
    private function createServiceWithHistory(
        array $responses,
        string $leasesPath = '/api/dhcpv4/leases/search_lease',
        string $ipv4RangesPath = '',
        string $ipv6RangesPath = '',
    ): OpnSenseDhcpService {
        $mock = Http::sequence($responses);
        Http::fake(['*' => $mock]);
        $client = Http::baseUrl('http://opnsense.local')->throw();

        return new OpnSenseDhcpService($client, 254, $leasesPath, $ipv4RangesPath, $ipv6RangesPath);
    }

    /**
     * @return array<int, Request>
     */
    private function history(): array
    {
        return Http::recorded()->map(fn (array $pair): Request => $pair[0])->values()->all();
    }

    public function test_uses_custom_leases_path(): void
    {
        $service = $this->createServiceWithHistory(
            [
                Http::response([
                    'rows' => [
                        ['address' => '10.0.0.1', 'mac' => 'aa:bb:cc:dd:ee:ff', 'hostname' => 'h1', 'ends' => '2026-01-01', 'status' => 'active'],
                    ],
                ], 200),
            ],
            leasesPath: '/api/kea/leases/search',
        );

        $service->getLeases();

        $this->assertCount(1, $this->history());
        $this->assertEquals('/api/kea/leases/search', parse_url($this->history()[0]->url(), PHP_URL_PATH));
    }

    public function test_uses_custom_ipv4_ranges_path(): void
    {
        $service = $this->createServiceWithHistory(
            [
                Http::response(['rows' => []], 200),
            ],
            ipv4RangesPath: '/api/kea/dhcpv4/search_subnet',
        );

        $service->getRanges();

        $this->assertCount(1, $this->history());
        $this->assertEquals('/api/kea/dhcpv4/search_subnet', parse_url($this->history()[0]->url(), PHP_URL_PATH));
    }

    public function test_uses_custom_ipv6_ranges_path(): void
    {
        $service = $this->createServiceWithHistory(
            [
                Http::response(['rows' => []], 200),
            ],
            ipv6RangesPath: '/api/kea/dhcpv6/search_subnet',
        );

        $service->getRanges();

        $this->assertCount(1, $this->history());
        $this->assertEquals('/api/kea/dhcpv6/search_subnet', parse_url($this->history()[0]->url(), PHP_URL_PATH));
    }

    public function test_skips_ipv4_when_path_is_empty(): void
    {
        $service = $this->createServiceWithHistory(
            [
                Http::response([
                    'rows' => [
                        ['interface' => 'lan', 'prefix' => 'fd00::/64', 'description' => 'LAN IPv6'],
                    ],
                ], 200),
                Http::response(['rows' => []], 200),
            ],
            ipv4RangesPath: '',
            ipv6RangesPath: '/api/kea/dhcpv6/search_subnet',
        );

        $ranges = $service->getRanges();

        $this->assertCount(2, $this->history());
        $this->assertCount(1, $ranges);
        $this->assertEquals('ipv6', $ranges->first()->type);
    }

    public function test_skips_ipv6_when_path_is_empty(): void
    {
        $service = $this->createServiceWithHistory(
            [
                Http::response([
                    'rows' => [
                        ['interface' => 'lan', 'range_from' => '10.0.0.100', 'range_to' => '10.0.0.200', 'subnet' => '10.0.0.0/24'],
                    ],
                ], 200),
                Http::response(['rows' => []], 200),
            ],
            ipv4RangesPath: '/api/kea/dhcpv4/search_subnet',
            ipv6RangesPath: '',
        );

        $ranges = $service->getRanges();

        $this->assertCount(2, $this->history());
        $this->assertCount(1, $ranges);
        $this->assertEquals('ipv4', $ranges->first()->type);
    }

    public function test_skips_both_ranges_when_paths_are_empty(): void
    {
        $service = $this->createServiceWithHistory(
            [],
            ipv4RangesPath: '',
            ipv6RangesPath: '',
        );

        $ranges = $service->getRanges();

        $this->assertCount(0, $this->history());
        $this->assertCount(0, $ranges);
    }

    public function test_leases_use_get_method(): void
    {
        $service = $this->createServiceWithHistory(
            [
                Http::response([
                    'rows' => [
                        ['address' => '10.0.0.1', 'mac' => 'aa:bb:cc:dd:ee:ff', 'hostname' => 'h1', 'ends' => '2026-01-01', 'status' => 'active'],
                    ],
                ], 200),
            ],
        );

        $service->getLeases();

        $this->assertCount(1, $this->history());
        $this->assertEquals('GET', $this->history()[0]->method());
    }

    public function test_default_paths_use_isc_endpoints(): void
    {
        $service = $this->createServiceWithHistory(
            [
                Http::response(['rows' => [['address' => '10.0.0.1', 'mac' => 'aa:bb:cc:dd:ee:ff', 'hostname' => 'h1', 'ends' => '2026-01-01', 'status' => 'active']]], 200),
            ],
        );

        $service->getLeases();

        $ranges = $service->getRanges();

        $this->assertCount(1, $this->history(), 'Only the leases request should be made; ISC has no range endpoints');
        $this->assertEquals('/api/dhcpv4/leases/search_lease', parse_url($this->history()[0]->url(), PHP_URL_PATH));
        $this->assertEquals('GET', $this->history()[0]->method());
        $this->assertCount(0, $ranges);
    }

    public function test_does_not_fetch_duplicates_when_ipv4_and_ipv6_paths_are_identical(): void
    {
        $service = $this->createServiceWithHistory(
            [
                Http::response([
                    'rows' => [
                        ['interface' => 'lan', 'start_addr' => '10.0.0.100', 'end_addr' => '10.0.0.200', 'subnet_mask' => '255.255.255.0'],
                        ['interface' => 'dmz', 'start_addr' => '10.1.0.100', 'end_addr' => '10.1.0.200', 'subnet_mask' => '255.255.255.0'],
                    ],
                ], 200),
                Http::response(['rows' => []], 200),
            ],
            ipv4RangesPath: '/api/dnsmasq/settings/search_range',
            ipv6RangesPath: '/api/dnsmasq/settings/search_range',
        );

        $ranges = $service->getRanges();

        $this->assertCount(2, $this->history());
        $this->assertEquals('/api/dnsmasq/settings/search_range', parse_url($this->history()[0]->url(), PHP_URL_PATH));
        $this->assertEquals('/api/dhcpv4/leases/search_lease', parse_url($this->history()[1]->url(), PHP_URL_PATH));
        $this->assertCount(2, $ranges, 'Should not duplicate ranges when both IPv4 and IPv6 use the same endpoint');
    }
}
