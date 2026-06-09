<?php

declare(strict_types=1);

namespace App\Services\NetworkSwitch;

use App\Models\DhcpSnoopingObservation;
use App\Models\MacAddress;
use App\Models\SwitchConfig;
use App\Models\SwitchPort;
use App\Services\Interfaces\NetworkSwitchInterface;
use App\Services\Interfaces\SupportsDhcpSnooping;
use Illuminate\Support\Facades\DB;
use Throwable;

class PortSyncService
{
    public function __construct(
        protected SwitchServiceFactory $factory,
        protected SyncRunTracker $runTracker,
        protected PortStatusSync $portStatusSync,
        protected PortMacSync $portMacSync,
        protected PortConfigSync $portConfigSync,
    ) {}

    public function syncSwitch(SwitchConfig $switchConfig): SyncResult
    {
        $this->runTracker->cleanStale($switchConfig);

        $syncStartedAt = now();
        $syncRun = $this->runTracker->start($switchConfig);

        /** @var array<int, array{switchPort: SwitchPort, oldStatus: ?string, newStatus: string}> $portStateChanges */
        $portStateChanges = [];

        try {
            $adapter = $this->factory->make($switchConfig);

            // ── Fetch all network data BEFORE opening a DB transaction ──────
            // This prevents long-held DB locks during slow SSH operations.
            $portStatuses = $adapter->getAllPorts();
            $portConfigData = $this->portConfigSync->fetchFromNetwork($adapter, $switchConfig, $portStatuses);
            $macEntries = $adapter->getForwardingDatabase();
            // ────────────────────────────────────────────────────────────────

            $portsCreated = 0;
            $portsUpdated = 0;
            $macsCreated = 0;
            $macsUpdated = 0;

            DB::transaction(function () use ($adapter, $portStatuses, $portConfigData, $macEntries, $switchConfig, $syncStartedAt, &$portsCreated, &$portsUpdated, &$macsCreated, &$macsUpdated, &$portStateChanges): void {
                $statusResult = $this->portStatusSync->sync($portStatuses, $switchConfig, $syncStartedAt);
                $portsCreated = $statusResult['created'];
                $portsUpdated = $statusResult['updated'];
                $portStateChanges = $statusResult['stateChanges'];

                $this->portConfigSync->sync($switchConfig, $portConfigData, $syncStartedAt);

                $macResult = $this->portMacSync->sync($macEntries, $switchConfig, $syncStartedAt);
                $macsCreated = $macResult['created'];
                $macsUpdated = $macResult['updated'];

                $this->portMacSync->cleanStaleMacs($switchConfig, $macResult['syncedMacIds']);

                $this->processSnoopingBindings($adapter, $switchConfig);
            });

            $this->runTracker->complete($syncRun, $portsCreated, $portsUpdated, $macsCreated, $macsUpdated);
        } catch (Throwable $throwable) {
            $this->runTracker->fail($syncRun, $throwable->getMessage());

            throw $throwable;
        } finally {
            $syncRun->refresh();

            if ($syncRun->status === 'running') {
                $syncRun->update([
                    'status' => 'failed',
                    'finished_at' => now(),
                    'error' => 'Sync terminated unexpectedly',
                ]);
            }
        }

        return new SyncResult(syncRun: $syncRun, portStateChanges: $portStateChanges);
    }

    private function processSnoopingBindings(NetworkSwitchInterface $adapter, SwitchConfig $switchConfig): void
    {
        if (! $adapter instanceof SupportsDhcpSnooping) {
            return;
        }

        $bindings = $adapter->getDhcpSnoopingBindings();
        $upsertedIds = [];

        foreach ($bindings as $binding) {
            $observation = DhcpSnoopingObservation::updateOrCreate(
                [
                    'switch_config_id' => $switchConfig->id,
                    'vlan' => $binding['vlan'],
                    'ip' => $binding['ip'],
                    'mac' => MacAddress::normalize($binding['mac']),
                ],
                [
                    'interface' => $binding['interface'] ?? null,
                    'expires_at' => $binding['lease_seconds'] > 0
                        ? now()->addSeconds($binding['lease_seconds'])
                        : null,
                    'observed_at' => now(),
                ],
            );
            $upsertedIds[] = $observation->id;
        }

        // Delete stale observations for this switch
        DhcpSnoopingObservation::where('switch_config_id', $switchConfig->id)
            ->whereNotIn('id', $upsertedIds)
            ->delete();
    }
}
