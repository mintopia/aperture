<?php

declare(strict_types=1);

namespace App\Listeners;

use App\Events\SwitchUnreachable;
use App\Models\AuditLog;
use App\Support\Queues;
use Illuminate\Contracts\Queue\ShouldQueue;

class RecordSwitchUnreachable implements ShouldQueue
{
    public string $queue = Queues::ACCESS;

    public function handle(SwitchUnreachable $event): void
    {
        AuditLog::record(
            action: 'switch.unreachable',
            subject: $event->switchConfig,
            process: 'network',
            metadata: $event->broadcastWith(),
            severity: 'critical',
        );
    }
}
