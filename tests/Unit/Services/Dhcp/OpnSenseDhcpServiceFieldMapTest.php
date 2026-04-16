<?php

declare(strict_types=1);

namespace Tests\Unit\Services\Dhcp;

use App\Services\Dhcp\OpnSenseDhcpService;
use App\Services\ValueObjects\DhcpLease;
use App\Services\ValueObjects\DhcpRange;
use GuzzleHttp\Client;
use GuzzleHttp\Handler\MockHandler;
use GuzzleHttp\HandlerStack;
use GuzzleHttp\Middleware;
use GuzzleHttp\Psr7\Request;
use GuzzleHttp\Psr7\Response;
use Tests\TestCase;

class OpnSenseDhcpServiceFieldMapTest extends TestCase
{
    /** @var array<string, string> */
    private const DNSMASQ_LEASE_MAP = [
        'ip' => 'address',
        'mac' => 'hwaddr',
        'hostname' => 'hostname',
        'expires' => 'expires',
        'status' => 'status',
    ];

    /** @var array<string, string> */
    private const DNSMASQ_RANGE_MAP = [
        'interface' => 'interface',
        'subnet' => 'subnet',
        'range_from' => 'from',
        'range_to' => 'to',
        'gateway' => 'gateway',
        'description' => 'domain',
        'prefix' => 'prefix',
    ];

    /** @var array<string, string> */
    private const KEA_LEASE_MAP = [
        'ip' => 'address',
        'mac' => 'hwaddr',
        'hostname' => 'hostname',
        'expires' => 'expire',
        'status' => 'state',
    ];

    /**
     * @param  list<Response>  $responses
     * @param  array<string, string>  $leaseFieldMap
     * @param  array<string, string>  $rangeFieldMap
     */
    private function createService(
        array $responses,
        int $poolSize = 254,
        string $ipv4RangesPath = '',
        string $ipv6RangesPath = '',
        array $leaseFieldMap = ['ip' => 'address', 'mac' => 'mac', 'hostname' => 'hostname', 'expires' => 'ends', 'status' => 'status'],
        array $rangeFieldMap = ['interface' => 'interface', 'subnet' => 'subnet', 'range_from' => 'range_from', 'range_to' => 'range_to', 'gateway' => 'gateway', 'description' => 'description', 'prefix' => 'prefix'],
    ): OpnSenseDhcpService {
        $mock = new MockHandler($responses);
        $handler = HandlerStack::create($mock);
        $client = new Client(['handler' => $handler]);

        return new OpnSenseDhcpService(
            client: $client,
            poolSize: $poolSize,
            ipv4RangesPath: $ipv4RangesPath,
            ipv6RangesPath: $ipv6RangesPath,
            leaseFieldMap: $leaseFieldMap,
            rangeFieldMap: $rangeFieldMap,
        );
    }

    public function test_dnsmasq_leases_mapped_from_hwaddr_and_expires(): void
    {
        $service = $this->createService(
            responses: [
                new Response(200, [], (string) json_encode([
                    'rows' => [
                        [
                            'address' => '10.0.0.50',
                            'hwaddr' => 'aa:bb:cc:dd:ee:01',
                            'hostname' => 'dnsmasq-host',
                            'expires' => '2026-06-01 12:00:00',
                            'if' => 'em0',
                        ],
                    ],
                ])),
            ],
            leaseFieldMap: self::DNSMASQ_LEASE_MAP,
        );

        $leases = $service->getLeases();

        $this->assertCount(1, $leases);
        $this->assertInstanceOf(DhcpLease::class, $leases[0]);
        $this->assertEquals('10.0.0.50', $leases[0]->ip);
        $this->assertEquals('aa:bb:cc:dd:ee:01', $leases[0]->mac);
        $this->assertEquals('dnsmasq-host', $leases[0]->hostname);
        $this->assertEquals('2026-06-01 12:00:00', $leases[0]->expires);
    }

    public function test_kea_leases_mapped_from_hwaddr_and_expire(): void
    {
        $service = $this->createService(
            responses: [
                new Response(200, [], (string) json_encode([
                    'rows' => [
                        [
                            'address' => '10.0.0.60',
                            'hwaddr' => 'ff:ee:dd:cc:bb:aa',
                            'hostname' => 'kea-host',
                            'expire' => '1748736000',
                            'if' => 'igb0',
                        ],
                    ],
                ])),
            ],
            leaseFieldMap: self::KEA_LEASE_MAP,
        );

        $leases = $service->getLeases();

        $this->assertCount(1, $leases);
        $this->assertEquals('10.0.0.60', $leases[0]->ip);
        $this->assertEquals('ff:ee:dd:cc:bb:aa', $leases[0]->mac);
        $this->assertEquals('kea-host', $leases[0]->hostname);
        $this->assertEquals('1748736000', $leases[0]->expires);
    }

    public function test_dnsmasq_pool_status_counts_all_leases_as_active_when_no_status_field(): void
    {
        $service = $this->createService(
            responses: [
                new Response(200, [], (string) json_encode([
                    'rows' => [
                        ['address' => '10.0.0.1', 'hwaddr' => 'aa:bb:cc:00:00:01', 'hostname' => 'h1', 'expires' => '2026-01-01'],
                        ['address' => '10.0.0.2', 'hwaddr' => 'aa:bb:cc:00:00:02', 'hostname' => 'h2', 'expires' => '2026-01-01'],
                        ['address' => '10.0.0.3', 'hwaddr' => 'aa:bb:cc:00:00:03', 'hostname' => 'h3', 'expires' => '2026-01-01'],
                    ],
                ])),
            ],
            poolSize: 100,
            leaseFieldMap: self::DNSMASQ_LEASE_MAP,
        );

        $pool = $service->getPoolStatus();

        $this->assertEquals(100, $pool->total);
        $this->assertEquals(3, $pool->used);
        $this->assertEquals(97, $pool->available);
    }

    public function test_kea_pool_status_counts_all_leases_as_active_when_no_status_field(): void
    {
        $service = $this->createService(
            responses: [
                new Response(200, [], (string) json_encode([
                    'rows' => [
                        ['address' => '10.0.0.1', 'hwaddr' => 'aa:bb:cc:00:00:01', 'hostname' => 'h1', 'expire' => '1748736000'],
                        ['address' => '10.0.0.2', 'hwaddr' => 'aa:bb:cc:00:00:02', 'hostname' => 'h2', 'expire' => '1748736000'],
                    ],
                ])),
            ],
            poolSize: 50,
            leaseFieldMap: self::KEA_LEASE_MAP,
        );

        $pool = $service->getPoolStatus();

        $this->assertEquals(50, $pool->total);
        $this->assertEquals(2, $pool->used);
        $this->assertEquals(48, $pool->available);
    }

    public function test_dnsmasq_get_lease_uses_mapped_fields(): void
    {
        $service = $this->createService(
            responses: [
                new Response(200, [], (string) json_encode([
                    'rows' => [
                        ['address' => '10.0.0.50', 'hwaddr' => 'aa:bb:cc:dd:ee:01', 'hostname' => 'target', 'expires' => '2026-06-01 12:00:00'],
                        ['address' => '10.0.0.51', 'hwaddr' => 'aa:bb:cc:dd:ee:02', 'hostname' => 'other', 'expires' => '2026-06-01 13:00:00'],
                    ],
                ])),
            ],
            leaseFieldMap: self::DNSMASQ_LEASE_MAP,
        );

        $lease = $service->getLease('10.0.0.50');

        $this->assertNotNull($lease);
        $this->assertInstanceOf(DhcpLease::class, $lease);
        $this->assertEquals('10.0.0.50', $lease->ip);
        $this->assertEquals('aa:bb:cc:dd:ee:01', $lease->mac);
        $this->assertEquals('target', $lease->hostname);
    }

    public function test_dnsmasq_get_lease_returns_null_when_not_found(): void
    {
        $service = $this->createService(
            responses: [
                new Response(200, [], (string) json_encode([
                    'rows' => [
                        ['address' => '10.0.0.50', 'hwaddr' => 'aa:bb:cc:dd:ee:01', 'hostname' => 'h1', 'expires' => '2026-06-01'],
                    ],
                ])),
            ],
            leaseFieldMap: self::DNSMASQ_LEASE_MAP,
        );

        $lease = $service->getLease('10.0.0.99');

        $this->assertNull($lease);
    }

    public function test_dnsmasq_ranges_mapped_from_and_to_fields(): void
    {
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
                new Response(200, [], (string) json_encode(['rows' => []])),
            ],
            ipv4RangesPath: '/api/dnsmasq/settings/search_range',
            rangeFieldMap: self::DNSMASQ_RANGE_MAP,
        );

        $ranges = $service->getRanges();

        $this->assertCount(1, $ranges);
        $this->assertInstanceOf(DhcpRange::class, $ranges->first());
        $this->assertEquals('lan', $ranges->first()->interface);
        $this->assertEquals('ipv4', $ranges->first()->type);
        $this->assertEquals('10.0.0.100', $ranges->first()->rangeFrom);
        $this->assertEquals('10.0.0.200', $ranges->first()->rangeTo);
        $this->assertEquals('lan.local', $ranges->first()->description);
        $this->assertNull($ranges->first()->subnet);
        $this->assertNull($ranges->first()->gateway);
    }

    public function test_isc_defaults_remain_backward_compatible(): void
    {
        $service = $this->createService(
            responses: [
                new Response(200, [], (string) json_encode([
                    'rows' => [
                        ['address' => '10.0.0.10', 'mac' => 'aa:bb:cc:dd:ee:ff', 'hostname' => 'isc-host', 'ends' => '2026-04-15 12:00:00', 'status' => 'active'],
                    ],
                ])),
            ],
        );

        $leases = $service->getLeases();

        $this->assertCount(1, $leases);
        $this->assertEquals('10.0.0.10', $leases[0]->ip);
        $this->assertEquals('aa:bb:cc:dd:ee:ff', $leases[0]->mac);
        $this->assertEquals('isc-host', $leases[0]->hostname);
        $this->assertEquals('2026-04-15 12:00:00', $leases[0]->expires);
    }

    public function test_get_requests_do_not_include_json_body(): void
    {
        /** @var list<array{request: Request}> $history */
        $history = [];
        $mock = new MockHandler([
            new Response(200, [], (string) json_encode([
                'rows' => [['address' => '10.0.0.1', 'mac' => 'aa:bb:cc:dd:ee:ff', 'hostname' => 'h1', 'ends' => '2026-01-01', 'status' => 'active']],
            ])),
        ]);
        $handler = HandlerStack::create($mock);
        $handler->push(Middleware::history($history));
        $client = new Client(['handler' => $handler]);

        $service = new OpnSenseDhcpService($client, 254);
        $service->getLeases();

        $this->assertCount(1, $history);
        $this->assertEquals('', (string) $history[0]['request']->getBody());
        $this->assertFalse($history[0]['request']->hasHeader('Content-Type'));
    }

    public function test_range_get_requests_do_not_include_json_body(): void
    {
        /** @var list<array{request: Request}> $history */
        $history = [];
        $mock = new MockHandler([
            new Response(200, [], (string) json_encode([
                'rows' => [['interface' => 'lan', 'range_from' => '10.0.0.100', 'range_to' => '10.0.0.200']],
            ])),
            new Response(200, [], (string) json_encode(['rows' => []])),
        ]);
        $handler = HandlerStack::create($mock);
        $handler->push(Middleware::history($history));
        $client = new Client(['handler' => $handler]);

        $service = new OpnSenseDhcpService($client, 254, ipv4RangesPath: '/api/kea/dhcpv4/search_subnet');
        $service->getRanges();

        $this->assertCount(2, $history);
        $this->assertEquals('', (string) $history[0]['request']->getBody());
        $this->assertFalse($history[0]['request']->hasHeader('Content-Type'));
    }
}
