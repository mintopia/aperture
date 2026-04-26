<?php

declare(strict_types=1);

namespace App\Events;

use App\Models\SwitchConfig;
use Illuminate\Broadcasting\InteractsWithSockets;
use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;

class SwitchSyncCompleted
{
    use Dispatchable;
    use InteractsWithSockets;
    use SerializesModels;

    /**
     * Create a new event instance.
     *
     * @param  array<int, string>  $errors
     */
    public function __construct(
        public SwitchConfig $switchConfig,
        public int $portsUpdated,
        public array $errors,
    ) {}
}
