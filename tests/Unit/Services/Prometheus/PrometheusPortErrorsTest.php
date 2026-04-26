<?php

declare(strict_types=1);

namespace Tests\Unit\Services\Prometheus;

use App\Services\Prometheus\PrometheusPortErrors;
use App\Services\Prometheus\PrometheusService;
use App\Services\ValueObjects\PortTimeSeries;
use Mockery;
use Mockery\MockInterface;
use Tests\TestCase;

class PrometheusPortErrorsTest extends TestCase
{
    private PrometheusService&MockInterface $prometheus;

    private PrometheusPortErrors $service;

    protected function setUp(): void
    {
        parent::setUp();

        $this->prometheus = Mockery::mock(PrometheusService::class);
        $this->service = new PrometheusPortErrors(
            prometheus: $this->prometheus,
        );
    }

    public function test_get_port_errors_returns_port_time_series(): void
    {
        $inData = [
            ['timestamp' => 1700000000.0, 'value' => 5.0],
            ['timestamp' => 1700000060.0, 'value' => 3.0],
        ];
        $outData = [
            ['timestamp' => 1700000000.0, 'value' => 1.0],
            ['timestamp' => 1700000060.0, 'value' => 2.0],
        ];

        $this->prometheus->shouldReceive('getPortErrors')
            ->once()
            ->with('switch1', 'GigabitEthernet0/1', 1700000000.0, 1700003600.0, 60)
            ->andReturn(['in' => $inData, 'out' => $outData]);

        $result = $this->service->getPortErrors('switch1', 'GigabitEthernet0/1', 1700000000.0, 1700003600.0, 60);

        $this->assertInstanceOf(PortTimeSeries::class, $result);
        $this->assertSame($inData, $result->in);
        $this->assertSame($outData, $result->out);
    }

    public function test_get_port_errors_passes_null_step(): void
    {
        $this->prometheus->shouldReceive('getPortErrors')
            ->once()
            ->with('switch1', 'Gi0/0', 0.0, 3600.0, null)
            ->andReturn(['in' => [], 'out' => []]);

        $result = $this->service->getPortErrors('switch1', 'Gi0/0', 0.0, 3600.0);

        $this->assertInstanceOf(PortTimeSeries::class, $result);
        $this->assertSame([], $result->in);
        $this->assertSame([], $result->out);
    }

    public function test_get_port_errors_returns_empty_series_when_no_data(): void
    {
        $this->prometheus->shouldReceive('getPortErrors')
            ->once()
            ->andReturn(['in' => [], 'out' => []]);

        $result = $this->service->getPortErrors('switch1', 'GigabitEthernet0/1', 0.0, 1000.0);

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
