<?php

declare(strict_types=1);

namespace App\Services\Interfaces;

use Illuminate\Support\Collection;

interface DhcpInterface
{
    /**
     * @return array{total: int, used: int, available: int, utilisation: float}
     */
    public function getPoolStatus(): array;

    /**
     * @return Collection<int, array{ip: string, mac: string, hostname: string, expires: string}>
     */
    public function getLeases(): Collection;

    /**
     * @return array{ip: string, mac: string, hostname: string, expires: string}|null
     */
    public function getLease(string $ipAddress): ?array;
}
