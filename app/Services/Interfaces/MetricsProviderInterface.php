<?php

declare(strict_types=1);

namespace App\Services\Interfaces;

interface MetricsProviderInterface
{
    /**
     * Execute an instant PromQL query.
     *
     * @return array<string, mixed>
     */
    public function query(string $promql, ?float $time = null): array;

    /**
     * Execute a range PromQL query.
     *
     * @return array<string, mixed>
     */
    public function queryRange(string $promql, float $start, float $end, ?int $step = null): array;

    /**
     * Get port bandwidth data (in/out octets rate).
     *
     * @return array{in: array<int, array{timestamp: float, value: float}>, out: array<int, array{timestamp: float, value: float}>}
     */
    public function getPortBandwidth(string $device, string $ifName, float $start, float $end, ?int $step = null): array;

    /**
     * Get port error data (in/out errors rate).
     *
     * @return array{in: array<int, array{timestamp: float, value: float}>, out: array<int, array{timestamp: float, value: float}>}
     */
    public function getPortErrors(string $device, string $ifName, float $start, float $end, ?int $step = null): array;

    /**
     * Get aggregate bandwidth across all interfaces of a device.
     *
     * @return array{in: array<int, array{timestamp: float, value: float}>, out: array<int, array{timestamp: float, value: float}>}
     */
    public function getDeviceBandwidth(string $device, float $start, float $end, ?int $step = null): array;

    /**
     * Check if the provider is configured and available.
     */
    public function isAvailable(): bool;
}
