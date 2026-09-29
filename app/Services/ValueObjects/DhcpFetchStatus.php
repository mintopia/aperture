<?php

declare(strict_types=1);

namespace App\Services\ValueObjects;

readonly class DhcpFetchStatus
{
    public function __construct(
        public bool $leases = true,
        public bool $ranges = true,
    ) {}

    public function rangesUsable(): bool
    {
        return $this->leases && $this->ranges;
    }
}
