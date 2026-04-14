<?php

declare(strict_types=1);

namespace App\Services\Interfaces;

use App\Services\ValueObjects\DhcpLease;
use App\Services\ValueObjects\DhcpPoolStatus;
use Illuminate\Support\Collection;

interface DhcpInterface
{
    public function getPoolStatus(): DhcpPoolStatus;

    /** @return Collection<int, DhcpLease> */
    public function getLeases(): Collection;

    public function getLease(string $ipAddress): ?DhcpLease;
}
