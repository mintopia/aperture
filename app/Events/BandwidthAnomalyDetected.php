<?php

declare(strict_types=1);

namespace App\Events;

use Illuminate\Broadcasting\InteractsWithSockets;
use Illuminate\Broadcasting\PrivateChannel;
use Illuminate\Contracts\Broadcasting\ShouldBroadcast;
use Illuminate\Foundation\Events\Dispatchable;

class BandwidthAnomalyDetected implements ShouldBroadcast
{
    use Dispatchable;
    use InteractsWithSockets;

    /**
     * Create a new event instance.
     */
    public function __construct(
        public string $ipAddress,
        public ?string $userName,
        public ?int $userId,
        public float $shortTermAvg,
        public float $longTermAvg,
        public float $ratio,
        public float $threshold,
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
            'ip_address' => $this->ipAddress,
            'user_name' => $this->userName,
            'user_id' => $this->userId,
            'short_term_avg' => $this->shortTermAvg,
            'long_term_avg' => $this->longTermAvg,
            'ratio' => $this->ratio,
            'threshold' => $this->threshold,
        ];
    }
}
