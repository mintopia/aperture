<?php

declare(strict_types=1);

namespace App\Events;

use App\Models\SwitchPort;
use Illuminate\Broadcasting\InteractsWithSockets;
use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;

class PortStateChanged
{
    use Dispatchable;
    use InteractsWithSockets;
    use SerializesModels;

    /**
     * Create a new event instance.
     */
    public function __construct(
        public SwitchPort $switchPort,
        public ?string $oldStatus,
        public string $newStatus,
    ) {}
}
