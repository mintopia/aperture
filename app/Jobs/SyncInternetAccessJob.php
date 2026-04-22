<?php

declare(strict_types=1);

namespace App\Jobs;

use App\Models\IpAddress;
use App\Services\IpAddressActionService;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;

class SyncInternetAccessJob implements ShouldQueue
{
    use Queueable;

    public function __construct(
        public readonly IpAddress $ip,
        public readonly bool $enabled,
    ) {}

    public function handle(IpAddressActionService $actionService): void
    {
        if ($this->enabled) {
            $actionService->enableInternet($this->ip);
        } else {
            $actionService->disableInternet($this->ip);
        }
    }
}
