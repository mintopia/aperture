<?php

declare(strict_types=1);

namespace App\Services\Null;

use App\Services\Interfaces\PortErrorsInterface;
use App\Services\ValueObjects\PortTimeSeries;

class NullPortErrors implements PortErrorsInterface
{
    public function getPortErrors(string $device, string $ifName, float $start, float $end, ?int $step = null): PortTimeSeries
    {
        return new PortTimeSeries(in: [], out: []);
    }

    public function isAvailable(): bool
    {
        return false;
    }
}
