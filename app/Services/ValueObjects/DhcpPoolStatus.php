<?php

declare(strict_types=1);

namespace App\Services\ValueObjects;

readonly class DhcpPoolStatus
{
    /**
     * @param  int|numeric-string  $total  numeric-string only when it exceeds PHP_INT_MAX (e.g. an IPv6 /64)
     * @param  int|numeric-string  $available
     */
    public function __construct(
        public int|string $total,
        public int $used,
        public int|string $available,
        public float $utilisation,
    ) {}
}
