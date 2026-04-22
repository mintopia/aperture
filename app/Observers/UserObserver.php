<?php

declare(strict_types=1);

namespace App\Observers;

use App\Jobs\SyncUserPolicyJob;
use App\Models\User;

class UserObserver
{
    /** @var list<string> */
    protected array $policyFields = [
        'internet_enabled',
        'rate_limit_enabled',
        'dns_filtering_enabled',
        'internet_blocked',
    ];

    public function updated(User $user): void
    {
        $changed = array_intersect($this->policyFields, array_keys($user->getChanges()));

        if ($changed === []) {
            return;
        }

        $userIps = $user->ips()->with('ip')->get();

        foreach ($userIps as $userIp) {
            if ($userIp->ip) {
                SyncUserPolicyJob::dispatch($user, $userIp->ip);
            }
        }
    }
}
