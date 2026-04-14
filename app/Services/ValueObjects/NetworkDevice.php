<?php

declare(strict_types=1);

namespace App\Services\ValueObjects;

readonly class NetworkDevice
{
    public function __construct(
        public string $hostname,
        public string $ip,
        public string $type,
    ) {}
}
