<?php

declare(strict_types=1);

namespace Tests\Unit\Services\Dhcp;

use App\Services\OpnSense\OpnSenseDhcpService;
use GuzzleHttp\Client;
use GuzzleHttp\Handler\MockHandler;
use GuzzleHttp\HandlerStack;
use GuzzleHttp\Middleware;
use GuzzleHttp\Psr7\Request;
use GuzzleHttp\Psr7\Response;
use Tests\TestCase;

class OpnSenseDhcpServicePathsTest extends TestCase
{
    /**
     * @param  array<int, Response>  $responses
     * @param  array<int, array{request: Request}>  $history
     */
    private function createServiceWithHistory(
        array $responses,
        array &$history,
        string $leasesPath = '/api/dhcpv4/leases/search_lease',
        string $ipv4RangesPath = '',
        string $ipv6RangesPath = '',
    ): OpnSenseDhcpService {
        $mock = new MockHandler($responses);
        $handler = HandlerStack::create($mock);
        $handler->push(Middleware::history($history));

        $client = new Client(['handler' => $handler]);

        return new OpnSenseDhcpService($client, 254, $leasesPath, $ipv4RangesPath, $ipv6RangesPath);
    }

    public function test_uses_custom_leases_path(): void
    {
        $history = [];
        $service = $this->createServiceWithHistory(
            [
                new Response(200, [], (string) json_encode([
                    'rows' => [
                        ['address' => '10.0.0.1', 'mac' => 'aa:bb:cc:dd:ee:ff', 'hostname' => 'h1', 'ends' => '2026-01-01', 'status' => 'active'],
                    ],
                ])),
            ],
            $history,
            leasesPath: '/api/kea/leases/search',
        );

        $service->getLeases();

        $this->assertCount(1, $history);
        $this->assertEquals('/api/kea/leases/search', $history[0]['request']->getUri()->getPath());
    }

    public function test_uses_custom_ipv4_ranges_path(): void
    {
        $history = [];
        $service = $this->createServiceWithHistory(
            [
                new Response(200, [], (string) json_encode(['rows' => []])),
            ],
            $history,
            ipv4RangesPath: '/api/kea/dhcpv4/search_subnet',
        );

        $service->getRanges();

        $this->assertCount(1, $history);
        $this->assertEquals('/api/kea/dhcpv4/search_subnet', $history[0]['request']->getUri()->getPath());
    }

    public function test_uses_custom_ipv6_ranges_path(): void
    {
        $history = [];
        $service = $this->createServiceWithHistory(
            [
                new Response(200, [], (string) json_encode(['rows' => []])),
            ],
            $history,
            ipv6RangesPath: '/api/kea/dhcpv6/search_subnet',
        );

        $service->getRanges();

        $this->assertCount(1, $history);
        $this->assertEquals('/api/kea/dhcpv6/search_subnet', $history[0]['request']->getUri()->getPath());
    }

    public function test_skips_ipv4_when_path_is_empty(): void
    {
        $history = [];
        $service = $this->createServiceWithHistory(
            [
                new Response(200, [], (string) json_encode([
                    'rows' => [
                        ['interface' => 'lan', 'prefix' => 'fd00::/64', 'description' => 'LAN IPv6'],
                    ],
                ])),
                new Response(200, [], (string) json_encode(['rows' => []])),
            ],
            $history,
            ipv4RangesPath: '',
            ipv6RangesPath: '/api/kea/dhcpv6/search_subnet',
        );

        $ranges = $service->getRanges();

        $this->assertCount(2, $history);
        $this->assertCount(1, $ranges);
        $this->assertEquals('ipv6', $ranges->first()->type);
    }

    public function test_skips_ipv6_when_path_is_empty(): void
    {
        $history = [];
        $service = $this->createServiceWithHistory(
            [
                new Response(200, [], (string) json_encode([
                    'rows' => [
                        ['interface' => 'lan', 'range_from' => '10.0.0.100', 'range_to' => '10.0.0.200', 'subnet' => '10.0.0.0/24'],
                    ],
                ])),
                new Response(200, [], (string) json_encode(['rows' => []])),
            ],
            $history,
            ipv4RangesPath: '/api/kea/dhcpv4/search_subnet',
            ipv6RangesPath: '',
        );

        $ranges = $service->getRanges();

        $this->assertCount(2, $history);
        $this->assertCount(1, $ranges);
        $this->assertEquals('ipv4', $ranges->first()->type);
    }

    public function test_skips_both_ranges_when_paths_are_empty(): void
    {
        $history = [];
        $service = $this->createServiceWithHistory(
            [],
            $history,
            ipv4RangesPath: '',
            ipv6RangesPath: '',
        );

        $ranges = $service->getRanges();

        $this->assertCount(0, $history);
        $this->assertCount(0, $ranges);
    }

    public function test_leases_use_get_method(): void
    {
        $history = [];
        $service = $this->createServiceWithHistory(
            [
                new Response(200, [], (string) json_encode([
                    'rows' => [
                        ['address' => '10.0.0.1', 'mac' => 'aa:bb:cc:dd:ee:ff', 'hostname' => 'h1', 'ends' => '2026-01-01', 'status' => 'active'],
                    ],
                ])),
            ],
            $history,
        );

        $service->getLeases();

        $this->assertCount(1, $history);
        $this->assertEquals('GET', $history[0]['request']->getMethod());
    }

    public function test_default_paths_use_isc_endpoints(): void
    {
        $history = [];
        $service = $this->createServiceWithHistory(
            [
                new Response(200, [], (string) json_encode(['rows' => [['address' => '10.0.0.1', 'mac' => 'aa:bb:cc:dd:ee:ff', 'hostname' => 'h1', 'ends' => '2026-01-01', 'status' => 'active']]])),
            ],
            $history,
        );

        $service->getLeases();

        $ranges = $service->getRanges();

        $this->assertCount(1, $history, 'Only the leases request should be made; ISC has no range endpoints');
        $this->assertEquals('/api/dhcpv4/leases/search_lease', $history[0]['request']->getUri()->getPath());
        $this->assertEquals('GET', $history[0]['request']->getMethod());
        $this->assertCount(0, $ranges);
    }

    public function test_does_not_fetch_duplicates_when_ipv4_and_ipv6_paths_are_identical(): void
    {
        $history = [];
        $service = $this->createServiceWithHistory(
            [
                new Response(200, [], (string) json_encode([
                    'rows' => [
                        ['interface' => 'lan', 'start_addr' => '10.0.0.100', 'end_addr' => '10.0.0.200', 'subnet_mask' => '255.255.255.0'],
                        ['interface' => 'dmz', 'start_addr' => '10.1.0.100', 'end_addr' => '10.1.0.200', 'subnet_mask' => '255.255.255.0'],
                    ],
                ])),
                new Response(200, [], (string) json_encode(['rows' => []])),
            ],
            $history,
            ipv4RangesPath: '/api/dnsmasq/settings/search_range',
            ipv6RangesPath: '/api/dnsmasq/settings/search_range',
        );

        $ranges = $service->getRanges();

        $this->assertCount(2, $history);
        $this->assertEquals('/api/dnsmasq/settings/search_range', $history[0]['request']->getUri()->getPath());
        $this->assertEquals('/api/dhcpv4/leases/search_lease', $history[1]['request']->getUri()->getPath());
        $this->assertCount(2, $ranges, 'Should not duplicate ranges when both IPv4 and IPv6 use the same endpoint');
    }
}
