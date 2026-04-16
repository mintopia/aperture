<?php

declare(strict_types=1);

namespace App\Services\Dhcp;

use App\Services\Interfaces\DhcpInterface;
use App\Services\ValueObjects\DhcpLease;
use App\Services\ValueObjects\DhcpPoolStatus;
use App\Services\ValueObjects\DhcpRange;
use Illuminate\Support\Collection;

class NullDhcpService implements DhcpInterface
{
    public function getPoolStatus(): DhcpPoolStatus
    {
        return new DhcpPoolStatus(total: 0, used: 0, available: 0, utilisation: 0.0);
    }

    /** @return Collection<int, DhcpLease> */
    public function getLeases(): Collection
    {
        return collect();
    }

    public function getLease(string $ipAddress): ?DhcpLease
    {
        return null;
    }

    /** @return Collection<int, DhcpRange> */
    public function getRanges(): Collection
    {
        return collect();
    }
}
