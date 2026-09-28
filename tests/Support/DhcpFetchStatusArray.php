<?php

declare(strict_types=1);

namespace Tests\Support;

use App\Enums\AddressFamily;
use App\Services\ValueObjects\DhcpSnapshot;

final class DhcpFetchStatusArray
{
    /** @return array{ipv4: bool, ipv6: bool, ipv4_ranges: bool, ipv6_ranges: bool} */
    public static function of(DhcpSnapshot $snapshot): array
    {
        return [
            'ipv4' => $snapshot->fetchStatus(AddressFamily::IPv4)->leases,
            'ipv6' => $snapshot->fetchStatus(AddressFamily::IPv6)->leases,
            'ipv4_ranges' => $snapshot->fetchStatus(AddressFamily::IPv4)->ranges,
            'ipv6_ranges' => $snapshot->fetchStatus(AddressFamily::IPv6)->ranges,
        ];
    }
}
