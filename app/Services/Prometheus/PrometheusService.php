<?php

declare(strict_types=1);

namespace App\Services\Prometheus;

use App\Services\Interfaces\MetricsProviderInterface;
use Illuminate\Http\Client\PendingRequest;
use Illuminate\Support\Facades\Http;

class PrometheusService implements MetricsProviderInterface
{
    public function __construct(
        protected string $endpoint,
        protected string $bearerToken = '',
        protected bool $verifySsl = true,
        protected int $defaultStep = 60,
    ) {}

    public function query(string $promql, ?float $time = null): array
    {
        $params = ['query' => $promql];
        if ($time !== null) {
            $params['time'] = $time;
        }

        $response = $this->http()->get($this->url('/api/v1/query'), $params);
        $response->throw();

        return $response->json('data', []);
    }

    public function queryRange(string $promql, float $start, float $end, ?int $step = null): array
    {
        $response = $this->http()->get($this->url('/api/v1/query_range'), [
            'query' => $promql,
            'start' => $start,
            'end' => $end,
            'step' => $step ?? $this->defaultStep,
        ]);
        $response->throw();

        return $response->json('data', []);
    }

    public function getPortBandwidth(string $device, string $ifName, float $start, float $end, ?int $step = null): array
    {
        $escapedDevice = $this->escapePromQLLabelValue($device);
        $escapedIfName = $this->escapePromQLLabelValue($ifName);

        $inQuery = sprintf(
            'rate(ifHCInOctets{instance=~"%s.*",ifName="%s"}[5m]) * 8 or rate(ifInOctets{instance=~"%s.*",ifName="%s"}[5m]) * 8',
            $escapedDevice,
            $escapedIfName,
            $escapedDevice,
            $escapedIfName,
        );
        $outQuery = sprintf(
            'rate(ifHCOutOctets{instance=~"%s.*",ifName="%s"}[5m]) * 8 or rate(ifOutOctets{instance=~"%s.*",ifName="%s"}[5m]) * 8',
            $escapedDevice,
            $escapedIfName,
            $escapedDevice,
            $escapedIfName,
        );

        return [
            'in' => $this->fetchTimeSeries($inQuery, $start, $end, $step),
            'out' => $this->fetchTimeSeries($outQuery, $start, $end, $step),
        ];
    }

    public function getPortErrors(string $device, string $ifName, float $start, float $end, ?int $step = null): array
    {
        $escapedDevice = $this->escapePromQLLabelValue($device);
        $escapedIfName = $this->escapePromQLLabelValue($ifName);

        $inQuery = sprintf('rate(ifInErrors{instance=~"%s.*",ifName="%s"}[5m])', $escapedDevice, $escapedIfName);
        $outQuery = sprintf('rate(ifOutErrors{instance=~"%s.*",ifName="%s"}[5m])', $escapedDevice, $escapedIfName);

        return [
            'in' => $this->fetchTimeSeries($inQuery, $start, $end, $step),
            'out' => $this->fetchTimeSeries($outQuery, $start, $end, $step),
        ];
    }

    public function getDeviceBandwidth(string $device, float $start, float $end, ?int $step = null): array
    {
        $escapedDevice = $this->escapePromQLLabelValue($device);

        $inQuery = sprintf(
            'sum(rate(ifHCInOctets{instance=~"%s.*"}[5m])) * 8 or sum(rate(ifInOctets{instance=~"%s.*"}[5m])) * 8',
            $escapedDevice,
            $escapedDevice,
        );
        $outQuery = sprintf(
            'sum(rate(ifHCOutOctets{instance=~"%s.*"}[5m])) * 8 or sum(rate(ifOutOctets{instance=~"%s.*"}[5m])) * 8',
            $escapedDevice,
            $escapedDevice,
        );

        return [
            'in' => $this->fetchTimeSeries($inQuery, $start, $end, $step),
            'out' => $this->fetchTimeSeries($outQuery, $start, $end, $step),
        ];
    }

    public function isAvailable(): bool
    {
        return $this->endpoint !== '';
    }

    /**
     * Execute a range query and extract time series data points.
     *
     * @return array<int, array{timestamp: float, value: float}>
     */
    protected function fetchTimeSeries(string $promql, float $start, float $end, ?int $step = null): array
    {
        $data = $this->queryRange($promql, $start, $end, $step);

        $result = $data['result'] ?? [];
        if (empty($result)) {
            return [];
        }

        $values = $result[0]['values'] ?? [];

        return array_map(
            fn (array $point): array => [
                'timestamp' => (float) $point[0],
                'value' => (float) $point[1],
            ],
            $values,
        );
    }

    public function escapePromQLLabelValue(string $value): string
    {
        return str_replace(['\\', '"', "\n"], ['\\\\', '\\"', '\\n'], $value);
    }

    protected function http(): PendingRequest
    {
        $http = Http::withOptions([
            'verify' => $this->verifySsl,
        ])->timeout(30)->acceptJson();

        if ($this->bearerToken !== '') {
            $http = $http->withToken($this->bearerToken);
        }

        return $http;
    }

    protected function url(string $path): string
    {
        return rtrim($this->endpoint, '/').$path;
    }
}
