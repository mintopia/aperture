<?php

declare(strict_types=1);

namespace App\Jobs;

use App\Events\PortStateChanged;
use App\Models\SwitchConfig;
use App\Models\SwitchSyncRun;
use App\Services\NetworkSwitch\CircuitBreaker;
use App\Services\NetworkSwitch\PortSyncService;
use Illuminate\Contracts\Queue\ShouldBeUnique;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Throwable;

class SyncSwitchPortsJob implements ShouldBeUnique, ShouldQueue
{
    use Queueable;

    public int $tries = 3;

    public int $timeout = 120;

    public int $uniqueFor = 300;

    public function __construct(
        public SwitchConfig $switchConfig,
    ) {}

    public function uniqueId(): int|string
    {
        return $this->switchConfig->id;
    }

    /**
     * @return list<int>
     */
    public function backoff(): array
    {
        return [1, 5, 10];
    }

    public function handle(PortSyncService $syncService, CircuitBreaker $circuitBreaker): void
    {
        if (! $this->switchConfig->enabled) {
            return;
        }

        if (! $circuitBreaker->isAvailable($this->switchConfig)) {
            return;
        }

        try {
            $result = $syncService->syncSwitch($this->switchConfig);
            $circuitBreaker->recordSuccess($this->switchConfig);

            foreach ($result->portStateChanges as $change) {
                PortStateChanged::dispatch(
                    $change['switchPort'],
                    $change['oldStatus'],
                    $change['newStatus'],
                );
            }
        } catch (Throwable $throwable) {
            $circuitBreaker->recordFailure($this->switchConfig);

            throw $throwable;
        }
    }

    public function failed(Throwable $exception): void
    {
        // Only create a failure record if the service didn't already record one
        $hasRecentFailure = SwitchSyncRun::where('switch_config_id', $this->switchConfig->id)
            ->where('status', 'failed')
            ->where('finished_at', '>=', now()->subMinutes(5))
            ->exists();

        if (! $hasRecentFailure) {
            SwitchSyncRun::create([
                'switch_config_id' => $this->switchConfig->id,
                'status' => 'failed',
                'started_at' => now(),
                'finished_at' => now(),
                'error' => $exception->getMessage(),
            ]);
        }
    }
}
