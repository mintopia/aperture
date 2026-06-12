<?php

declare(strict_types=1);

namespace App\Listeners;

use App\Events\BandwidthAnomalyDetected;
use App\Events\DhcpPoolThresholdReached;
use App\Events\SwitchUnreachable;
use App\Models\AuditLog;
use Illuminate\Contracts\Broadcasting\ShouldBroadcast;
use Illuminate\Events\Dispatcher;
use Throwable;

class RecordBroadcastEvent
{
    public function subscribe(Dispatcher $events): void
    {
        $events->listen('*', [self::class, 'handleWildcard']);
    }

    /**
     * @param  list<mixed>  $payload
     */
    public function handleWildcard(string $eventName, array $payload): void
    {
        $event = $payload[0] ?? null;

        if (! $event instanceof ShouldBroadcast) {
            return;
        }

        $this->handleBroadcastEvent($event);
    }

    public function handleBroadcastEvent(ShouldBroadcast $event): void
    {
        try {
            match (true) {
                $event instanceof SwitchUnreachable => AuditLog::record(
                    action: 'switch.unreachable',
                    subject: $event->switchConfig,
                    process: 'network',
                    metadata: $event->broadcastWith(),
                    severity: 'critical',
                ),
                $event instanceof BandwidthAnomalyDetected => AuditLog::record(
                    action: 'bandwidth.anomaly',
                    process: 'network',
                    metadata: $event->broadcastWith(),
                    severity: 'warning',
                ),
                $event instanceof DhcpPoolThresholdReached => AuditLog::record(
                    action: 'dhcp.threshold_reached',
                    process: 'network',
                    metadata: $event->broadcastWith(),
                    severity: 'warning',
                ),
                default => null,
            };
        } catch (Throwable $throwable) {
            report($throwable);
        }
    }
}
