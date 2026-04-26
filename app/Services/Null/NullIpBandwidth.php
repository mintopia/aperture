<?php

declare(strict_types=1);

namespace App\Services\Null;

use App\Services\Interfaces\IpBandwidthInterface;
use App\Services\ValueObjects\IpBandwidthResult;
use Illuminate\Support\Collection;

class NullIpBandwidth implements IpBandwidthInterface
{
    public function getIpBandwidth(string|array $ipAddress, string $range = '24h'): IpBandwidthResult
    {
        return new IpBandwidthResult(received: 0, sent: 0, timestamps: [], download: [], upload: []);
    }

    public function getTotalBandwidth(string $range = '24h'): IpBandwidthResult
    {
        return new IpBandwidthResult(received: 0, sent: 0, timestamps: [], download: [], upload: []);
    }

    public function getTopTalkers(int $limit = 10, string $range = '1m'): Collection
    {
        return collect();
    }
}
