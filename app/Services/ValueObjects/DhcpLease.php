<?php

declare(strict_types=1);

namespace App\Services\ValueObjects;

use App\Models\IpAddress;

readonly class DhcpLease
{
    public string $ip;

    public function __construct(
        string $ip,
        public ?string $mac,
        public string $hostname,
        public string $expires,
    ) {
        $this->ip = IpAddress::normalize($ip);
    }
}
