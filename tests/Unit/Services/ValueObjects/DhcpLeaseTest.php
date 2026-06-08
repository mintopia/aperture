<?php

declare(strict_types=1);

namespace Tests\Unit\Services\ValueObjects;

use App\Services\ValueObjects\DhcpLease;
use PHPUnit\Framework\TestCase;

class DhcpLeaseTest extends TestCase
{
    public function test_dhcp_lease_allows_nullable_mac(): void
    {
        $lease = new DhcpLease(ip: '2001:db8::1', mac: null, hostname: 'host', expires: '2026-06-08');
        $this->assertNull($lease->mac);
    }
}
