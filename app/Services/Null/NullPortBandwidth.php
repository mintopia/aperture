<?php

declare(strict_types=1);

namespace App\Services\Null;

use App\Services\Interfaces\PortBandwidthInterface;
use App\Services\ValueObjects\PortTimeSeries;

class NullPortBandwidth implements PortBandwidthInterface
{
    public function getPortBandwidth(string $device, string $ifName, float $start, float $end, ?int $step = null): PortTimeSeries
    {
        return new PortTimeSeries(in: [], out: []);
    }

    public function isAvailable(): bool
    {
        return false;
    }
}
