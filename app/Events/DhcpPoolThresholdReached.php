<?php

declare(strict_types=1);

namespace App\Events;

use App\Enums\AddressFamily;
use Illuminate\Broadcasting\InteractsWithSockets;
use Illuminate\Broadcasting\PrivateChannel;
use Illuminate\Contracts\Broadcasting\ShouldBroadcast;
use Illuminate\Foundation\Events\Dispatchable;

class DhcpPoolThresholdReached implements ShouldBroadcast
{
    use Dispatchable;
    use InteractsWithSockets;

    public function __construct(
        public string $pool,
        public float $usage,
        public float $threshold,
        public string $addressFamily = AddressFamily::IPv4->value,
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
            'pool' => $this->pool,
            'usage' => $this->usage,
            'threshold' => $this->threshold,
            'address_family' => $this->addressFamily,
        ];
    }
}
