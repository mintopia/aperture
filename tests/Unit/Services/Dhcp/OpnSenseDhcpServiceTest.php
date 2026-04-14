<?php

declare(strict_types=1);

namespace Tests\Unit\Services\Dhcp;

use App\Services\Dhcp\OpnSenseDhcpService;
use GuzzleHttp\Client;
use GuzzleHttp\Handler\MockHandler;
use GuzzleHttp\HandlerStack;
use GuzzleHttp\Psr7\Response;
use Tests\TestCase;

class OpnSenseDhcpServiceTest extends TestCase
{
    /**
     * @param  array<int, Response>  $responses
     */
    private function createServiceWithMock(array $responses, int $poolSize = 0): OpnSenseDhcpService
    {
        $mock = new MockHandler($responses);
        $handler = HandlerStack::create($mock);
        $client = new Client(['handler' => $handler]);

        return new OpnSenseDhcpService($client, $poolSize);
    }

    public function test_get_leases_returns_collection(): void
    {
        $service = $this->createServiceWithMock([
            new Response(200, [], (string) json_encode([
                'rows' => [
                    ['address' => '10.0.0.10', 'mac' => 'aa:bb:cc:dd:ee:ff', 'hostname' => 'device1', 'ends' => '2026-04-15 12:00:00', 'status' => 'active'],
                    ['address' => '10.0.0.11', 'mac' => '11:22:33:44:55:66', 'hostname' => 'device2', 'ends' => '2026-04-15 13:00:00', 'status' => 'active'],
                ],
                'rowCount' => 2,
                'total' => 2,
                'current' => 1,
            ])),
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
            new Response(200, [], (string) json_encode([
                'rows' => [],
                'rowCount' => 0,
                'total' => 0,
                'current' => 1,
            ])),
        ]);

        $leases = $service->getLeases();
        $this->assertCount(0, $leases);
    }

    public function test_get_pool_status_returns_stats(): void
    {
        $service = $this->createServiceWithMock([
            new Response(200, [], (string) json_encode([
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

        $pool = $service->getPoolStatus();

        $this->assertEquals(254, $pool->total);
        $this->assertEquals(2, $pool->used);
        $this->assertEquals(252, $pool->available);
        $this->assertEqualsWithDelta(2 / 254, $pool->utilisation, 0.001);
    }

    public function test_get_pool_status_handles_zero_pool_size(): void
    {
        $service = $this->createServiceWithMock([
            new Response(200, [], (string) json_encode([
                'rows' => [],
                'rowCount' => 0,
                'total' => 0,
                'current' => 1,
            ])),
        ]);

        config(['aperture.dhcp.pool_size' => 0]);

        $pool = $service->getPoolStatus();

        $this->assertEquals(0, $pool->total);
        $this->assertEquals(0, $pool->used);
        $this->assertEquals(0, $pool->available);
        $this->assertEquals(0.0, $pool->utilisation);
    }

    public function test_get_lease_returns_matching_lease(): void
    {
        $service = $this->createServiceWithMock([
            new Response(200, [], (string) json_encode([
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
            new Response(200, [], (string) json_encode([
                'rows' => [],
                'rowCount' => 0,
                'total' => 0,
                'current' => 1,
            ])),
        ]);

        $lease = $service->getLease('10.0.0.99');
        $this->assertNull($lease);
    }
}
