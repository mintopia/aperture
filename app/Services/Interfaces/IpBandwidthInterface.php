<?php

declare(strict_types=1);

namespace App\Services\Interfaces;

use App\Services\ValueObjects\IpBandwidthResult;
use App\Services\ValueObjects\TopTalker;
use Illuminate\Support\Collection;

interface IpBandwidthInterface
{
    /** @param string|string[] $ipAddress */
    public function getIpBandwidth(string|array $ipAddress, string $range = '24h'): IpBandwidthResult;

    public function getTotalBandwidth(string $range = '24h'): IpBandwidthResult;

    /** @return Collection<int, TopTalker> */
    public function getTopTalkers(int $limit = 10, string $range = '1m'): Collection;
}
