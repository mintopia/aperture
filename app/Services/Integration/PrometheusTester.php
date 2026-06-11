<?php

declare(strict_types=1);

namespace App\Services\Integration;

use App\Services\Interfaces\TestableIntegration;
use App\Services\ValueObjects\TestConnectionResult;
use Illuminate\Http\Client\PendingRequest;
use Illuminate\Support\Facades\Http;
use Throwable;

class PrometheusTester implements TestableIntegration
{
    /**
     * Maximum age of the newest sample before the metric feed is considered stale.
     */
    private const STALENESS_THRESHOLD_SECONDS = 900;

    private const DEFAULT_BANDWIDTH_METRIC = 'ntopng_host_bytes_rcvd';

    /**
     * @param  array<string, mixed>  $config
     */
    public function connect(array $config): TestConnectionResult
    {
        $endpoint = rtrim(is_string($config['endpoint'] ?? null) ? $config['endpoint'] : '', '/');
        $url = $endpoint.'/api/v1/status/buildinfo';

        $result = ConnectionTester::test(
            'GET',
            $url,
            fn () => $this->client($config)->get($url),
        );

        if (! $result->success) {
            return $result;
        }

        return $this->checkMetricFreshness($config, $endpoint, $result);
    }

    /**
     * Verify the bandwidth metric is actually receiving recent samples, so a
     * reachable Prometheus with a dead ntopng feed is reported as unhealthy.
     *
     * @param  array<string, mixed>  $config
     */
    private function checkMetricFreshness(array $config, string $endpoint, TestConnectionResult $buildinfoResult): TestConnectionResult
    {
        $metric = is_string($config['bandwidth_rcvd_metric'] ?? null) && $config['bandwidth_rcvd_metric'] !== ''
            ? $config['bandwidth_rcvd_metric']
            : self::DEFAULT_BANDWIDTH_METRIC;
        $queryUrl = $endpoint.'/api/v1/query';

        try {
            $response = $this->client($config)->get($queryUrl, ['query' => $metric]);
            $response->throw();
        } catch (Throwable $throwable) {
            return new TestConnectionResult(
                success: false,
                message: 'Connection failed: '.$throwable->getMessage(),
                requestMethod: 'GET',
                requestUrl: $queryUrl,
            );
        }

        $newestTimestamp = $this->newestSampleTimestamp($response->json('data.result'));

        if ($newestTimestamp === null) {
            return new TestConnectionResult(
                success: false,
                message: sprintf('Prometheus is reachable but returned no samples for metric "%s".', $metric),
                requestMethod: 'GET',
                requestUrl: $queryUrl,
                responseStatus: $response->status(),
                responseBody: $response->body(),
            );
        }

        $age = time() - (int) $newestTimestamp;

        if ($age > self::STALENESS_THRESHOLD_SECONDS) {
            return new TestConnectionResult(
                success: false,
                message: sprintf(
                    'Prometheus metric "%s" is stale: newest sample is %d minutes old (threshold is 15 minutes).',
                    $metric,
                    intdiv($age, 60),
                ),
                requestMethod: 'GET',
                requestUrl: $queryUrl,
                responseStatus: $response->status(),
                responseBody: $response->body(),
            );
        }

        return $buildinfoResult;
    }

    /**
     * Find the newest sample timestamp across all returned vector series.
     */
    private function newestSampleTimestamp(mixed $series): ?float
    {
        if (! is_array($series)) {
            return null;
        }

        $newest = null;

        foreach ($series as $sample) {
            if (! is_array($sample) || ! is_array($sample['value'] ?? null)) {
                continue;
            }

            $timestamp = $sample['value'][0] ?? null;

            if (! is_int($timestamp) && ! is_float($timestamp)) {
                continue;
            }

            if ($newest === null || $timestamp > $newest) {
                $newest = (float) $timestamp;
            }
        }

        return $newest;
    }

    /**
     * @param  array<string, mixed>  $config
     */
    private function client(array $config): PendingRequest
    {
        $http = Http::withOptions([
            'verify' => (bool) ($config['verify_ssl'] ?? true),
        ])->timeout(10);

        if (! empty($config['bearer_token']) && is_string($config['bearer_token'])) {
            $http = $http->withToken($config['bearer_token']);
        }

        return $http;
    }
}
