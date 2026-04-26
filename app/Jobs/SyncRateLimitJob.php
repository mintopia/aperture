<?php

declare(strict_types=1);

namespace App\Jobs;

use App\Models\IpAddress;
use App\Services\IpAddressActionService;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Facades\Log;
use Throwable;

class SyncRateLimitJob implements ShouldQueue
{
    use Queueable;

    public int $tries = 3;

    public int $timeout = 30;

    public function __construct(
        public readonly IpAddress $ip,
        public readonly bool $enabled,
    ) {}

    /**
     * @return list<int>
     */
    public function backoff(): array
    {
        return [2, 10, 30];
    }

    public function handle(IpAddressActionService $actionService): void
    {
        if ($this->enabled) {
            $actionService->enableRateLimit($this->ip);
        } else {
            $actionService->disableRateLimit($this->ip);
        }
    }

    public function failed(Throwable $exception): void
    {
        Log::error('SyncRateLimitJob failed', [
            'ip' => $this->ip->address,
            'enabled' => $this->enabled,
            'error' => $exception->getMessage(),
        ]);
    }
}
