<?php

declare(strict_types=1);

namespace Tests\Unit\Services\ValueObjects;

use App\Services\ValueObjects\ArpEntry;
use App\Services\ValueObjects\DhcpLease;
use PHPUnit\Framework\TestCase;

class ValueObjectIpNormalizationTest extends TestCase
{
    public function test_dhcp_lease_lowercases_ipv6_address(): void
    {
        $lease = new DhcpLease(ip: '2A0F:85C1:D91:2100::1', mac: 'AA:BB:CC:DD:EE:01', hostname: 'host', expires: '2026-06-11');

        $this->assertSame('2a0f:85c1:d91:2100::1', $lease->ip);
    }

    public function test_dhcp_lease_leaves_ipv4_address_unchanged(): void
    {
        $lease = new DhcpLease(ip: '10.0.0.50', mac: 'AA:BB:CC:DD:EE:01', hostname: 'host', expires: '2026-06-11');

        $this->assertSame('10.0.0.50', $lease->ip);
    }

    public function test_arp_entry_lowercases_ipv6_address(): void
    {
        $entry = new ArpEntry(ip: '2A0F:85C1:D91:2100::1', mac: 'AA:BB:CC:DD:EE:02');

        $this->assertSame('2a0f:85c1:d91:2100::1', $entry->ip);
    }

    public function test_arp_entry_leaves_ipv4_address_unchanged(): void
    {
        $entry = new ArpEntry(ip: '10.0.0.51', mac: 'AA:BB:CC:DD:EE:02');

        $this->assertSame('10.0.0.51', $entry->ip);
    }
}
