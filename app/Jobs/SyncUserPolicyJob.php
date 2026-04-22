<?php

declare(strict_types=1);

namespace App\Jobs;

use App\Models\IpAddress;
use App\Models\User;
use App\Services\IpPolicyService;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;

class SyncUserPolicyJob implements ShouldQueue
{
    use Queueable;

    public function __construct(
        public readonly User $user,
        public readonly IpAddress $ip,
    ) {}

    public function handle(IpPolicyService $policyService): void
    {
        $policyService->applyUserPolicy($this->user, $this->ip);
    }
}
