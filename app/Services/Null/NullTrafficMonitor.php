<?php

declare(strict_types=1);

namespace App\Services\Null;

use App\Services\Interfaces\TrafficMonitorInterface;
use App\Services\ValueObjects\AggregateStats;
use App\Services\ValueObjects\TopTalker;
use App\Services\ValueObjects\UserBandwidth;
use Illuminate\Support\Collection;

class NullTrafficMonitor implements TrafficMonitorInterface
{
    public function getUserBandwidth(string|array $ipAddress, string $range = '24h'): UserBandwidth
    {
        return new UserBandwidth(
            received: 0,
            sent: 0,
            timestamps: [],
            download: [],
            upload: [],
        );
    }

    public function getAggregateStats(): AggregateStats
    {
        return new AggregateStats(
            totalUsers: 0,
            totalDevices: 0,
            totalBandwidth: 0,
        );
    }

    /** @return Collection<int, TopTalker> */
    public function getTopTalkers(int $limit = 10): Collection
    {
        return collect();
    }
}
