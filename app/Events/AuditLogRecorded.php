<?php

declare(strict_types=1);

namespace App\Events;

use App\Models\AuditLog;
use App\Services\AuditLog\AuditLogDescriptionGenerator;
use Illuminate\Broadcasting\InteractsWithSockets;
use Illuminate\Broadcasting\PrivateChannel;
use Illuminate\Contracts\Broadcasting\ShouldBroadcast;
use Illuminate\Foundation\Events\Dispatchable;

class AuditLogRecorded implements ShouldBroadcast
{
    use Dispatchable;
    use InteractsWithSockets;

    public function __construct(public AuditLog $log) {}

    /**
     * @return array<int, PrivateChannel>
     */
    public function broadcastOn(): array
    {
        return [
            new PrivateChannel('admin.events'),
        ];
    }

    /**
     * @return array<string, mixed>
     */
    public function broadcastWith(): array
    {
        return [
            'id' => $this->log->id,
            'action' => $this->log->action,
            'description' => AuditLogDescriptionGenerator::generate($this->log),
            'severity' => $this->log->severity,
            'created_at' => $this->log->created_at->toIso8601String(),
        ];
    }
}
