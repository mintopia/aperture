<?php

declare(strict_types=1);

namespace App\Services\Prometheus;

use App\Services\Interfaces\PortErrorsInterface;
use App\Services\ValueObjects\PortTimeSeries;

class PrometheusPortErrors implements PortErrorsInterface
{
    public function __construct(
        protected PrometheusService $prometheus,
    ) {}

    public function getPortErrors(string $device, string $ifName, float $start, float $end, ?int $step = null): PortTimeSeries
    {
        $data = $this->prometheus->getPortErrors($device, $ifName, $start, $end, $step);

        return new PortTimeSeries(in: $data['in'], out: $data['out']);
    }

    public function isAvailable(): bool
    {
        return $this->prometheus->isAvailable();
    }
}
