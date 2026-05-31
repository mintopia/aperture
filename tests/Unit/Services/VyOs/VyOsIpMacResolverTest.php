<?php

declare(strict_types=1);

namespace Tests\Unit\Services\VyOs;

use App\Services\VyOs\VyOsClient;
use App\Services\VyOs\VyOsIpMacResolver;
use Mockery;
use Mockery\MockInterface;
use Tests\TestCase;

class VyOsIpMacResolverTest extends TestCase
{
    private VyOsClient&MockInterface $client;

    private VyOsIpMacResolver $resolver;

    protected function setUp(): void
    {
        parent::setUp();
        $this->client = Mockery::mock(VyOsClient::class);
        $this->resolver = new VyOsIpMacResolver($this->client);
    }

    public function test_get_arp_table_returns_ipv4_neighbors(): void
    {
        $this->client->shouldReceive('show')
            ->with(['ip', 'neighbors'])
            ->once()
            ->andReturn([
                [
                    'ip' => '192.168.1.100',
                    'mac' => 'aa:bb:cc:dd:ee:ff',
                    'interface' => 'eth0',
                    'state' => 'reachable',
                ],
                [
                    'ip' => '192.168.1.101',
                    'mac' => '11:22:33:44:55:66',
                    'interface' => 'eth0',
                    'state' => 'stale',
                ],
            ]);

        $this->client->shouldReceive('show')
            ->with(['ipv6', 'neighbors'])
            ->once()
            ->andReturn([]);

        $result = $this->resolver->getArpTable();

        $this->assertCount(2, $result);
        $this->assertSame('192.168.1.100', $result->get(0)->ip);
        $this->assertSame('aa:bb:cc:dd:ee:ff', $result->get(0)->mac);
        $this->assertSame('192.168.1.101', $result->get(1)->ip);
    }

    public function test_get_arp_table_returns_ipv6_neighbors(): void
    {
        $this->client->shouldReceive('show')
            ->with(['ip', 'neighbors'])
            ->once()
            ->andReturn([]);

        $this->client->shouldReceive('show')
            ->with(['ipv6', 'neighbors'])
            ->once()
            ->andReturn([
                [
                    'ip' => 'fe80::1',
                    'mac' => 'aa:bb:cc:dd:ee:03',
                    'interface' => 'eth0',
                    'state' => 'reachable',
                ],
            ]);

        $result = $this->resolver->getArpTable();

        $this->assertCount(1, $result);
        $this->assertSame('fe80::1', $result->first()->ip);
        $this->assertSame('aa:bb:cc:dd:ee:03', $result->first()->mac);
    }

    public function test_get_arp_table_merges_ipv4_and_ipv6(): void
    {
        $this->client->shouldReceive('show')
            ->with(['ip', 'neighbors'])
            ->once()
            ->andReturn([
                ['ip' => '192.168.1.1', 'mac' => 'aa:bb:cc:dd:ee:01', 'interface' => 'eth0', 'state' => 'reachable'],
            ]);

        $this->client->shouldReceive('show')
            ->with(['ipv6', 'neighbors'])
            ->once()
            ->andReturn([
                ['ip' => 'fe80::1', 'mac' => 'aa:bb:cc:dd:ee:02', 'interface' => 'eth0', 'state' => 'reachable'],
            ]);

        $result = $this->resolver->getArpTable();

        $this->assertCount(2, $result);
        $ips = $result->pluck('ip')->all();
        $this->assertContains('192.168.1.1', $ips);
        $this->assertContains('fe80::1', $ips);
    }

    public function test_get_arp_table_deduplicates_by_ip_and_mac(): void
    {
        $this->client->shouldReceive('show')
            ->with(['ip', 'neighbors'])
            ->once()
            ->andReturn([
                ['ip' => '192.168.1.1', 'mac' => 'aa:bb:cc:dd:ee:01', 'interface' => 'eth0', 'state' => 'reachable'],
            ]);

        $this->client->shouldReceive('show')
            ->with(['ipv6', 'neighbors'])
            ->once()
            ->andReturn([
                ['ip' => '192.168.1.1', 'mac' => 'aa:bb:cc:dd:ee:01', 'interface' => 'eth1', 'state' => 'reachable'],
                ['ip' => 'fe80::1', 'mac' => 'aa:bb:cc:dd:ee:02', 'interface' => 'eth0', 'state' => 'reachable'],
            ]);

        $result = $this->resolver->getArpTable();

        $this->assertCount(2, $result);
    }

    public function test_get_arp_table_returns_empty_collection_when_no_data(): void
    {
        $this->client->shouldReceive('show')
            ->with(['ip', 'neighbors'])
            ->once()
            ->andReturn([]);

        $this->client->shouldReceive('show')
            ->with(['ipv6', 'neighbors'])
            ->once()
            ->andReturn([]);

        $result = $this->resolver->getArpTable();

        $this->assertCount(0, $result);
    }

    public function test_get_arp_table_handles_api_exception_gracefully(): void
    {
        $this->client->shouldReceive('show')
            ->with(['ip', 'neighbors'])
            ->once()
            ->andThrow(new \RuntimeException('Connection refused'));

        $this->client->shouldReceive('show')
            ->with(['ipv6', 'neighbors'])
            ->once()
            ->andReturn([
                ['ip' => 'fe80::1', 'mac' => 'aa:bb:cc:dd:ee:01', 'interface' => 'eth0', 'state' => 'reachable'],
            ]);

        $result = $this->resolver->getArpTable();

        $this->assertCount(1, $result);
        $this->assertSame('fe80::1', $result->first()->ip);
    }
}
