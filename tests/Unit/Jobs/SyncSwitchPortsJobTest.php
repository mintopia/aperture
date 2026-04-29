<?php

declare(strict_types=1);

namespace Tests\Unit\Jobs;

use App\Events\PortStateChanged;
use App\Events\SwitchSyncCompleted;
use App\Jobs\SyncSwitchPortsJob;
use App\Models\SwitchConfig;
use App\Models\SwitchPort;
use App\Models\SwitchSyncRun;
use App\Services\NetworkSwitch\CircuitBreaker;
use App\Services\NetworkSwitch\PortSyncService;
use App\Services\NetworkSwitch\SyncResult;
use Illuminate\Contracts\Queue\ShouldBeUnique;
use Illuminate\Foundation\Testing\LazilyRefreshDatabase;
use Illuminate\Support\Facades\Event;
use Mockery;
use Mockery\MockInterface;
use RuntimeException;
use Tests\TestCase;

class SyncSwitchPortsJobTest extends TestCase
{
    use LazilyRefreshDatabase;

    public function test_job_dispatches_sync_service(): void
    {
        Event::fake([SwitchSyncCompleted::class, PortStateChanged::class]);

        $switchConfig = SwitchConfig::factory()->create();
        $syncRun = SwitchSyncRun::factory()->completed()->create([
            'switch_config_id' => $switchConfig->id,
        ]);
        $syncResult = new SyncResult(syncRun: $syncRun, portStateChanges: []);

        /** @var MockInterface&PortSyncService $service */
        $service = Mockery::mock(PortSyncService::class);
        $service->shouldReceive('syncSwitch')
            ->with(Mockery::on(fn (SwitchConfig $config): bool => $config->is($switchConfig)))
            ->once()
            ->andReturn($syncResult);

        /** @var MockInterface&CircuitBreaker $circuitBreaker */
        $circuitBreaker = Mockery::mock(CircuitBreaker::class);
        $circuitBreaker->shouldReceive('isAvailable')->andReturn(true);
        $circuitBreaker->shouldReceive('recordSuccess');

        $job = new SyncSwitchPortsJob($switchConfig);
        $job->handle($service, $circuitBreaker);
    }

    public function test_job_does_not_dispatch_switch_sync_completed_when_no_errors(): void
    {
        Event::fake([SwitchSyncCompleted::class, PortStateChanged::class]);

        $switchConfig = SwitchConfig::factory()->create();
        $syncRun = SwitchSyncRun::factory()->completed()->create([
            'switch_config_id' => $switchConfig->id,
            'ports_updated' => 12,
        ]);
        $syncResult = new SyncResult(syncRun: $syncRun, portStateChanges: []);

        /** @var MockInterface&PortSyncService $service */
        $service = Mockery::mock(PortSyncService::class);
        $service->shouldReceive('syncSwitch')->once()->andReturn($syncResult);

        /** @var MockInterface&CircuitBreaker $circuitBreaker */
        $circuitBreaker = Mockery::mock(CircuitBreaker::class);
        $circuitBreaker->shouldReceive('isAvailable')->andReturn(true);
        $circuitBreaker->shouldReceive('recordSuccess');

        $job = new SyncSwitchPortsJob($switchConfig);
        $job->handle($service, $circuitBreaker);

        Event::assertNotDispatched(SwitchSyncCompleted::class);
    }

    public function test_job_does_not_dispatch_events_on_failure(): void
    {
        Event::fake([SwitchSyncCompleted::class, PortStateChanged::class]);

        $switchConfig = SwitchConfig::factory()->create();

        /** @var MockInterface&PortSyncService $service */
        $service = Mockery::mock(PortSyncService::class);
        $service->shouldReceive('syncSwitch')->once()->andThrow(new RuntimeException('SSH error'));

        /** @var MockInterface&CircuitBreaker $circuitBreaker */
        $circuitBreaker = Mockery::mock(CircuitBreaker::class);
        $circuitBreaker->shouldReceive('isAvailable')->andReturn(true);
        $circuitBreaker->shouldReceive('recordFailure');

        $job = new SyncSwitchPortsJob($switchConfig);

        try {
            $job->handle($service, $circuitBreaker);
        } catch (RuntimeException) {
            // Expected
        }

        Event::assertNotDispatched(SwitchSyncCompleted::class);
        Event::assertNotDispatched(PortStateChanged::class);
    }

    public function test_job_implements_should_be_unique(): void
    {
        $switchConfig = SwitchConfig::factory()->make();
        $job = new SyncSwitchPortsJob($switchConfig);

        $this->assertInstanceOf(ShouldBeUnique::class, $job);
        $this->assertSame(300, $job->uniqueFor);
    }

    public function test_job_unique_id_is_switch_config_id(): void
    {
        $switchConfig = SwitchConfig::factory()->create();
        $job = new SyncSwitchPortsJob($switchConfig);

        $this->assertSame($switchConfig->id, $job->uniqueId());
    }

    public function test_job_has_correct_backoff(): void
    {
        $switchConfig = SwitchConfig::factory()->make();
        $job = new SyncSwitchPortsJob($switchConfig);

        $this->assertSame([1, 5, 10], $job->backoff());
    }

    public function test_job_has_correct_tries(): void
    {
        $switchConfig = SwitchConfig::factory()->make();
        $job = new SyncSwitchPortsJob($switchConfig);

        $this->assertSame(3, $job->tries);
    }

    public function test_job_has_correct_timeout(): void
    {
        $switchConfig = SwitchConfig::factory()->make();
        $job = new SyncSwitchPortsJob($switchConfig);

        $this->assertSame(120, $job->timeout);
    }

    public function test_job_handles_failure(): void
    {
        $switchConfig = SwitchConfig::factory()->create();
        $exception = new RuntimeException('Connection refused');

        $job = new SyncSwitchPortsJob($switchConfig);
        $job->failed($exception);

        // With no recent failure from the service, the job should create a failure record
        $this->assertDatabaseHas('switch_sync_runs', [
            'switch_config_id' => $switchConfig->id,
            'status' => 'failed',
            'error' => 'Connection refused',
        ]);

        $syncRun = SwitchSyncRun::where('switch_config_id', $switchConfig->id)->first();
        $this->assertNotNull($syncRun->started_at);
        $this->assertNotNull($syncRun->finished_at);
    }

    public function test_job_failed_skips_duplicate_when_service_already_recorded(): void
    {
        $switchConfig = SwitchConfig::factory()->create();

        // Simulate the service having already recorded a failure
        SwitchSyncRun::factory()->create([
            'switch_config_id' => $switchConfig->id,
            'status' => 'failed',
            'started_at' => now(),
            'finished_at' => now(),
            'error' => 'Connection refused',
        ]);

        $exception = new RuntimeException('Connection refused');

        $job = new SyncSwitchPortsJob($switchConfig);
        $job->failed($exception);

        // Should NOT create a duplicate — only the one from the service should exist
        $this->assertSame(
            1,
            SwitchSyncRun::where('switch_config_id', $switchConfig->id)
                ->where('status', 'failed')
                ->count()
        );
    }

    public function test_job_skips_disabled_switch(): void
    {
        $switchConfig = SwitchConfig::factory()->disabled()->create();

        /** @var MockInterface&PortSyncService $service */
        $service = Mockery::mock(PortSyncService::class);
        $service->shouldNotReceive('syncSwitch');

        /** @var MockInterface&CircuitBreaker $circuitBreaker */
        $circuitBreaker = Mockery::mock(CircuitBreaker::class);
        $circuitBreaker->shouldNotReceive('isAvailable');

        $job = new SyncSwitchPortsJob($switchConfig);
        $job->handle($service, $circuitBreaker);
    }

    public function test_job_dispatches_port_state_changed_for_each_change(): void
    {
        Event::fake([SwitchSyncCompleted::class, PortStateChanged::class]);

        $switchConfig = SwitchConfig::factory()->create();
        $syncRun = SwitchSyncRun::factory()->completed()->create([
            'switch_config_id' => $switchConfig->id,
        ]);

        $port1 = SwitchPort::factory()->create([
            'switch_config_id' => $switchConfig->id,
            'port_name' => 'Gi1/0/1',
            'status' => 'connected',
        ]);
        $port2 = SwitchPort::factory()->create([
            'switch_config_id' => $switchConfig->id,
            'port_name' => 'Gi1/0/2',
            'status' => 'connected',
        ]);

        $syncResult = new SyncResult(
            syncRun: $syncRun,
            portStateChanges: [
                ['switchPort' => $port1, 'oldStatus' => 'notconnect', 'newStatus' => 'connected'],
                ['switchPort' => $port2, 'oldStatus' => 'connected', 'newStatus' => 'notconnect'],
            ],
        );

        /** @var MockInterface&PortSyncService $service */
        $service = Mockery::mock(PortSyncService::class);
        $service->shouldReceive('syncSwitch')->once()->andReturn($syncResult);

        /** @var MockInterface&CircuitBreaker $circuitBreaker */
        $circuitBreaker = Mockery::mock(CircuitBreaker::class);
        $circuitBreaker->shouldReceive('isAvailable')->andReturn(true);
        $circuitBreaker->shouldReceive('recordSuccess');

        $job = new SyncSwitchPortsJob($switchConfig);
        $job->handle($service, $circuitBreaker);

        Event::assertDispatched(PortStateChanged::class, 2);
        Event::assertDispatched(PortStateChanged::class, function (PortStateChanged $event) use ($port1): bool {
            return $event->switchPort->is($port1)
                && $event->oldStatus === 'notconnect'
                && $event->newStatus === 'connected';
        });
        Event::assertDispatched(PortStateChanged::class, function (PortStateChanged $event) use ($port2): bool {
            return $event->switchPort->is($port2)
                && $event->oldStatus === 'connected'
                && $event->newStatus === 'notconnect';
        });
    }

    public function test_job_does_not_dispatch_events_when_nothing_changed(): void
    {
        Event::fake([SwitchSyncCompleted::class, PortStateChanged::class]);

        $switchConfig = SwitchConfig::factory()->create();
        $syncRun = SwitchSyncRun::factory()->completed()->create([
            'switch_config_id' => $switchConfig->id,
        ]);
        $syncResult = new SyncResult(syncRun: $syncRun, portStateChanges: []);

        /** @var MockInterface&PortSyncService $service */
        $service = Mockery::mock(PortSyncService::class);
        $service->shouldReceive('syncSwitch')->once()->andReturn($syncResult);

        /** @var MockInterface&CircuitBreaker $circuitBreaker */
        $circuitBreaker = Mockery::mock(CircuitBreaker::class);
        $circuitBreaker->shouldReceive('isAvailable')->andReturn(true);
        $circuitBreaker->shouldReceive('recordSuccess');

        $job = new SyncSwitchPortsJob($switchConfig);
        $job->handle($service, $circuitBreaker);

        Event::assertNotDispatched(SwitchSyncCompleted::class);
        Event::assertNotDispatched(PortStateChanged::class);
    }
}
