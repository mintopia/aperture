<?php

declare(strict_types=1);

namespace App\Services\ValueObjects;

readonly class DhcpLease
{
    public function __construct(
        public string $ip,
        public ?string $mac,
        public string $hostname,
        public string $expires,
    ) {}
}
