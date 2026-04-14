<?php

declare(strict_types=1);

namespace App\Services\ValueObjects;

readonly class ArpEntry
{
    public function __construct(
        public string $ip,
        public string $mac,
    ) {}
}
