<?php

declare(strict_types=1);

namespace App\Services\Interfaces;

use App\Services\ValueObjects\DhcpLease;
use App\Services\ValueObjects\DhcpPoolStatus;
use App\Services\ValueObjects\DhcpRange;
use Illuminate\Support\Collection;

interface DhcpInterface
{
    /** @param  'ipv4'|'ipv6'  $family */
    public function getPoolStatus(string $family = 'ipv4'): DhcpPoolStatus;

    /** @return Collection<int, DhcpLease> */
    public function getLeases(): Collection;

    public function getLease(string $ipAddress): ?DhcpLease;

    /** @return Collection<int, DhcpRange> */
    public function getRanges(): Collection;

    /** @return array{ipv4: bool, ipv6: bool, ...} */
    public function getFetchStatus(): array;

    public function resetSnapshot(): void;
}
