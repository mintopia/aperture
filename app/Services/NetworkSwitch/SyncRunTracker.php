<?php

declare(strict_types=1);

namespace App\Services\NetworkSwitch;

use App\Models\SwitchConfig;
use App\Models\SwitchSyncRun;

class SyncRunTracker
{
    public function start(SwitchConfig $switchConfig): SwitchSyncRun
    {
        return SwitchSyncRun::create([
            'switch_config_id' => $switchConfig->id,
            'status' => 'running',
            'started_at' => now(),
        ]);
    }

    public function complete(
        SwitchSyncRun $run,
        int $portsCreated,
        int $portsUpdated,
        int $macsCreated,
        int $macsUpdated,
    ): void {
        $run->update([
            'status' => 'completed',
            'finished_at' => now(),
            'ports_created' => $portsCreated,
            'ports_updated' => $portsUpdated,
            'macs_created' => $macsCreated,
            'macs_updated' => $macsUpdated,
        ]);
    }

    public function fail(SwitchSyncRun $run, string $error): void
    {
        $run->update([
            'status' => 'failed',
            'finished_at' => now(),
            'error' => $error,
        ]);
    }

    /**
     * Clean up stale sync runs that have been stuck in 'running' state for more than 5 minutes.
     */
    public function cleanStale(SwitchConfig $switchConfig): void
    {
        SwitchSyncRun::where('switch_config_id', $switchConfig->id)
            ->where('status', 'running')
            ->where('started_at', '<', now()->subMinutes(5))
            ->update([
                'status' => 'failed',
                'finished_at' => now(),
                'error' => 'Sync timed out (stale run cleanup)',
            ]);
    }
}
