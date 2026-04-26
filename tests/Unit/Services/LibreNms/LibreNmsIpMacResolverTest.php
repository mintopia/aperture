<?php

declare(strict_types=1);

namespace Tests\Unit\Services\LibreNms;

use App\Services\LibreNms\LibreNmsIpMacResolver;
use App\Services\LibreNmsService;
use App\Services\ValueObjects\ArpEntry;
use Mockery;
use Mockery\MockInterface;
use Tests\TestCase;

class LibreNmsIpMacResolverTest extends TestCase
{
    private LibreNmsService&MockInterface $libreNms;

    private LibreNmsIpMacResolver $resolver;

    protected function setUp(): void
    {
        parent::setUp();

        $this->libreNms = Mockery::mock(LibreNmsService::class);
        $this->resolver = new LibreNmsIpMacResolver(
            libreNms: $this->libreNms,
        );
    }

    public function test_get_arp_table_merges_ipv4_arp_and_ipv6_neighbors(): void
    {
        $arpEntries = collect([
            new ArpEntry(ip: '192.168.1.1', mac: 'aa:bb:cc:dd:ee:01'),
            new ArpEntry(ip: '192.168.1.2', mac: 'aa:bb:cc:dd:ee:02'),
        ]);

        $ipv6Entries = collect([
            new ArpEntry(ip: 'fe80::1', mac: 'aa:bb:cc:dd:ee:03'),
            new ArpEntry(ip: 'fe80::2', mac: 'aa:bb:cc:dd:ee:04'),
        ]);

        $this->libreNms->shouldReceive('getArpTable')
            ->once()
            ->andReturn($arpEntries);

        $this->libreNms->shouldReceive('getIpv6Neighbors')
            ->once()
            ->andReturn($ipv6Entries);

        $result = $this->resolver->getArpTable();

        $this->assertCount(4, $result);
        $this->assertSame('192.168.1.1', $result->get(0)->ip);
        $this->assertSame('192.168.1.2', $result->get(1)->ip);
        $this->assertSame('fe80::1', $result->get(2)->ip);
        $this->assertSame('fe80::2', $result->get(3)->ip);
    }

    public function test_get_arp_table_deduplicates_by_ip_and_mac(): void
    {
        $arpEntries = collect([
            new ArpEntry(ip: '192.168.1.1', mac: 'aa:bb:cc:dd:ee:01'),
            new ArpEntry(ip: '192.168.1.2', mac: 'aa:bb:cc:dd:ee:02'),
        ]);

        // Same entry appears in both arp and ipv6 neighbors (duplicate)
        $ipv6Entries = collect([
            new ArpEntry(ip: '192.168.1.1', mac: 'aa:bb:cc:dd:ee:01'),
            new ArpEntry(ip: 'fe80::1', mac: 'aa:bb:cc:dd:ee:03'),
        ]);

        $this->libreNms->shouldReceive('getArpTable')
            ->once()
            ->andReturn($arpEntries);

        $this->libreNms->shouldReceive('getIpv6Neighbors')
            ->once()
            ->andReturn($ipv6Entries);

        $result = $this->resolver->getArpTable();

        $this->assertCount(3, $result);
        $ips = $result->pluck('ip')->all();
        $this->assertContains('192.168.1.1', $ips);
        $this->assertContains('192.168.1.2', $ips);
        $this->assertContains('fe80::1', $ips);
    }

    public function test_get_arp_table_returns_empty_collection_when_no_data(): void
    {
        $this->libreNms->shouldReceive('getArpTable')
            ->once()
            ->andReturn(collect());

        $this->libreNms->shouldReceive('getIpv6Neighbors')
            ->once()
            ->andReturn(collect());

        $result = $this->resolver->getArpTable();

        $this->assertCount(0, $result);
    }

    public function test_get_arp_table_returns_only_arp_when_ipv6_empty(): void
    {
        $arpEntries = collect([
            new ArpEntry(ip: '10.0.0.1', mac: 'de:ad:be:ef:00:01'),
        ]);

        $this->libreNms->shouldReceive('getArpTable')
            ->once()
            ->andReturn($arpEntries);

        $this->libreNms->shouldReceive('getIpv6Neighbors')
            ->once()
            ->andReturn(collect());

        $result = $this->resolver->getArpTable();

        $this->assertCount(1, $result);
        $this->assertSame('10.0.0.1', $result->first()->ip);
    }
}
