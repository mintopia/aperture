<?php

declare(strict_types=1);

namespace App\Services\Interfaces;

use Illuminate\Support\Collection;

interface TrafficMonitorInterface
{
    /**
     * @return array{received: int, sent: int, timestamps: array<int, string>, download: array<int, int>, upload: array<int, int>}
     */
    public function getUserBandwidth(string $ipAddress, string $range = '24h'): array;

    /**
     * @return array{totalUsers: int, totalDevices: int, totalBandwidth: int}
     */
    public function getAggregateStats(): array;

    /**
     * @return Collection<int, array{ip: string, received: int, sent: int, nickname: string|null}>
     */
    public function getTopTalkers(int $limit = 10): Collection;
}
