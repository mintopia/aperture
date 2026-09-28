<?php

declare(strict_types=1);

namespace App\Services\ValueObjects;

use App\Models\IpAddress;

readonly class DhcpLease
{
    public string $ip;

    public ?string $mac;

    public ?string $hostname;

    public ?string $expires;

    public function __construct(
        string $ip,
        ?string $mac,
        ?string $hostname,
        ?string $expires,
    ) {
        $this->ip = IpAddress::normalize($ip);
        $this->mac = $mac === null || trim($mac) === '' ? null : trim($mac);
        $this->hostname = $hostname === null || trim($hostname) === '' ? null : trim($hostname);
        // Cisco reports manual bindings as "Infinite"; blank or non-expiring means no expiry.
        $trimmed = trim((string) $expires);
        $this->expires = $trimmed === '' || strcasecmp($trimmed, 'infinite') === 0 ? null : $trimmed;
    }
}
