<?php

declare(strict_types=1);

namespace App\Services\ValueObjects;

readonly class DhcpPoolStatus
{
    public function __construct(
        public int $total,
        public int $used,
        public int $available,
        public float $utilisation,
    ) {}
}
