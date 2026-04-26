<?php

declare(strict_types=1);

namespace App\Events;

use App\Models\IpAddress;
use App\Models\User;
use Illuminate\Broadcasting\InteractsWithSockets;
use Illuminate\Broadcasting\PrivateChannel;
use Illuminate\Contracts\Broadcasting\ShouldBroadcast;
use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;

class RateLimitChanged implements ShouldBroadcast
{
    use Dispatchable;
    use InteractsWithSockets;
    use SerializesModels;

    /**
     * Create a new event instance.
     */
    public function __construct(
        public IpAddress $ipAddress,
        public ?int $oldLimit,
        public int $newLimit,
        public ?User $changedBy,
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
            'ip_address_id' => $this->ipAddress->id,
            'ip_address' => $this->ipAddress->ip_address,
            'old_limit' => $this->oldLimit,
            'new_limit' => $this->newLimit,
            'changed_by_id' => $this->changedBy?->id,
        ];
    }
}
