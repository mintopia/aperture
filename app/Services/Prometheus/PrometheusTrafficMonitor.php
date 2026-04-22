<?php

declare(strict_types=1);

namespace App\Services\Prometheus;

use App\Services\Interfaces\TrafficMonitorInterface;
use App\Services\ValueObjects\AggregateStats;
use App\Services\ValueObjects\TopTalker;
use App\Services\ValueObjects\UserBandwidth;
use Illuminate\Support\Collection;

class PrometheusTrafficMonitor implements TrafficMonitorInterface
{
    public function __construct(
        protected PrometheusService $prometheus,
        protected string $flowMetricPrefix = 'flow_traffic_bytes_total',
    ) {}

    public function getUserBandwidth(string $ipAddress, string $range = '24h'): UserBandwidth
    {
        $seconds = $this->rangeToSeconds($range);
        $end = time();
        $start = $end - $seconds;
        $step = $this->resolveStep($seconds);

        $escapedIp = $this->prometheus->escapePromQLLabelValue($ipAddress);

        $inQuery = sprintf(
            'rate(%s{src_addr="%s",direction="ingress"}[5m])',
            $this->flowMetricPrefix,
            $escapedIp,
        );
        $outQuery = sprintf(
            'rate(%s{src_addr="%s",direction="egress"}[5m])',
            $this->flowMetricPrefix,
            $escapedIp,
        );

        $inSeries = $this->prometheus->queryRange($inQuery, (float) $start, (float) $end, $step);
        $outSeries = $this->prometheus->queryRange($outQuery, (float) $start, (float) $end, $step);

        $inPoints = $this->extractPoints($inSeries);
        $outPoints = $this->extractPoints($outSeries);

        $timestamps = array_map(
            fn (array $point): string => (string) (int) $point[0],
            $inPoints ?: $outPoints,
        );

        $downloadValues = array_map(
            fn (array $point): int => (int) round((float) $point[1]),
            $inPoints,
        );

        $uploadValues = array_map(
            fn (array $point): int => (int) round((float) $point[1]),
            $outPoints,
        );

        $totalReceived = array_sum($downloadValues);
        $totalSent = array_sum($uploadValues);

        return new UserBandwidth(
            received: $totalReceived,
            sent: $totalSent,
            timestamps: array_values($timestamps),
            download: array_values($downloadValues),
            upload: array_values($uploadValues),
        );
    }

    public function getAggregateStats(): AggregateStats
    {
        $usersData = $this->prometheus->query(
            sprintf('count(count by (src_addr) (%s))', $this->flowMetricPrefix),
        );
        $totalUsers = $this->extractScalarValue($usersData);

        $devicesData = $this->prometheus->query(
            sprintf('count(count by (instance) (%s))', $this->flowMetricPrefix),
        );
        $totalDevices = $this->extractScalarValue($devicesData);

        $bandwidthData = $this->prometheus->query(
            sprintf('sum(rate(%s[5m]))', $this->flowMetricPrefix),
        );
        $totalBandwidth = $this->extractScalarValue($bandwidthData);

        return new AggregateStats(
            totalUsers: $totalUsers,
            totalDevices: $totalDevices,
            totalBandwidth: $totalBandwidth,
        );
    }

    /** @return Collection<int, TopTalker> */
    public function getTopTalkers(int $limit = 10): Collection
    {
        $query = sprintf(
            'topk(%d, sum by (src_addr) (rate(%s[5m])))',
            $limit,
            $this->flowMetricPrefix,
        );

        $data = $this->prometheus->query($query);
        /** @var array<int, array{metric: array<string, string>, value: array{0: float, 1: string}}> $results */
        $results = $data['result'] ?? [];

        return collect($results)->map(function (array $item): TopTalker {
            $ip = $item['metric']['src_addr'] ?? 'unknown';
            $totalRate = (int) round((float) $item['value'][1]);

            return new TopTalker(
                ip: $ip,
                received: $totalRate,
                sent: 0,
            );
        });
    }

    protected function rangeToSeconds(string $range): int
    {
        if (preg_match('/^(\d+)([hmd])$/', $range, $matches) !== 1) {
            return 86400; // default 24h
        }

        $value = (int) $matches[1];

        return match ($matches[2]) {
            'h' => $value * 3600,
            'd' => $value * 86400,
            default => $value * 60,
        };
    }

    protected function resolveStep(int $seconds): int
    {
        return match (true) {
            $seconds <= 3600 => 60,
            $seconds <= 86400 => 300,
            default => 900,
        };
    }

    /**
     * Extract data points from a Prometheus range query result.
     *
     * @param  array<string, mixed>  $data
     * @return array<int, array{0: float, 1: string}>
     */
    protected function extractPoints(array $data): array
    {
        $result = $data['result'] ?? [];
        if (empty($result)) {
            return [];
        }

        return $result[0]['values'] ?? [];
    }

    /**
     * Extract a scalar integer value from a Prometheus instant query result.
     *
     * @param  array<string, mixed>  $data
     */
    protected function extractScalarValue(array $data): int
    {
        $result = $data['result'] ?? [];
        if (empty($result)) {
            return 0;
        }

        return (int) round((float) ($result[0]['value'][1] ?? 0));
    }
}
