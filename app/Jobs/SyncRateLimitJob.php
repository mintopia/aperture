<?php

declare(strict_types=1);

namespace App\Jobs;

use App\Models\IpAddress;
use App\Services\IpAddressActionService;
use App\Support\Queues;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Queue\Middleware\WithoutOverlapping;
use Illuminate\Support\Facades\Log;
use Throwable;

class SyncRateLimitJob implements ShouldQueue
{
    use Queueable;

    public int $tries = 3;

    public int $timeout = 30;

    public function __construct(
        public readonly IpAddress $ip,
    ) {
        $this->onQueue(Queues::ACCESS);
    }

    /**
     * @return list<int>
     */
    public function backoff(): array
    {
        return [2, 10, 30];
    }

    /**
     * @return list<WithoutOverlapping>
     */
    public function middleware(): array
    {
        return [(new WithoutOverlapping(static::class.':'.$this->ip->address))->releaseAfter(5)->expireAfter(60)];
    }

    public function handle(IpAddressActionService $actionService): void
    {
        $ip = $this->ip->fresh();
        if ($ip === null) {
            return;
        }

        if ($ip->rate_limit_enabled) {
            $actionService->enableRateLimit($ip);
        } else {
            $actionService->disableRateLimit($ip);
        }
    }

    public function failed(Throwable $exception): void
    {
        Log::error('SyncRateLimitJob failed', [
            'ip' => $this->ip->address,
            'error' => $exception->getMessage(),
        ]);
    }
}
