<?php

declare(strict_types=1);

namespace App\Services\Interfaces;

use App\Services\ValueObjects\PortTimeSeries;

interface PortErrorsInterface
{
    public function getPortErrors(string $device, string $ifName, float $start, float $end, ?int $step = null): PortTimeSeries;

    public function isAvailable(): bool;
}
