<?php

declare(strict_types=1);

namespace Tests\Unit\Jobs;

use App\Events\SwitchSyncCompleted;
use App\Events\SwitchUnreachable;
use App\Jobs\SyncSwitchPortsJob;
use App\Models\SwitchConfig;
use App\Models\SwitchSyncRun;
use App\Services\NetworkSwitch\CircuitBreaker;
use App\Services\NetworkSwitch\PortSyncService;
use Illuminate\Foundation\Testing\LazilyRefreshDatabase;
use Illuminate\Support\Facades\Event;
use Mockery;
use Mockery\MockInterface;
use RuntimeException;
use Tests\TestCase;

class SyncSwitchPortsJobCircuitBreakerTest extends TestCase
{
    use LazilyRefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        config(['aperture.circuit_breaker.failure_threshold' => 3]);
    }

    public function test_job_skips_circuit_broken_switch(): void
    {
        $switchConfig = SwitchConfig::factory()->create();

        /** @var MockInterface&CircuitBreaker $circuitBreaker */
        $circuitBreaker = Mockery::mock(CircuitBreaker::class);
        $circuitBreaker->shouldReceive('isAvailable')
            ->with(Mockery::on(fn (SwitchConfig $config): bool => $config->is($switchConfig)))
            ->once()
            ->andReturn(false);

        $this->app->instance(CircuitBreaker::class, $circuitBreaker);

        /** @var MockInterface&PortSyncService $service */
        $service = Mockery::mock(PortSyncService::class);
        $service->shouldNotReceive('syncSwitch');

        $job = new SyncSwitchPortsJob($switchConfig);
        $job->handle($service, $circuitBreaker);
    }

    public function test_job_records_success_on_circuit_breaker_after_sync(): void
    {
        Event::fake([SwitchSyncCompleted::class]);

        $switchConfig = SwitchConfig::factory()->create();
        $syncRun = SwitchSyncRun::factory()->completed()->create([
            'switch_config_id' => $switchConfig->id,
        ]);

        /** @var MockInterface&CircuitBreaker $circuitBreaker */
        $circuitBreaker = Mockery::mock(CircuitBreaker::class);
        $circuitBreaker->shouldReceive('isAvailable')
            ->with(Mockery::on(fn (SwitchConfig $config): bool => $config->is($switchConfig)))
            ->once()
            ->andReturn(true);
        $circuitBreaker->shouldReceive('recordSuccess')
            ->with(Mockery::on(fn (SwitchConfig $config): bool => $config->is($switchConfig)))
            ->once();

        $this->app->instance(CircuitBreaker::class, $circuitBreaker);

        /** @var MockInterface&PortSyncService $service */
        $service = Mockery::mock(PortSyncService::class);
        $service->shouldReceive('syncSwitch')
            ->with(Mockery::on(fn (SwitchConfig $config): bool => $config->is($switchConfig)))
            ->once()
            ->andReturn($syncRun);

        $job = new SyncSwitchPortsJob($switchConfig);
        $job->handle($service, $circuitBreaker);
    }

    public function test_job_records_failure_on_circuit_breaker_when_sync_throws(): void
    {
        Event::fake([SwitchUnreachable::class]);

        $switchConfig = SwitchConfig::factory()->create();

        /** @var MockInterface&CircuitBreaker $circuitBreaker */
        $circuitBreaker = Mockery::mock(CircuitBreaker::class);
        $circuitBreaker->shouldReceive('isAvailable')
            ->with(Mockery::on(fn (SwitchConfig $config): bool => $config->is($switchConfig)))
            ->once()
            ->andReturn(true);
        $circuitBreaker->shouldReceive('recordFailure')
            ->with(Mockery::on(fn (SwitchConfig $config): bool => $config->is($switchConfig)))
            ->once();

        $this->app->instance(CircuitBreaker::class, $circuitBreaker);

        /** @var MockInterface&PortSyncService $service */
        $service = Mockery::mock(PortSyncService::class);
        $service->shouldReceive('syncSwitch')
            ->once()
            ->andThrow(new RuntimeException('Connection refused'));

        $job = new SyncSwitchPortsJob($switchConfig);

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('Connection refused');

        $job->handle($service, $circuitBreaker);
    }

    public function test_circuit_breaker_does_not_affect_disabled_switch_check(): void
    {
        $switchConfig = SwitchConfig::factory()->disabled()->create();

        /** @var MockInterface&CircuitBreaker $circuitBreaker */
        $circuitBreaker = Mockery::mock(CircuitBreaker::class);
        $circuitBreaker->shouldNotReceive('isAvailable');
        $circuitBreaker->shouldNotReceive('recordSuccess');
        $circuitBreaker->shouldNotReceive('recordFailure');

        /** @var MockInterface&PortSyncService $service */
        $service = Mockery::mock(PortSyncService::class);
        $service->shouldNotReceive('syncSwitch');

        $job = new SyncSwitchPortsJob($switchConfig);
        $job->handle($service, $circuitBreaker);
    }
}
