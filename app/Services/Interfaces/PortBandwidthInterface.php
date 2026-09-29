<?php

declare(strict_types=1);

namespace App\Services\Interfaces;

use App\Services\ValueObjects\PortTimeSeries;

interface PortBandwidthInterface
{
    public function getPortBandwidth(string $device, string $ifName, float $start, float $end, ?int $step = null): PortTimeSeries;

    public function isAvailable(): bool;
}
