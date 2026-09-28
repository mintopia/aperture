<?php

declare(strict_types=1);

namespace App\Services\ValueObjects;

use App\Models\IpAddress;

readonly class DhcpLease
{
    public string $ip;

    public ?string $expires;

    public function __construct(
        string $ip,
        public ?string $mac,
        public string $hostname,
        ?string $expires,
    ) {
        $this->ip = IpAddress::normalize($ip);
        // Cisco reports manual bindings as "Infinite"; blank or non-expiring means no expiry.
        $trimmed = trim((string) $expires);
        $this->expires = $trimmed === '' || strcasecmp($trimmed, 'infinite') === 0 ? null : $trimmed;
    }
}
