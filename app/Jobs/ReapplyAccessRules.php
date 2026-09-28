<?php

declare(strict_types=1);

namespace App\Jobs;

use App\Models\IpAddress;
use App\Support\Queues;
use Illuminate\Contracts\Queue\ShouldBeUnique;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Facades\Log;
use Throwable;

class ReapplyAccessRules implements ShouldBeUnique, ShouldQueue
{
    use Queueable;

    public int $tries = 3;

    public int $timeout = 120;

    public int $uniqueFor = 300;

    public function __construct()
    {
        $this->onQueue(Queues::SYNC);
    }

    /**
     * @return list<int>
     */
    public function backoff(): array
    {
        return [10, 30, 60];
    }

    public function handle(): void
    {
        $allowedIps = IpAddress::where('internet_enabled', true)->get();

        foreach ($allowedIps as $ip) {
            try {
                $ip->internet_enabled = true;
                $ip->save();
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

    public function failed(Throwable $exception): void
    {
        Log::error('ReapplyAccessRules failed', [
            'error' => $exception->getMessage(),
        ]);
    }
}
