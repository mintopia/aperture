<?php

declare(strict_types=1);

namespace Tests\Unit\Services\Prometheus;

use App\Services\Prometheus\PrometheusPortBandwidth;
use App\Services\Prometheus\PrometheusService;
use App\Services\ValueObjects\PortTimeSeries;
use Mockery;
use Mockery\MockInterface;
use Tests\TestCase;

class PrometheusPortBandwidthTest extends TestCase
{
    private PrometheusService&MockInterface $prometheus;

    private PrometheusPortBandwidth $service;

    protected function setUp(): void
    {
        parent::setUp();

        $this->prometheus = Mockery::mock(PrometheusService::class);
        $this->service = new PrometheusPortBandwidth(
            prometheus: $this->prometheus,
        );
    }

    public function test_get_port_bandwidth_returns_port_time_series(): void
    {
        $inData = [
            ['timestamp' => 1700000000.0, 'value' => 1024.0],
            ['timestamp' => 1700000060.0, 'value' => 2048.0],
        ];
        $outData = [
            ['timestamp' => 1700000000.0, 'value' => 512.0],
            ['timestamp' => 1700000060.0, 'value' => 768.0],
        ];

        $this->prometheus->shouldReceive('getPortBandwidth')
            ->once()
            ->with('switch1', 'GigabitEthernet0/1', 1700000000.0, 1700003600.0, 60)
            ->andReturn(['in' => $inData, 'out' => $outData]);

        $result = $this->service->getPortBandwidth('switch1', 'GigabitEthernet0/1', 1700000000.0, 1700003600.0, 60);

        $this->assertInstanceOf(PortTimeSeries::class, $result);
        $this->assertSame($inData, $result->in);
        $this->assertSame($outData, $result->out);
    }

    public function test_get_port_bandwidth_passes_null_step(): void
    {
        $this->prometheus->shouldReceive('getPortBandwidth')
            ->once()
            ->with('switch1', 'Gi0/0', 0.0, 3600.0, null)
            ->andReturn(['in' => [], 'out' => []]);

        $result = $this->service->getPortBandwidth('switch1', 'Gi0/0', 0.0, 3600.0);

        $this->assertInstanceOf(PortTimeSeries::class, $result);
        $this->assertSame([], $result->in);
        $this->assertSame([], $result->out);
    }

    public function test_get_port_bandwidth_returns_empty_series_when_no_data(): void
    {
        $this->prometheus->shouldReceive('getPortBandwidth')
            ->once()
            ->andReturn(['in' => [], 'out' => []]);

        $result = $this->service->getPortBandwidth('switch1', 'GigabitEthernet0/1', 0.0, 1000.0);

        $this->assertInstanceOf(PortTimeSeries::class, $result);
        $this->assertSame([], $result->in);
        $this->assertSame([], $result->out);
    }

    public function test_is_available_delegates_to_prometheus(): void
    {
        $this->prometheus->shouldReceive('isAvailable')
            ->once()
            ->andReturn(true);

        $this->assertTrue($this->service->isAvailable());
    }

    public function test_is_available_returns_false_when_prometheus_unavailable(): void
    {
        $this->prometheus->shouldReceive('isAvailable')
            ->once()
            ->andReturn(false);

        $this->assertFalse($this->service->isAvailable());
    }
}
