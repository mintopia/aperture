<?php

declare(strict_types=1);

namespace App\Services\ValueObjects;

readonly class PortTimeSeries
{
    /**
     * @param  array<int, array{timestamp: float, value: float}>  $in
     * @param  array<int, array{timestamp: float, value: float}>  $out
     */
    public function __construct(
        public array $in,
        public array $out,
    ) {}
}
