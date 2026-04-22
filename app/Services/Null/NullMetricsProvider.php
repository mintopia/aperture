<?php

declare(strict_types=1);

namespace App\Services\Null;

use App\Services\Interfaces\MetricsProviderInterface;

class NullMetricsProvider implements MetricsProviderInterface
{
    public function query(string $promql, ?float $time = null): array
    {
        return [];
    }

    public function queryRange(string $promql, float $start, float $end, ?int $step = null): array
    {
        return [];
    }

    public function getPortBandwidth(string $device, string $ifName, float $start, float $end, ?int $step = null): array
    {
        return ['in' => [], 'out' => []];
    }

    public function getPortErrors(string $device, string $ifName, float $start, float $end, ?int $step = null): array
    {
        return ['in' => [], 'out' => []];
    }

    public function getDeviceBandwidth(string $device, float $start, float $end, ?int $step = null): array
    {
        return ['in' => [], 'out' => []];
    }

    public function isAvailable(): bool
    {
        return false;
    }
}
