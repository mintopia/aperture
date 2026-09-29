<?php

declare(strict_types=1);

namespace App\Services\ValueObjects;

readonly class PortStatus
{
    public function __construct(
        public string $interface,
        public string $status,
        public string $speed,
        public string $duplex = '',
        public string $vlan = '',
        public string $description = '',
        public string $switchportMode = '',
        public string $adminStatus = 'up',
    ) {}
}
