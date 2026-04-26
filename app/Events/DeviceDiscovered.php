<?php

declare(strict_types=1);

namespace App\Events;

use App\Models\IpAddress;
use App\Models\MacAddress;
use App\Models\SwitchPort;
use Illuminate\Broadcasting\InteractsWithSockets;
use Illuminate\Broadcasting\PrivateChannel;
use Illuminate\Contracts\Broadcasting\ShouldBroadcast;
use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;

class DeviceDiscovered implements ShouldBroadcast
{
    use Dispatchable;
    use InteractsWithSockets;
    use SerializesModels;

    /**
     * Create a new event instance.
     */
    public function __construct(
        public MacAddress $macAddress,
        public ?IpAddress $ipAddress,
        public ?SwitchPort $switchPort,
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
            'mac_address' => $this->macAddress->mac_address,
            'ip_address' => $this->ipAddress?->ip_address,
            'switch_port_id' => $this->switchPort?->id,
        ];
    }
}
