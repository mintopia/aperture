<?php

declare(strict_types=1);

namespace App\Services\ValueObjects;

readonly class PortDetail
{
    public function __construct(
        public string $hostname,
        public string $interface,
        public string $status,
        public string $adminStatus,
        public int $speed,
    ) {}
}
