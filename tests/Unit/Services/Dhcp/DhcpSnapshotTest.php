<?php

declare(strict_types=1);

namespace Tests\Unit\Services\Dhcp;

use App\Enums\AddressFamily;
use App\Services\ValueObjects\DhcpFetchStatus;
use App\Services\ValueObjects\DhcpPoolStatus;
use App\Services\ValueObjects\DhcpRange;
use App\Services\ValueObjects\DhcpSnapshot;
use PHPUnit\Framework\TestCase;

class DhcpSnapshotTest extends TestCase
{
    public function test_from_ip_detects_family(): void
    {
        $this->assertSame(AddressFamily::IPv4, AddressFamily::fromIp('10.0.0.1'));
        $this->assertSame(AddressFamily::IPv6, AddressFamily::fromIp('2001:db8::1'));
    }

    public function test_empty_snapshot_is_successful_and_has_zero_pool_status(): void
    {
        $snapshot = DhcpSnapshot::empty();

        $this->assertTrue($snapshot->leases->isEmpty());
        $this->assertTrue($snapshot->fetchStatus(AddressFamily::IPv4)->rangesUsable());
        $this->assertTrue($snapshot->fetchStatus(AddressFamily::IPv6)->rangesUsable());
        $this->assertSame(0, $snapshot->poolStatus(AddressFamily::IPv6)->total);
    }

    public function test_pool_status_is_derived_from_ranges_unless_overridden(): void
    {
        $range = new DhcpRange('lan', AddressFamily::IPv4, null, '10.0.0.1', '10.0.0.10', null, null, null, '10', 5, 0.5);
        $override = new DhcpPoolStatus(total: 99, used: 1, available: 98, utilisation: 0.01);

        $derived = DhcpSnapshot::create(collect(), collect([$range]));
        $overridden = DhcpSnapshot::create(collect(), collect([$range]), poolOverrides: [AddressFamily::IPv4->value => $override]);

        $this->assertSame(10, $derived->poolStatus(AddressFamily::IPv4)->total);
        $this->assertSame($override, $overridden->poolStatus(AddressFamily::IPv4));
    }

    public function test_ranges_are_unusable_when_lease_fetch_failed(): void
    {
        $this->assertFalse((new DhcpFetchStatus(leases: false))->rangesUsable());
        $this->assertFalse((new DhcpFetchStatus(ranges: false))->rangesUsable());
    }
}
