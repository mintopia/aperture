<?php

declare(strict_types=1);

namespace App\Services\Interfaces;

use App\Services\ValueObjects\AggregateStats;
use App\Services\ValueObjects\TopTalker;
use App\Services\ValueObjects\UserBandwidth;
use Illuminate\Support\Collection;

interface TrafficMonitorInterface
{
    /** @param string|string[] $ipAddress */
    public function getUserBandwidth(string|array $ipAddress, string $range = '24h'): UserBandwidth;

    public function getAggregateStats(): AggregateStats;

    /** @return Collection<int, TopTalker> */
    public function getTopTalkers(int $limit = 10): Collection;
}
