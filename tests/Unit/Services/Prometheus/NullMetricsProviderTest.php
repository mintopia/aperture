<?php

declare(strict_types=1);

namespace Tests\Unit\Services\Prometheus;

use App\Services\Null\NullMetricsProvider;
use Tests\TestCase;

class NullMetricsProviderTest extends TestCase
{
    private NullMetricsProvider $provider;

    protected function setUp(): void
    {
        parent::setUp();

        $this->provider = new NullMetricsProvider;
    }

    public function test_query_returns_empty_array(): void
    {
        $this->assertSame([], $this->provider->query('up'));
    }

    public function test_query_range_returns_empty_array(): void
    {
        $this->assertSame([], $this->provider->queryRange('up', 1000.0, 2000.0));
    }

    public function test_get_port_bandwidth_returns_empty_in_out(): void
    {
        $result = $this->provider->getPortBandwidth('device', 'Gi0/1', 1000.0, 2000.0);

        $this->assertSame(['in' => [], 'out' => []], $result);
    }

    public function test_get_port_errors_returns_empty_in_out(): void
    {
        $result = $this->provider->getPortErrors('device', 'Gi0/1', 1000.0, 2000.0);

        $this->assertSame(['in' => [], 'out' => []], $result);
    }

    public function test_get_device_bandwidth_returns_empty_in_out(): void
    {
        $result = $this->provider->getDeviceBandwidth('device', 1000.0, 2000.0);

        $this->assertSame(['in' => [], 'out' => []], $result);
    }

    public function test_is_available_returns_false(): void
    {
        $this->assertFalse($this->provider->isAvailable());
    }
}
