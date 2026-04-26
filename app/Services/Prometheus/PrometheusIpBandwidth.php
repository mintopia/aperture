<?php

declare(strict_types=1);

namespace App\Services\Prometheus;

use App\Services\Interfaces\IpBandwidthInterface;
use App\Services\ValueObjects\IpBandwidthResult;
use App\Services\ValueObjects\TopTalker;
use Illuminate\Support\Collection;

class PrometheusIpBandwidth implements IpBandwidthInterface
{
    public function __construct(
        protected PrometheusService $prometheus,
        protected string $rcvdMetric = 'ntopng_host_bytes_rcvd',
        protected string $sentMetric = 'ntopng_host_bytes_sent',
        protected string $ipLabel = 'ip',
    ) {}

    public function getIpBandwidth(string|array $ipAddress, string $range = '24h'): IpBandwidthResult
    {
        $ipFilter = $this->buildIpFilter($ipAddress);

        return $this->queryBandwidthRange($range, $ipFilter);
    }

    public function getTotalBandwidth(string $range = '24h'): IpBandwidthResult
    {
        return $this->queryBandwidthRange($range);
    }

    /** @return Collection<int, TopTalker> */
    public function getTopTalkers(int $limit = 10, string $range = '1m'): Collection
    {
        $seconds = $this->rangeToSeconds($range);
        $step = $this->resolveStep($seconds);
        $rateWindow = $this->resolveRateWindow($step);

        $query = sprintf(
            'topk(%d, sum by (%s) (rate(%s[%s])))',
            $limit,
            $this->ipLabel,
            $this->rcvdMetric,
            $rateWindow,
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

    protected function queryBandwidthRange(string $range, ?string $ipFilter = null): IpBandwidthResult
    {
        $seconds = $this->rangeToSeconds($range);
        $step = $this->resolveStep($seconds);
        $end = (int) (floor(time() / $step) * $step);
        $start = $end - $seconds;

        $rateWindow = $this->resolveRateWindow($step);
        $selector = $ipFilter !== null ? sprintf('{%s}', $ipFilter) : '';

        $inQuery = sprintf(
            'sum(rate(%s%s[%s]))',
            $this->rcvdMetric,
            $selector,
            $rateWindow,
        );
        $outQuery = sprintf(
            'sum(rate(%s%s[%s]))',
            $this->sentMetric,
            $selector,
            $rateWindow,
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

        return new IpBandwidthResult(
            received: $totalReceived,
            sent: $totalSent,
            timestamps: array_values($timestamps),
            download: array_values($downloadValues),
            upload: array_values($uploadValues),
        );
    }

    /** @param string|string[] $ipAddress */
    protected function buildIpFilter(string|array $ipAddress): string
    {
        if (is_string($ipAddress)) {
            $escaped = $this->prometheus->escapePromQLLabelValue($ipAddress);

            return sprintf('%s="%s"', $this->ipLabel, $escaped);
        }

        $escaped = array_map(
            fn (string $ip): string => $this->prometheus->escapePromQLLabelValue($this->escapeRe2($ip)),
            $ipAddress,
        );

        return sprintf('%s=~"%s"', $this->ipLabel, implode('|', $escaped));
    }

    protected function escapeRe2(string $value): string
    {
        return (string) preg_replace('/([.\\\\*+?{}()\[\]^$|])/', '\\\\$1', $value);
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

    protected function resolveRateWindow(int $step): string
    {
        $window = max($step, 120);

        return (int) ($window / 60).'m';
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
}
