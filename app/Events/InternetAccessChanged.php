<?php

declare(strict_types=1);

namespace App\Events;

use App\Models\IpAddress;
use App\Models\User;
use Illuminate\Broadcasting\InteractsWithSockets;
use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;

class InternetAccessChanged
{
    use Dispatchable;
    use InteractsWithSockets;
    use SerializesModels;

    /**
     * Create a new event instance.
     */
    public function __construct(
        public IpAddress $ipAddress,
        public bool $enabled,
        public ?User $changedBy,
    ) {}
}
