<?php

declare(strict_types=1);

namespace Tests\Unit\Services\Prometheus;

use App\Services\Prometheus\NullTrafficMonitor;
use App\Services\ValueObjects\AggregateStats;
use App\Services\ValueObjects\UserBandwidth;
use Tests\TestCase;

class NullTrafficMonitorTest extends TestCase
{
    private NullTrafficMonitor $monitor;

    protected function setUp(): void
    {
        parent::setUp();
        $this->monitor = new NullTrafficMonitor;
    }

    public function test_get_user_bandwidth_returns_empty_user_bandwidth(): void
    {
        $result = $this->monitor->getUserBandwidth('10.0.0.1');

        $this->assertInstanceOf(UserBandwidth::class, $result);
        $this->assertSame(0, $result->received);
        $this->assertSame(0, $result->sent);
        $this->assertEmpty($result->timestamps);
        $this->assertEmpty($result->download);
        $this->assertEmpty($result->upload);
    }

    public function test_get_aggregate_stats_returns_zero_stats(): void
    {
        $result = $this->monitor->getAggregateStats();

        $this->assertInstanceOf(AggregateStats::class, $result);
        $this->assertSame(0, $result->totalUsers);
        $this->assertSame(0, $result->totalDevices);
        $this->assertSame(0, $result->totalBandwidth);
    }

    public function test_get_top_talkers_returns_empty_collection(): void
    {
        $result = $this->monitor->getTopTalkers();

        $this->assertCount(0, $result);
    }

    public function test_get_top_talkers_ignores_limit_parameter(): void
    {
        $result = $this->monitor->getTopTalkers(100);

        $this->assertCount(0, $result);
    }
}
