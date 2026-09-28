<?php

declare(strict_types=1);

namespace App\Jobs;

use App\Enums\Capability;
use App\Models\CapabilityAssignment;
use App\Services\Dhcp\DhcpSyncService;
use App\Services\Interfaces\DhcpInterface;
use App\Support\Queues;
use Illuminate\Contracts\Queue\ShouldBeUnique;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Support\Facades\Log;

class SyncDhcpData implements ShouldBeUnique, ShouldQueue
{
    use \Illuminate\Foundation\Queue\Queueable;

    public int $timeout = 120;

    public int $uniqueFor = 300;

    public int $tries = 3;

    /** @var list<int> */
    public array $backoff = [30, 60];

    public function __construct()
    {
        $this->onQueue(Queues::SYNC);
    }

    public function handle(DhcpInterface $dhcp, DhcpSyncService $syncService): void
    {
        $assignment = CapabilityAssignment::where('capability', Capability::Dhcp->value)->first();
        if ($assignment === null) {
            Log::info('SyncDhcpData: no DHCP capability assigned, skipping');

            return;
        }

        $integration = $assignment->integration;

        Log::info('SyncDhcpData: starting sync', ['integration' => $integration]);

        $syncService->sync($integration, $dhcp->snapshot());
    }
}
