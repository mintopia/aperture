<?php

declare(strict_types=1);

namespace App\Services\Prometheus;

use App\Services\Interfaces\PortBandwidthInterface;
use App\Services\ValueObjects\PortTimeSeries;

class PrometheusPortBandwidth implements PortBandwidthInterface
{
    public function __construct(
        protected PrometheusService $prometheus,
    ) {}

    public function getPortBandwidth(string $device, string $ifName, float $start, float $end, ?int $step = null): PortTimeSeries
    {
        $data = $this->prometheus->getPortBandwidth($device, $ifName, $start, $end, $step);

        return new PortTimeSeries(in: $data['in'], out: $data['out']);
    }

    public function isAvailable(): bool
    {
        return $this->prometheus->isAvailable();
    }
}
