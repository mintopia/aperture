<?php

declare(strict_types=1);

namespace Tests\Unit\Services\Kea;

use App\Services\Interfaces\DhcpInterface;
use App\Services\Interfaces\IpMacResolverInterface;
use App\Services\Kea\KeaIpMacResolver;
use App\Services\ValueObjects\DhcpLease;
use App\Services\ValueObjects\DhcpSnapshot;
use App\Services\ValueObjects\IpMacEntry;
use Illuminate\Support\Collection;
use Tests\TestCase;

class KeaIpMacResolverTest extends TestCase
{
    /** @param  list<DhcpLease>  $leases */
    private function resolverWith(array $leases): KeaIpMacResolver
    {
        $kea = new class(DhcpSnapshot::create(collect($leases), collect())) implements DhcpInterface
        {
            public function __construct(private readonly DhcpSnapshot $snapshot) {}

            public function snapshot(): DhcpSnapshot
            {
                return $this->snapshot;
            }

            public function getLease(string $ipAddress): ?DhcpLease
            {
                return null;
            }
        };

        return new KeaIpMacResolver($kea);
    }

    public function test_implements_ip_mac_resolver_interface(): void
    {
        $this->assertInstanceOf(IpMacResolverInterface::class, new KeaIpMacResolver);
    }

    public function test_returns_empty_collection_when_kea_is_not_configured(): void
    {
        $table = (new KeaIpMacResolver)->getIpMacTable();

        $this->assertInstanceOf(Collection::class, $table);
        $this->assertTrue($table->isEmpty());
    }

    public function test_reads_ipv4_and_ipv6_leases_live_from_kea(): void
    {
        $table = $this->resolverWith([
            new DhcpLease('10.0.0.5', 'AA:BB:CC:DD:EE:01', null, null),
            new DhcpLease('2001:db8::1', 'AA:BB:CC:DD:EE:02', null, null),
        ])->getIpMacTable();

        $this->assertCount(2, $table);
        $this->assertContainsOnlyInstancesOf(IpMacEntry::class, $table);
        $this->assertSame(['10.0.0.5', '2001:db8::1'], $table->pluck('ip')->all());
        $this->assertSame(['AA:BB:CC:DD:EE:01', 'AA:BB:CC:DD:EE:02'], $table->pluck('mac')->all());
    }

    public function test_excludes_leases_without_mac_and_dedupes(): void
    {
        $table = $this->resolverWith([
            new DhcpLease('10.0.0.5', null, null, null),
            new DhcpLease('10.0.0.6', 'AA:BB:CC:DD:EE:03', null, null),
            new DhcpLease('10.0.0.6', 'AA:BB:CC:DD:EE:03', null, null),
        ])->getIpMacTable();

        $this->assertCount(1, $table);
        $this->assertSame('10.0.0.6', $table->first()->ip);
    }
}
