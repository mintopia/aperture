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
        );
    }

    public function test_get_user_bandwidth_returns_user_bandwidth_value_object(): void
    {
        $this->prometheus->shouldReceive('escapePromQLLabelValue')
            ->with('10.0.0.1')
            ->andReturn('10.0.0.1');

        $this->prometheus->shouldReceive('queryRange')
            ->withArgs(function (string $query, float $start, float $end, int $step): bool {
                return str_contains($query, 'ntopng_host_bytes_rcvd')
                    && str_contains($query, 'ip="10.0.0.1"');
            })
            ->once()
            ->andReturn([
                'resultType' => 'matrix',
                'result' => [
                    [
                        'metric' => ['ip' => '10.0.0.1'],
                        'values' => [
                            [1700000000.0, '1024'],
                            [1700000300.0, '2048'],
                        ],
                    ],
                ],
            ]);

        $this->prometheus->shouldReceive('queryRange')
            ->withArgs(function (string $query, float $start, float $end, int $step): bool {
                return str_contains($query, 'ntopng_host_bytes_sent')
                    && str_contains($query, 'ip="10.0.0.1"');
            })
            ->once()
            ->andReturn([
                'resultType' => 'matrix',
                'result' => [
                    [
                        'metric' => ['ip' => '10.0.0.1'],
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
            ->withArgs(fn (string $q): bool => str_contains($q, 'count(count by (ip)'))
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
            ->withArgs(fn (string $q): bool => str_contains($q, 'sum(rate(ntopng_host_bytes_rcvd'))
            ->once()
            ->andReturn([
                'resultType' => 'vector',
                'result' => [['value' => [1700000000, '500000000']]],
            ]);

        $this->prometheus->shouldReceive('query')
            ->withArgs(fn (string $q): bool => str_contains($q, 'sum(rate(ntopng_host_bytes_sent'))
            ->once()
            ->andReturn([
                'resultType' => 'vector',
                'result' => [['value' => [1700000000, '573741824']]],
            ]);

        $result = $this->monitor->getAggregateStats();

        $this->assertInstanceOf(AggregateStats::class, $result);
        $this->assertSame(42, $result->totalUsers);
        $this->assertSame(5, $result->totalDevices);
        $this->assertSame(1073741824, $result->totalBandwidth); // 500000000 + 573741824
    }

    public function test_get_aggregate_stats_returns_zeros_when_no_data(): void
    {
        $this->prometheus->shouldReceive('query')
            ->times(4)
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
            ->withArgs(fn (string $q): bool => str_contains($q, 'topk(10,') && str_contains($q, 'sum by (ip)'))
            ->once()
            ->andReturn([
                'resultType' => 'vector',
                'result' => [
                    [
                        'metric' => ['ip' => '192.168.1.10'],
                        'value' => [1700000000, '5000000'],
                    ],
                    [
                        'metric' => ['ip' => '192.168.1.20'],
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

    public function test_get_top_talkers_handles_missing_ip_label(): void
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

    public function test_custom_metrics_are_used_in_queries(): void
    {
        $customMonitor = new PrometheusTrafficMonitor(
            prometheus: $this->prometheus,
            rcvdMetric: 'custom_bytes_in',
            sentMetric: 'custom_bytes_out',
            ipLabel: 'src_addr',
        );

        $this->prometheus->shouldReceive('query')
            ->withArgs(fn (string $q): bool => str_contains($q, 'count(count by (src_addr)') && str_contains($q, 'custom_bytes_in'))
            ->once()
            ->andReturn(['result' => []]);

        $this->prometheus->shouldReceive('query')
            ->withArgs(fn (string $q): bool => str_contains($q, 'count(count by (instance)') && str_contains($q, 'custom_bytes_in'))
            ->once()
            ->andReturn(['result' => []]);

        $this->prometheus->shouldReceive('query')
            ->withArgs(fn (string $q): bool => str_contains($q, 'sum(rate(custom_bytes_in'))
            ->once()
            ->andReturn(['result' => []]);

        $this->prometheus->shouldReceive('query')
            ->withArgs(fn (string $q): bool => str_contains($q, 'sum(rate(custom_bytes_out'))
            ->once()
            ->andReturn(['result' => []]);

        $customMonitor->getAggregateStats();
    }

    public function test_custom_metrics_are_used_in_bandwidth_queries(): void
    {
        $customMonitor = new PrometheusTrafficMonitor(
            prometheus: $this->prometheus,
            rcvdMetric: 'flow_in',
            sentMetric: 'flow_out',
            ipLabel: 'host',
        );

        $this->prometheus->shouldReceive('escapePromQLLabelValue')
            ->with('10.0.0.1')
            ->andReturn('10.0.0.1');

        $this->prometheus->shouldReceive('queryRange')
            ->withArgs(function (string $query): bool {
                return str_contains($query, 'rate(flow_in{host="10.0.0.1"}');
            })
            ->once()
            ->andReturn(['result' => []]);

        $this->prometheus->shouldReceive('queryRange')
            ->withArgs(function (string $query): bool {
                return str_contains($query, 'rate(flow_out{host="10.0.0.1"}');
            })
            ->once()
            ->andReturn(['result' => []]);

        $customMonitor->getUserBandwidth('10.0.0.1');
    }

    public function test_custom_ip_label_used_in_top_talkers(): void
    {
        $customMonitor = new PrometheusTrafficMonitor(
            prometheus: $this->prometheus,
            rcvdMetric: 'custom_rcvd',
            sentMetric: 'custom_sent',
            ipLabel: 'src_ip',
        );

        $this->prometheus->shouldReceive('query')
            ->withArgs(fn (string $q): bool => str_contains($q, 'sum by (src_ip)') && str_contains($q, 'custom_rcvd'))
            ->once()
            ->andReturn([
                'result' => [
                    [
                        'metric' => ['src_ip' => '10.1.1.1'],
                        'value' => [1700000000, '999'],
                    ],
                ],
            ]);

        $result = $customMonitor->getTopTalkers();

        $this->assertCount(1, $result);
        $this->assertSame('10.1.1.1', $result->first()->ip);
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

    public function test_get_user_bandwidth_wraps_queries_in_sum(): void
    {
        $this->prometheus->shouldReceive('escapePromQLLabelValue')
            ->with('44.30.69.131')
            ->andReturn('44.30.69.131');

        $this->prometheus->shouldReceive('queryRange')
            ->withArgs(function (string $query): bool {
                return str_starts_with($query, 'sum(rate(ntopng_host_bytes_rcvd{ip="44.30.69.131"}')
                    && str_ends_with($query, '))');
            })
            ->once()
            ->andReturn(['result' => []]);

        $this->prometheus->shouldReceive('queryRange')
            ->withArgs(function (string $query): bool {
                return str_starts_with($query, 'sum(rate(ntopng_host_bytes_sent{ip="44.30.69.131"}')
                    && str_ends_with($query, '))');
            })
            ->once()
            ->andReturn(['result' => []]);

        $this->monitor->getUserBandwidth('44.30.69.131');
    }

    public function test_get_user_bandwidth_merges_multiple_series(): void
    {
        $this->prometheus->shouldReceive('escapePromQLLabelValue')
            ->with('44.30.69.131')
            ->andReturn('44.30.69.131');

        // Simulate multiple series returned (shouldn't happen with sum(), but safety net)
        $this->prometheus->shouldReceive('queryRange')
            ->withArgs(fn (string $q): bool => str_contains($q, 'ntopng_host_bytes_rcvd'))
            ->once()
            ->andReturn([
                'resultType' => 'matrix',
                'result' => [
                    [
                        'metric' => ['ip' => '44.30.69.131', 'instance' => 'a'],
                        'values' => [
                            [1700000000.0, '100'],
                            [1700000300.0, '200'],
                        ],
                    ],
                    [
                        'metric' => ['ip' => '44.30.69.131', 'instance' => 'b'],
                        'values' => [
                            [1700000000.0, '50'],
                            [1700000300.0, '75'],
                        ],
                    ],
                ],
            ]);

        $this->prometheus->shouldReceive('queryRange')
            ->withArgs(fn (string $q): bool => str_contains($q, 'ntopng_host_bytes_sent'))
            ->once()
            ->andReturn([
                'resultType' => 'matrix',
                'result' => [
                    [
                        'metric' => ['ip' => '44.30.69.131', 'instance' => 'a'],
                        'values' => [
                            [1700000000.0, '10'],
                            [1700000300.0, '20'],
                        ],
                    ],
                    [
                        'metric' => ['ip' => '44.30.69.131', 'instance' => 'b'],
                        'values' => [
                            [1700000000.0, '5'],
                            [1700000300.0, '8'],
                        ],
                    ],
                ],
            ]);

        $result = $this->monitor->getUserBandwidth('44.30.69.131', '24h');

        // Should sum across series: 100+50=150, 200+75=275 for download
        $this->assertSame(150, $result->download[0]);
        $this->assertSame(275, $result->download[1]);
        $this->assertSame(425, $result->received);  // 150 + 275

        // Upload: 10+5=15, 20+8=28
        $this->assertSame(15, $result->upload[0]);
        $this->assertSame(28, $result->upload[1]);
        $this->assertSame(43, $result->sent);  // 15 + 28

        $this->assertCount(2, $result->timestamps);
        $this->assertSame('1700000000', $result->timestamps[0]);
        $this->assertSame('1700000300', $result->timestamps[1]);
    }
}
