<?php

declare(strict_types=1);

namespace App\Events;

use App\Models\SwitchConfig;
use Illuminate\Broadcasting\InteractsWithSockets;
use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;

class SwitchUnreachable
{
    use Dispatchable;
    use InteractsWithSockets;
    use SerializesModels;

    /**
     * Create a new event instance.
     */
    public function __construct(
        public SwitchConfig $switchConfig,
        public int $failureCount,
    ) {}
}
