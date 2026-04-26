<?php

declare(strict_types=1);

namespace App\Events;

use App\Models\SwitchConfig;
use Illuminate\Broadcasting\InteractsWithSockets;
use Illuminate\Broadcasting\PrivateChannel;
use Illuminate\Contracts\Broadcasting\ShouldBroadcast;
use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;

class SwitchSyncCompleted implements ShouldBroadcast
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

    /**
     * Get the channels the event should broadcast on.
     *
     * @return array<int, PrivateChannel>
     */
    public function broadcastOn(): array
    {
        return [
            new PrivateChannel('admin.events'),
        ];
    }

    /**
     * Get the data to broadcast.
     *
     * @return array<string, mixed>
     */
    public function broadcastWith(): array
    {
        return [
            'switch_config_id' => $this->switchConfig->id,
            'hostname' => $this->switchConfig->hostname,
            'ports_updated' => $this->portsUpdated,
            'errors' => $this->errors,
        ];
    }
}
