<?php

namespace App\Jobs;

use App\Models\IpAddress;
use App\Models\UserIpAddress;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Facades\Log;
use Throwable;

class ReapplyAccessRules implements ShouldQueue
{
    use Queueable;

    public int $tries = 1;

    public function handle(): void
    {
        $allowedIps = IpAddress::whereAllowed(true)->get();

        foreach ($allowedIps as $ip) {
            try {
                /** @var UserIpAddress|null $userIp */
                $userIp = $ip->users()->first();
                $description = $userIp?->user->nickname ?? $ip->address;
                $ip->allow();
            } catch (Throwable $e) {
                Log::warning('Failed to reapply access rule', [
                    'ip' => $ip->address,
                    'error' => $e->getMessage(),
                ]);
            }
        }

        Log::info('Access rules reapplied', [
            'count' => $allowedIps->count(),
        ]);
    }
}
