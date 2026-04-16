<?php

declare(strict_types=1);

namespace Tests\Unit\Services\Dhcp;

use App\Services\Dhcp\OpnSenseDhcpService;
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
        string $leasesPath = '/api/dhcpv4/leases/searchLease',
        string $ipv4RangesPath = '/api/dhcpv4/service/searchSubnet',
        string $ipv6RangesPath = '/api/dhcpv6/service/searchSubnet',
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
            leasesPath: '/api/kea/leases4/search',
        );

        $service->getLeases();

        $this->assertCount(1, $history);
        $this->assertEquals('/api/kea/leases4/search', $history[0]['request']->getUri()->getPath());
    }

    public function test_uses_custom_ipv4_ranges_path(): void
    {
        $history = [];
        $service = $this->createServiceWithHistory(
            [
                new Response(200, [], (string) json_encode(['rows' => []])),
                new Response(200, [], (string) json_encode(['rows' => []])),
            ],
            $history,
            ipv4RangesPath: '/api/kea/dhcpv4/search',
        );

        $service->getRanges();

        $this->assertEquals('/api/kea/dhcpv4/search', $history[0]['request']->getUri()->getPath());
    }

    public function test_uses_custom_ipv6_ranges_path(): void
    {
        $history = [];
        $service = $this->createServiceWithHistory(
            [
                new Response(200, [], (string) json_encode(['rows' => []])),
                new Response(200, [], (string) json_encode(['rows' => []])),
            ],
            $history,
            ipv6RangesPath: '/api/kea/dhcpv6/search',
        );

        $service->getRanges();

        $this->assertEquals('/api/kea/dhcpv6/search', $history[1]['request']->getUri()->getPath());
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
            ],
            $history,
            ipv6RangesPath: '',
        );

        $ranges = $service->getRanges();

        $this->assertCount(1, $history);
        $this->assertCount(1, $ranges);
        $this->assertEquals('ipv4', $ranges->first()->type);
    }

    public function test_default_paths_use_isc_endpoints(): void
    {
        $history = [];
        $service = $this->createServiceWithHistory(
            [
                new Response(200, [], (string) json_encode(['rows' => [['address' => '10.0.0.1', 'mac' => 'aa:bb:cc:dd:ee:ff', 'hostname' => 'h1', 'ends' => '2026-01-01', 'status' => 'active']]])),
                new Response(200, [], (string) json_encode(['rows' => []])),
                new Response(200, [], (string) json_encode(['rows' => []])),
            ],
            $history,
        );

        $service->getLeases();
        $service->getRanges();

        $this->assertEquals('/api/dhcpv4/leases/searchLease', $history[0]['request']->getUri()->getPath());
        $this->assertEquals('/api/dhcpv4/service/searchSubnet', $history[1]['request']->getUri()->getPath());
        $this->assertEquals('/api/dhcpv6/service/searchSubnet', $history[2]['request']->getUri()->getPath());
    }
}
