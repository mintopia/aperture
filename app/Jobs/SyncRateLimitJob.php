<?php

declare(strict_types=1);

namespace App\Jobs;

use App\Models\IpAddress;
use App\Services\IpAddressActionService;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;

class SyncRateLimitJob implements ShouldQueue
{
    use Queueable;

    public function __construct(
        public readonly IpAddress $ip,
        public readonly bool $enabled,
    ) {}

    public function handle(IpAddressActionService $actionService): void
    {
        if ($this->enabled) {
            $actionService->enableRateLimit($this->ip);
        } else {
            $actionService->disableRateLimit($this->ip);
        }
    }
}
