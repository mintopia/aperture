<?php

declare(strict_types=1);

namespace App\Events;

use App\Models\SwitchConfig;
use Illuminate\Broadcasting\InteractsWithSockets;
use Illuminate\Broadcasting\PrivateChannel;
use Illuminate\Contracts\Broadcasting\ShouldBroadcast;
use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;

class SwitchUnreachable implements ShouldBroadcast
{
    use Dispatchable;
    use InteractsWithSockets;
    use SerializesModels;

    public function __construct(
        public SwitchConfig $switchConfig,
        public int $failureCount,
    ) {}

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
            'switch_config_id' => $this->switchConfig->id,
            'hostname' => $this->switchConfig->hostname,
            'failure_count' => $this->failureCount,
        ];
    }
}
