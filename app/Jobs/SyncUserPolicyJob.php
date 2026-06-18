<?php

declare(strict_types=1);

namespace App\Jobs;

use App\Models\IpAddress;
use App\Models\User;
use App\Services\IpPolicyService;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Facades\Log;
use Throwable;

class SyncUserPolicyJob implements ShouldQueue
{
    use Queueable;

    public int $tries = 3;

    public int $timeout = 30;

    public function __construct(
        public readonly User $user,
        public readonly IpAddress $ip,
    ) {}

    /**
     * @return list<int>
     */
    public function backoff(): array
    {
        return [2, 10, 30];
    }

    public function handle(IpPolicyService $policyService): void
    {
        $policyService->applyUserPolicy($this->user, $this->ip);
    }

    public function failed(Throwable $exception): void
    {
        Log::error('SyncUserPolicyJob failed', [
            'user_id' => $this->user->id,
            'ip' => $this->ip->address,
            'error' => $exception->getMessage(),
        ]);
    }
}
