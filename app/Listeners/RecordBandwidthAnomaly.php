<?php

declare(strict_types=1);

namespace App\Listeners;

use App\Events\BandwidthAnomalyDetected;
use App\Models\AuditLog;
use App\Support\Queues;
use Illuminate\Contracts\Queue\ShouldQueue;

class RecordBandwidthAnomaly implements ShouldQueue
{
    public string $queue = Queues::ACCESS;

    public function handle(BandwidthAnomalyDetected $event): void
    {
        AuditLog::record(
            action: 'bandwidth.anomaly',
            process: 'network',
            metadata: $event->broadcastWith(),
            severity: 'warning',
        );
    }
}
