<?php

declare(strict_types=1);

namespace App\Services\ValueObjects;

readonly class ResolvedPort
{
    public function __construct(
        public string $ip,
        public string $mac,
        public string $port,
        public string $switch,
    ) {}
}
