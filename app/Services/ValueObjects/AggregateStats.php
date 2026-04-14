<?php

declare(strict_types=1);

namespace App\Services\ValueObjects;

readonly class AggregateStats
{
    public function __construct(
        public int $totalUsers,
        public int $totalDevices,
        public int $totalBandwidth,
    ) {}
}
