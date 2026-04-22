<?php

declare(strict_types=1);

namespace Tests\Unit\Services\Prometheus;

use App\Services\Prometheus\PrometheusService;
use App\Services\Prometheus\PrometheusTrafficMonitor;
use App\Services\ValueObjects\AggregateStats;
use App\Services\ValueObjects\TopTalker;
use App\Services\ValueObjects\UserBandwidth;
use Mockery;
use Mockery\MockInterface;
use Tests\TestCase;

class PrometheusTrafficMonitorTest extends TestCase
{
    private PrometheusService&MockInterface $prometheus;

    private PrometheusTrafficMonitor $monitor;

    protected function setUp(): void
    {
        parent::setUp();

        $this->prometheus = Mockery::mock(PrometheusService::class);
        $this->monitor = new PrometheusTrafficMonitor(
            prometheus: $this->prometheus,
            flowMetricPrefix: 'flow_traffic_bytes_total',
        );
    }

    public function test_get_user_bandwidth_returns_user_bandwidth_value_object(): void
    {
        $this->prometheus->shouldReceive('escapePromQLLabelValue')
            ->with('10.0.0.1')
            ->andReturn('10.0.0.1');

        $this->prometheus->shouldReceive('queryRange')
            ->withArgs(function (string $query, float $start, float $end, int $step): bool {
                return str_contains($query, 'direction="ingress"')
                    && str_contains($query, 'src_addr="10.0.0.1"');
            })
            ->once()
            ->andReturn([
                'resultType' => 'matrix',
                'result' => [
                    [
                        'metric' => ['src_addr' => '10.0.0.1'],
                        'values' => [
                            [1700000000.0, '1024'],
                            [1700000300.0, '2048'],
                        ],
                    ],
                ],
            ]);

        $this->prometheus->shouldReceive('queryRange')
            ->withArgs(function (string $query, float $start, float $end, int $step): bool {
                return str_contains($query, 'direction="egress"')
                    && str_contains($query, 'src_addr="10.0.0.1"');
            })
            ->once()
            ->andReturn([
                'resultType' => 'matrix',
                'result' => [
                    [
                        'metric' => ['src_addr' => '10.0.0.1'],
                        'values' => [
                            [1700000000.0, '512'],
                            [1700000300.0, '768'],
                        ],
                    ],
                ],
            ]);

        $result = $this->monitor->getUserBandwidth('10.0.0.1', '24h');

        $this->assertInstanceOf(UserBandwidth::class, $result);
        $this->assertSame(3072, $result->received);  // 1024 + 2048
        $this->assertSame(1280, $result->sent);       // 512 + 768
        $this->assertCount(2, $result->timestamps);
        $this->assertCount(2, $result->download);
        $this->assertCount(2, $result->upload);
        $this->assertSame('1700000000', $result->timestamps[0]);
        $this->assertSame(1024, $result->download[0]);
        $this->assertSame(512, $result->upload[0]);
    }

    public function test_get_user_bandwidth_returns_empty_when_no_data(): void
    {
        $this->prometheus->shouldReceive('escapePromQLLabelValue')
            ->with('10.0.0.99')
            ->andReturn('10.0.0.99');

        $this->prometheus->shouldReceive('queryRange')
            ->twice()
            ->andReturn([
                'resultType' => 'matrix',
                'result' => [],
            ]);

        $result = $this->monitor->getUserBandwidth('10.0.0.99');

        $this->assertInstanceOf(UserBandwidth::class, $result);
        $this->assertSame(0, $result->received);
        $this->assertSame(0, $result->sent);
        $this->assertEmpty($result->timestamps);
        $this->assertEmpty($result->download);
        $this->assertEmpty($result->upload);
    }

    public function test_get_user_bandwidth_defaults_to_24h_range(): void
    {
        $this->prometheus->shouldReceive('escapePromQLLabelValue')
            ->andReturn('10.0.0.1');

        $this->prometheus->shouldReceive('queryRange')
            ->withArgs(function (string $query, float $start, float $end, int $step): bool {
                // 24h = 86400s, step should be 300 for 24h
                $diff = $end - $start;

                return abs($diff - 86400) < 2 && $step === 300;
            })
            ->twice()
            ->andReturn(['result' => []]);

        $this->monitor->getUserBandwidth('10.0.0.1');
    }

    public function test_get_user_bandwidth_uses_1h_range(): void
    {
        $this->prometheus->shouldReceive('escapePromQLLabelValue')
            ->andReturn('10.0.0.1');

        $this->prometheus->shouldReceive('queryRange')
            ->withArgs(function (string $query, float $start, float $end, int $step): bool {
                $diff = $end - $start;

                return abs($diff - 3600) < 2 && $step === 60;
            })
            ->twice()
            ->andReturn(['result' => []]);

        $this->monitor->getUserBandwidth('10.0.0.1', '1h');
    }

    public function test_get_user_bandwidth_uses_7d_range(): void
    {
        $this->prometheus->shouldReceive('escapePromQLLabelValue')
            ->andReturn('10.0.0.1');

        $this->prometheus->shouldReceive('queryRange')
            ->withArgs(function (string $query, float $start, float $end, int $step): bool {
                $diff = $end - $start;

                return abs($diff - 604800) < 2 && $step === 900;
            })
            ->twice()
            ->andReturn(['result' => []]);

        $this->monitor->getUserBandwidth('10.0.0.1', '7d');
    }

    public function test_get_user_bandwidth_defaults_range_for_invalid_input(): void
    {
        $this->prometheus->shouldReceive('escapePromQLLabelValue')
            ->andReturn('10.0.0.1');

        $this->prometheus->shouldReceive('queryRange')
            ->withArgs(function (string $query, float $start, float $end, int $step): bool {
                $diff = $end - $start;

                return abs($diff - 86400) < 2; // defaults to 24h
            })
            ->twice()
            ->andReturn(['result' => []]);

        $this->monitor->getUserBandwidth('10.0.0.1', 'invalid');
    }

    public function test_get_aggregate_stats_returns_aggregate_stats_value_object(): void
    {
        $this->prometheus->shouldReceive('query')
            ->withArgs(fn (string $q): bool => str_contains($q, 'count(count by (src_addr)'))
            ->once()
            ->andReturn([
                'resultType' => 'vector',
                'result' => [['value' => [1700000000, '42']]],
            ]);

        $this->prometheus->shouldReceive('query')
            ->withArgs(fn (string $q): bool => str_contains($q, 'count(count by (instance)'))
            ->once()
            ->andReturn([
                'resultType' => 'vector',
                'result' => [['value' => [1700000000, '5']]],
            ]);

        $this->prometheus->shouldReceive('query')
            ->withArgs(fn (string $q): bool => str_contains($q, 'sum(rate('))
            ->once()
            ->andReturn([
                'resultType' => 'vector',
                'result' => [['value' => [1700000000, '1073741824']]],
            ]);

        $result = $this->monitor->getAggregateStats();

        $this->assertInstanceOf(AggregateStats::class, $result);
        $this->assertSame(42, $result->totalUsers);
        $this->assertSame(5, $result->totalDevices);
        $this->assertSame(1073741824, $result->totalBandwidth);
    }

    public function test_get_aggregate_stats_returns_zeros_when_no_data(): void
    {
        $this->prometheus->shouldReceive('query')
            ->times(3)
            ->andReturn(['result' => []]);

        $result = $this->monitor->getAggregateStats();

        $this->assertInstanceOf(AggregateStats::class, $result);
        $this->assertSame(0, $result->totalUsers);
        $this->assertSame(0, $result->totalDevices);
        $this->assertSame(0, $result->totalBandwidth);
    }

    public function test_get_top_talkers_returns_collection_of_top_talkers(): void
    {
        $this->prometheus->shouldReceive('query')
            ->withArgs(fn (string $q): bool => str_contains($q, 'topk(10,'))
            ->once()
            ->andReturn([
                'resultType' => 'vector',
                'result' => [
                    [
                        'metric' => ['src_addr' => '192.168.1.10'],
                        'value' => [1700000000, '5000000'],
                    ],
                    [
                        'metric' => ['src_addr' => '192.168.1.20'],
                        'value' => [1700000000, '3000000'],
                    ],
                ],
            ]);

        $result = $this->monitor->getTopTalkers(10);

        $this->assertCount(2, $result);
        $this->assertInstanceOf(TopTalker::class, $result->first());
        $this->assertSame('192.168.1.10', $result->first()->ip);
        $this->assertSame(5000000, $result->first()->received);
        $this->assertSame(0, $result->first()->sent);
        $this->assertNull($result->first()->nickname);
        $this->assertSame('192.168.1.20', $result->get(1)->ip);
        $this->assertSame(3000000, $result->get(1)->received);
    }

    public function test_get_top_talkers_returns_empty_collection_when_no_data(): void
    {
        $this->prometheus->shouldReceive('query')
            ->once()
            ->andReturn(['result' => []]);

        $result = $this->monitor->getTopTalkers();

        $this->assertCount(0, $result);
    }

    public function test_get_top_talkers_uses_custom_limit(): void
    {
        $this->prometheus->shouldReceive('query')
            ->withArgs(fn (string $q): bool => str_contains($q, 'topk(5,'))
            ->once()
            ->andReturn(['result' => []]);

        $this->monitor->getTopTalkers(5);
    }

    public function test_get_top_talkers_handles_missing_src_addr(): void
    {
        $this->prometheus->shouldReceive('query')
            ->once()
            ->andReturn([
                'result' => [
                    [
                        'metric' => [],
                        'value' => [1700000000, '1000'],
                    ],
                ],
            ]);

        $result = $this->monitor->getTopTalkers();

        $this->assertCount(1, $result);
        $this->assertSame('unknown', $result->first()->ip);
    }

    public function test_custom_flow_metric_prefix_is_used_in_queries(): void
    {
        $customMonitor = new PrometheusTrafficMonitor(
            prometheus: $this->prometheus,
            flowMetricPrefix: 'custom_flow_metric',
        );

        $this->prometheus->shouldReceive('query')
            ->withArgs(fn (string $q): bool => str_contains($q, 'custom_flow_metric'))
            ->times(3)
            ->andReturn(['result' => []]);

        $customMonitor->getAggregateStats();
    }

    public function test_get_user_bandwidth_uses_minutes_range(): void
    {
        $this->prometheus->shouldReceive('escapePromQLLabelValue')
            ->andReturn('10.0.0.1');

        $this->prometheus->shouldReceive('queryRange')
            ->withArgs(function (string $query, float $start, float $end, int $step): bool {
                $diff = $end - $start;

                return abs($diff - 1800) < 2 && $step === 60; // 30m = 1800s, step 60 for <= 1h
            })
            ->twice()
            ->andReturn(['result' => []]);

        $this->monitor->getUserBandwidth('10.0.0.1', '30m');
    }
}
