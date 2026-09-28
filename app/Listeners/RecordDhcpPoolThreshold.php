<?php

declare(strict_types=1);

namespace App\Listeners;

use App\Events\DhcpPoolThresholdReached;
use App\Models\AuditLog;
use App\Support\Queues;
use Illuminate\Contracts\Queue\ShouldQueue;

class RecordDhcpPoolThreshold implements ShouldQueue
{
    public string $queue = Queues::ACCESS;

    public function handle(DhcpPoolThresholdReached $event): void
    {
        AuditLog::record(
            action: 'dhcp.threshold_reached',
            process: 'network',
            metadata: $event->broadcastWith(),
            severity: 'warning',
        );
    }
}
