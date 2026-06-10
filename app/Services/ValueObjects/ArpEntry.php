<?php

declare(strict_types=1);

namespace App\Services\ValueObjects;

use App\Models\IpAddress;

readonly class ArpEntry
{
    public string $ip;

    public function __construct(
        string $ip,
        public string $mac,
    ) {
        $this->ip = IpAddress::normalize($ip);
    }
}
