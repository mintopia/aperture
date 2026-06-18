<?php

declare(strict_types=1);

namespace Tests\Unit\Services\VyOs;

use App\Services\VyOs\VyOsClient;
use App\Services\VyOs\VyOsIpMacResolver;
use Mockery;
use Mockery\MockInterface;
use RuntimeException;
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

    private function neighborTableText(array $rows = []): string
    {
        $columns = ['Address', 'Interface', 'Link layer address', 'State'];
        $dataRows = [];

        foreach ($rows as $row) {
            $dataRows[] = [
                $row['ip'],
                $row['iface'] ?? 'eth0',
                $row['mac'] ?? '',
                $row['state'] ?? 'STALE',
            ];
        }

        $widths = array_map('strlen', $columns);

        foreach ($dataRows as $row) {
            foreach ($row as $i => $value) {
                $widths[$i] = max($widths[$i], strlen($value));
            }
        }

        $lines = [];
        $lines[] = implode('  ', array_map(fn (string $col, int $w): string => str_pad($col, $w), $columns, $widths));
        $lines[] = implode('  ', array_map(fn (int $w): string => str_repeat('-', $w), $widths));

        foreach ($dataRows as $row) {
            $lines[] = implode('  ', array_map(fn (string $val, int $w): string => str_pad($val, $w), $row, $widths));
        }

        return implode("\n", $lines)."\n";
    }

    public function test_get_arp_table_returns_ipv4_neighbors(): void
    {
        $this->client->shouldReceive('showText')
            ->with(['ip', 'neighbors'])
            ->once()
            ->andReturn($this->neighborTableText([
                ['ip' => '192.168.1.100', 'mac' => 'aa:bb:cc:dd:ee:ff', 'state' => 'REACHABLE'],
                ['ip' => '192.168.1.101', 'mac' => '11:22:33:44:55:66', 'state' => 'STALE'],
            ]));

        $this->client->shouldReceive('showText')
            ->with(['ipv6', 'neighbors'])
            ->once()
            ->andReturn('');

        $result = $this->resolver->getArpTable();

        $this->assertCount(2, $result);
        $this->assertSame('192.168.1.100', $result->get(0)->ip);
        $this->assertSame('aa:bb:cc:dd:ee:ff', $result->get(0)->mac);
        $this->assertSame('192.168.1.101', $result->get(1)->ip);
    }

    public function test_get_arp_table_returns_ipv6_neighbors(): void
    {
        $this->client->shouldReceive('showText')
            ->with(['ip', 'neighbors'])
            ->once()
            ->andReturn('');

        $this->client->shouldReceive('showText')
            ->with(['ipv6', 'neighbors'])
            ->once()
            ->andReturn($this->neighborTableText([
                ['ip' => 'fe80::1', 'mac' => 'aa:bb:cc:dd:ee:03', 'state' => 'REACHABLE'],
            ]));

        $result = $this->resolver->getArpTable();

        $this->assertCount(1, $result);
        $this->assertSame('fe80::1', $result->first()->ip);
        $this->assertSame('aa:bb:cc:dd:ee:03', $result->first()->mac);
    }

    public function test_get_arp_table_merges_ipv4_and_ipv6(): void
    {
        $this->client->shouldReceive('showText')
            ->with(['ip', 'neighbors'])
            ->once()
            ->andReturn($this->neighborTableText([
                ['ip' => '192.168.1.1', 'mac' => 'aa:bb:cc:dd:ee:01', 'state' => 'REACHABLE'],
            ]));

        $this->client->shouldReceive('showText')
            ->with(['ipv6', 'neighbors'])
            ->once()
            ->andReturn($this->neighborTableText([
                ['ip' => 'fe80::1', 'mac' => 'aa:bb:cc:dd:ee:02', 'state' => 'REACHABLE'],
            ]));

        $result = $this->resolver->getArpTable();

        $this->assertCount(2, $result);
        $ips = $result->pluck('ip')->all();
        $this->assertContains('192.168.1.1', $ips);
        $this->assertContains('fe80::1', $ips);
    }

    public function test_get_arp_table_deduplicates_by_ip_and_mac(): void
    {
        $this->client->shouldReceive('showText')
            ->with(['ip', 'neighbors'])
            ->once()
            ->andReturn($this->neighborTableText([
                ['ip' => '192.168.1.1', 'mac' => 'aa:bb:cc:dd:ee:01', 'state' => 'REACHABLE'],
            ]));

        $this->client->shouldReceive('showText')
            ->with(['ipv6', 'neighbors'])
            ->once()
            ->andReturn($this->neighborTableText([
                ['ip' => '192.168.1.1', 'iface' => 'eth1', 'mac' => 'aa:bb:cc:dd:ee:01', 'state' => 'REACHABLE'],
                ['ip' => 'fe80::1', 'mac' => 'aa:bb:cc:dd:ee:02', 'state' => 'REACHABLE'],
            ]));

        $result = $this->resolver->getArpTable();

        $this->assertCount(2, $result);
    }

    public function test_get_arp_table_returns_empty_collection_when_no_data(): void
    {
        $this->client->shouldReceive('showText')
            ->with(['ip', 'neighbors'])
            ->once()
            ->andReturn('');

        $this->client->shouldReceive('showText')
            ->with(['ipv6', 'neighbors'])
            ->once()
            ->andReturn('');

        $result = $this->resolver->getArpTable();

        $this->assertCount(0, $result);
    }

    public function test_get_arp_table_handles_ipv4_exception_gracefully(): void
    {
        $this->client->shouldReceive('showText')
            ->with(['ip', 'neighbors'])
            ->once()
            ->andThrow(new RuntimeException('Connection refused'));

        $this->client->shouldReceive('showText')
            ->with(['ipv6', 'neighbors'])
            ->once()
            ->andReturn($this->neighborTableText([
                ['ip' => 'fe80::1', 'mac' => 'aa:bb:cc:dd:ee:01', 'state' => 'REACHABLE'],
            ]));

        $result = $this->resolver->getArpTable();

        $this->assertCount(1, $result);
        $this->assertSame('fe80::1', $result->first()->ip);
    }

    public function test_get_arp_table_handles_ipv6_exception_gracefully(): void
    {
        $this->client->shouldReceive('showText')
            ->with(['ip', 'neighbors'])
            ->once()
            ->andReturn($this->neighborTableText([
                ['ip' => '192.168.1.1', 'mac' => 'aa:bb:cc:dd:ee:01', 'state' => 'REACHABLE'],
            ]));

        $this->client->shouldReceive('showText')
            ->with(['ipv6', 'neighbors'])
            ->once()
            ->andThrow(new RuntimeException('Connection refused'));

        $result = $this->resolver->getArpTable();

        $this->assertCount(1, $result);
        $this->assertSame('192.168.1.1', $result->first()->ip);
    }

    public function test_get_arp_table_skips_entries_without_mac(): void
    {
        $this->client->shouldReceive('showText')
            ->with(['ip', 'neighbors'])
            ->once()
            ->andReturn($this->neighborTableText([
                ['ip' => '192.168.1.1', 'mac' => 'aa:bb:cc:dd:ee:01', 'state' => 'REACHABLE'],
                ['ip' => '192.168.1.2', 'mac' => '', 'state' => 'FAILED'],
                ['ip' => '192.168.1.3', 'mac' => '', 'state' => 'FAILED'],
            ]));

        $this->client->shouldReceive('showText')
            ->with(['ipv6', 'neighbors'])
            ->once()
            ->andReturn('');

        $result = $this->resolver->getArpTable();

        $this->assertCount(1, $result);
        $this->assertSame('192.168.1.1', $result->first()->ip);
    }

    public function test_get_arp_table_normalises_mac_to_lowercase(): void
    {
        $this->client->shouldReceive('showText')
            ->with(['ip', 'neighbors'])
            ->once()
            ->andReturn($this->neighborTableText([
                ['ip' => '192.168.1.1', 'mac' => 'AA:BB:CC:DD:EE:FF', 'state' => 'REACHABLE'],
            ]));

        $this->client->shouldReceive('showText')
            ->with(['ipv6', 'neighbors'])
            ->once()
            ->andReturn('');

        $result = $this->resolver->getArpTable();

        $this->assertSame('aa:bb:cc:dd:ee:ff', $result->first()->mac);
    }
}
