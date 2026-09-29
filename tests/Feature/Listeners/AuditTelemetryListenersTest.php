<?php

declare(strict_types=1);

namespace Tests\Feature\Listeners;

use App\Events\BandwidthAnomalyDetected;
use App\Events\DhcpPoolThresholdReached;
use App\Events\InternetAccessChanged;
use App\Events\SwitchUnreachable;
use App\Listeners\RecordBandwidthAnomaly;
use App\Listeners\RecordDhcpPoolThreshold;
use App\Listeners\RecordSwitchUnreachable;
use App\Models\AuditLog;
use App\Models\SwitchConfig;
use App\Support\Queues;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Events\CallQueuedListener;
use Illuminate\Foundation\Testing\LazilyRefreshDatabase;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Queue;
use ReflectionClass;
use Tests\TestCase;

class AuditTelemetryListenersTest extends TestCase
{
    use LazilyRefreshDatabase;

    public function test_listeners_are_queued_on_access_queue(): void
    {
        foreach ([RecordSwitchUnreachable::class, RecordBandwidthAnomaly::class, RecordDhcpPoolThreshold::class] as $class) {
            $listener = new $class;
            $this->assertInstanceOf(ShouldQueue::class, $listener);
            $this->assertSame(Queues::ACCESS, $listener->queue);
        }
    }

    public function test_listeners_are_registered_for_their_events_only(): void
    {
        $this->assertTrue(Event::hasListeners(SwitchUnreachable::class));
        $this->assertTrue(Event::hasListeners(BandwidthAnomalyDetected::class));
        $this->assertTrue(Event::hasListeners(DhcpPoolThresholdReached::class));
        $this->assertFalse(Event::hasListeners(InternetAccessChanged::class));
        $this->assertArrayNotHasKey('*', $this->wildcards());
    }

    public function test_dispatching_switch_unreachable_queues_listener(): void
    {
        Queue::fake();

        event(new SwitchUnreachable(SwitchConfig::factory()->create(), 3));

        Queue::assertPushedOn(Queues::ACCESS, CallQueuedListener::class, fn ($job): bool => $job->class === RecordSwitchUnreachable::class);
        $this->assertSame(0, AuditLog::count());
    }

    public function test_dispatching_bandwidth_anomaly_queues_listener(): void
    {
        Queue::fake();

        event(new BandwidthAnomalyDetected('10.0.0.5', 'alice', 1, 5000.0, 1000.0, 5.0, 3.0));

        Queue::assertPushedOn(Queues::ACCESS, CallQueuedListener::class, fn ($job): bool => $job->class === RecordBandwidthAnomaly::class);
    }

    public function test_dispatching_dhcp_threshold_queues_listener(): void
    {
        Queue::fake();

        event(new DhcpPoolThresholdReached(pool: 'lan', usage: 92.0, threshold: 90.0, addressFamily: 'ipv4'));

        Queue::assertPushedOn(Queues::ACCESS, CallQueuedListener::class, fn ($job): bool => $job->class === RecordDhcpPoolThreshold::class);
    }

    public function test_switch_unreachable_records_critical_audit_with_subject(): void
    {
        $switch = SwitchConfig::factory()->create(['name' => 'core-sw']);

        (new RecordSwitchUnreachable)->handle(new SwitchUnreachable($switch, 3));

        $log = AuditLog::where('action', 'switch.unreachable')->firstOrFail();
        $this->assertSame('critical', $log->severity);
        $this->assertSame('network', $log->process);
        $this->assertSame($switch->getMorphClass(), $log->subject_type);
        $this->assertSame($switch->id, $log->subject_id);
        $this->assertNotNull($log->metadata);
        $this->assertSame(3, $log->metadata['failure_count']);
    }

    public function test_bandwidth_anomaly_records_warning_audit(): void
    {
        (new RecordBandwidthAnomaly)->handle(
            new BandwidthAnomalyDetected('10.0.0.5', 'alice', 1, 5000.0, 1000.0, 5.0, 3.0)
        );

        $log = AuditLog::where('action', 'bandwidth.anomaly')->firstOrFail();
        $this->assertSame('warning', $log->severity);
        $this->assertNull($log->subject_type);
        $this->assertNotNull($log->metadata);
        $this->assertSame('10.0.0.5', $log->metadata['ip_address']);
    }

    public function test_dhcp_threshold_records_warning_audit(): void
    {
        (new RecordDhcpPoolThreshold)->handle(
            new DhcpPoolThresholdReached(pool: 'lan', usage: 92.0, threshold: 90.0, addressFamily: 'ipv4')
        );

        $log = AuditLog::where('action', 'dhcp.threshold_reached')->firstOrFail();
        $this->assertSame('warning', $log->severity);
        $this->assertNotNull($log->metadata);
        $this->assertSame('lan', $log->metadata['pool']);
    }

    /**
     * @return array<string, mixed>
     */
    private function wildcards(): array
    {
        $property = (new ReflectionClass(Event::getFacadeRoot()))->getProperty('wildcards');

        return $property->getValue(Event::getFacadeRoot());
    }
}
