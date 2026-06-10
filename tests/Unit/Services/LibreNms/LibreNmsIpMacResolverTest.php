<?php

declare(strict_types=1);

namespace Tests\Unit\Services\LibreNms;

use App\Services\LibreNms\LibreNmsIpMacResolver;
use App\Services\LibreNms\LibreNmsService;
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

    public function test_get_arp_table_normalizes_ipv6_addresses_to_lowercase(): void
    {
        $this->libreNms->shouldReceive('getArpTable')
            ->once()
            ->andReturn(collect());

        $this->libreNms->shouldReceive('getIpv6Neighbors')
            ->once()
            ->andReturn(collect([
                new ArpEntry(ip: '2001:DB8::ABCD', mac: 'aa:bb:cc:dd:ee:01'),
            ]));

        $result = $this->resolver->getArpTable();

        $this->assertCount(1, $result);
        $this->assertSame('2001:db8::abcd', $result->first()->ip);
    }

    public function test_get_arp_table_deduplicates_ipv6_entries_differing_only_in_case(): void
    {
        $arpEntries = collect([
            new ArpEntry(ip: '2001:db8::abcd', mac: 'aa:bb:cc:dd:ee:01'),
        ]);

        // Same neighbor reported again with uppercase hex digits
        $ipv6Entries = collect([
            new ArpEntry(ip: '2001:DB8::ABCD', mac: 'aa:bb:cc:dd:ee:01'),
        ]);

        $this->libreNms->shouldReceive('getArpTable')
            ->once()
            ->andReturn($arpEntries);

        $this->libreNms->shouldReceive('getIpv6Neighbors')
            ->once()
            ->andReturn($ipv6Entries);

        $result = $this->resolver->getArpTable();

        $this->assertCount(1, $result);
        $this->assertSame('2001:db8::abcd', $result->first()->ip);
        $this->assertSame('aa:bb:cc:dd:ee:01', $result->first()->mac);
    }

    public function test_get_arp_table_leaves_ipv4_addresses_byte_identical(): void
    {
        $this->libreNms->shouldReceive('getArpTable')
            ->once()
            ->andReturn(collect([
                new ArpEntry(ip: '192.168.1.50', mac: 'aa:bb:cc:dd:ee:05'),
            ]));

        $this->libreNms->shouldReceive('getIpv6Neighbors')
            ->once()
            ->andReturn(collect());

        $result = $this->resolver->getArpTable();

        $this->assertCount(1, $result);
        $this->assertSame('192.168.1.50', $result->first()->ip);
        $this->assertSame('aa:bb:cc:dd:ee:05', $result->first()->mac);
    }

    public function test_get_arp_table_preserves_mac_handling_when_normalizing_ipv6(): void
    {
        $this->libreNms->shouldReceive('getArpTable')
            ->once()
            ->andReturn(collect());

        // Same IPv6 address (differing in case) with different MACs must NOT dedupe
        $this->libreNms->shouldReceive('getIpv6Neighbors')
            ->once()
            ->andReturn(collect([
                new ArpEntry(ip: '2001:DB8::1', mac: 'AA:BB:CC:DD:EE:01'),
                new ArpEntry(ip: '2001:db8::1', mac: 'aa:bb:cc:dd:ee:02'),
            ]));

        $result = $this->resolver->getArpTable();

        $this->assertCount(2, $result);
        $this->assertSame('2001:db8::1', $result->get(0)->ip);
        $this->assertSame('AA:BB:CC:DD:EE:01', $result->get(0)->mac);
        $this->assertSame('2001:db8::1', $result->get(1)->ip);
        $this->assertSame('aa:bb:cc:dd:ee:02', $result->get(1)->mac);
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
