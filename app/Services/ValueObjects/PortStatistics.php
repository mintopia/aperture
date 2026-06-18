<?php

declare(strict_types=1);

namespace App\Services\ValueObjects;

readonly class PortStatistics
{
    public function __construct(
        public int $inBytes,
        public int $outBytes,
        public int $inErrors,
        public int $outErrors,
    ) {}
}
