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
        protected string $rcvdMetric = 'ntopng_host_bytes_rcvd',
        protected string $sentMetric = 'ntopng_host_bytes_sent',
        protected string $ipLabel = 'ip',
    ) {}

    public function getUserBandwidth(string $ipAddress, string $range = '24h'): UserBandwidth
    {
        $seconds = $this->rangeToSeconds($range);
        $end = time();
        $start = $end - $seconds;
        $step = $this->resolveStep($seconds);

        $escapedIp = $this->prometheus->escapePromQLLabelValue($ipAddress);

        $inQuery = sprintf(
            'sum(rate(%s{%s="%s"}[2m]))',
            $this->rcvdMetric,
            $this->ipLabel,
            $escapedIp,
        );
        $outQuery = sprintf(
            'sum(rate(%s{%s="%s"}[2m]))',
            $this->sentMetric,
            $this->ipLabel,
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
            fn (array $point): float => (float) $point[1] * 8,
            $inPoints,
        );

        $uploadValues = array_map(
            fn (array $point): float => (float) $point[1] * 8,
            $outPoints,
        );

        $totalReceived = (int) round(array_sum(array_map(
            fn (array $point): float => (float) $point[1],
            $inPoints,
        )) * $step);

        $totalSent = (int) round(array_sum(array_map(
            fn (array $point): float => (float) $point[1],
            $outPoints,
        )) * $step);

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
            sprintf('count(count by (%s) (%s))', $this->ipLabel, $this->rcvdMetric),
        );
        $totalUsers = $this->extractScalarValue($usersData);

        $devicesData = $this->prometheus->query(
            sprintf('count(count by (instance) (%s))', $this->rcvdMetric),
        );
        $totalDevices = $this->extractScalarValue($devicesData);

        $rcvdBandwidthData = $this->prometheus->query(
            sprintf('sum(rate(%s[2m]))', $this->rcvdMetric),
        );
        $sentBandwidthData = $this->prometheus->query(
            sprintf('sum(rate(%s[2m]))', $this->sentMetric),
        );
        $totalBandwidth = ($this->extractScalarValue($rcvdBandwidthData) + $this->extractScalarValue($sentBandwidthData)) * 8;

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
            'topk(%d, sum by (%s) (rate(%s[2m])))',
            $limit,
            $this->ipLabel,
            $this->rcvdMetric,
        );

        $data = $this->prometheus->query($query);
        /** @var array<int, array{metric: array<string, string>, value: array{0: float, 1: string}}> $results */
        $results = $data['result'] ?? [];

        $ipLabel = $this->ipLabel;

        return collect($results)->map(function (array $item) use ($ipLabel): TopTalker {
            $ip = $item['metric'][$ipLabel] ?? 'unknown';
            $totalRate = (int) round((float) $item['value'][1] * 8);

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
     * Extract data points from a Prometheus range query result,
     * merging multiple series by summing values at each timestamp.
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

        if (count($result) === 1) {
            return $result[0]['values'] ?? [];
        }

        /** @var array<string, float> $merged */
        $merged = [];
        foreach ($result as $series) {
            foreach ($series['values'] ?? [] as $point) {
                $ts = (string) $point[0];
                $merged[$ts] = ($merged[$ts] ?? 0.0) + (float) $point[1];
            }
        }

        ksort($merged);

        $points = [];
        foreach ($merged as $ts => $value) {
            $points[] = [(float) $ts, (string) $value];
        }

        return $points;
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
