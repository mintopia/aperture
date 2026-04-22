<?php

declare(strict_types=1);

namespace App\Jobs;

use App\Events\IpAllowed;
use App\Models\IpAddress;
use App\Models\User;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Facades\Log;
use Throwable;

class GrantNetworkAccess implements ShouldQueue
{
    use Queueable;

    public int $tries = 3;

    /** @var array<int, int> */
    public array $backoff = [10, 30, 60];

    public function __construct(
        protected User $user,
        protected IpAddress $ipAddress,
    ) {}

    public function handle(): void
    {
        $this->ipAddress->internet_enabled = true;
        $this->ipAddress->save();

        IpAllowed::dispatch();

        Log::info('Network access granted', [
            'user_id' => $this->user->id,
            'ip' => $this->ipAddress->address,
        ]);
    }

    public function failed(Throwable $exception): void
    {
        Log::error('Failed to grant network access', [
            'user_id' => $this->user->id,
            'ip' => $this->ipAddress->address,
            'error' => $exception->getMessage(),
        ]);
    }
}
