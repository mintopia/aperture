<?php

declare(strict_types=1);

namespace App\Events;

use App\Models\IpAddress;
use App\Models\MacAddress;
use App\Models\SwitchPort;
use Illuminate\Broadcasting\InteractsWithSockets;
use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;

class DeviceDiscovered
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
}
