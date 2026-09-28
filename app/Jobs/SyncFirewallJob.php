<?php

declare(strict_types=1);

namespace App\Jobs;

use App\Enums\FirewallAction;
use App\Models\IpAddress;
use App\Services\IpAddressActionService;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Facades\Log;
use Throwable;

class SyncFirewallJob implements ShouldQueue
{
    use Queueable;

    public int $tries = 3;

    public int $timeout = 30;

    public function __construct(
        public readonly IpAddress $ip,
        public readonly FirewallAction $action,
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
        match ($this->action) {
            FirewallAction::Internet => $this->enabled
                ? $actionService->enableInternet($this->ip)
                : $actionService->disableInternet($this->ip),
            FirewallAction::RateLimit => $this->enabled
                ? $actionService->enableRateLimit($this->ip)
                : $actionService->disableRateLimit($this->ip),
        };
    }

    public function failed(Throwable $exception): void
    {
        Log::error('SyncFirewallJob failed', [
            'action' => $this->action->value,
            'ip' => $this->ip->address,
            'enabled' => $this->enabled,
            'error' => $exception->getMessage(),
        ]);
    }
}
