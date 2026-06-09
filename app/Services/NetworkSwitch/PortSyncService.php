<?php

declare(strict_types=1);

namespace App\Services\NetworkSwitch;

use App\Models\DhcpSnoopingObservation;
use App\Models\MacAddress;
use App\Models\SwitchConfig;
use App\Models\SwitchPort;
use App\Services\Interfaces\NetworkSwitchInterface;
use App\Services\Interfaces\SupportsDhcpSnooping;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
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
            $snoopingBindings = $this->fetchSnoopingBindings($adapter, $switchConfig);
            // ────────────────────────────────────────────────────────────────

            $portsCreated = 0;
            $portsUpdated = 0;
            $macsCreated = 0;
            $macsUpdated = 0;

            DB::transaction(function () use ($portStatuses, $portConfigData, $macEntries, $snoopingBindings, $switchConfig, $syncStartedAt, &$portsCreated, &$portsUpdated, &$macsCreated, &$macsUpdated, &$portStateChanges): void {
                $statusResult = $this->portStatusSync->sync($portStatuses, $switchConfig, $syncStartedAt);
                $portsCreated = $statusResult['created'];
                $portsUpdated = $statusResult['updated'];
                $portStateChanges = $statusResult['stateChanges'];

                $this->portConfigSync->sync($switchConfig, $portConfigData, $syncStartedAt);

                $macResult = $this->portMacSync->sync($macEntries, $switchConfig, $syncStartedAt);
                $macsCreated = $macResult['created'];
                $macsUpdated = $macResult['updated'];

                $this->portMacSync->cleanStaleMacs($switchConfig, $macResult['syncedMacIds']);

                if ($snoopingBindings instanceof Collection) {
                    $this->persistSnoopingBindings($snoopingBindings, $switchConfig);
                }
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

    /**
     * Fetch DHCP snooping bindings over SSH before the DB transaction opens.
     *
     * Returns null when the adapter does not support snooping or the fetch
     * fails. A failed fetch must not abort the wider port sync and must not
     * trigger the stale-observation delete, so the caller skips persistence
     * entirely when null is returned. DB write errors during persistence are
     * deliberately NOT caught here — they must roll back the transaction.
     *
     * @return Collection<int, array{ip: string, mac: string, vlan: int, interface: string, lease_seconds: int}>|null
     */
    private function fetchSnoopingBindings(NetworkSwitchInterface $adapter, SwitchConfig $switchConfig): ?Collection
    {
        if (! $adapter instanceof SupportsDhcpSnooping) {
            return null;
        }

        try {
            return $adapter->getDhcpSnoopingBindings();
        } catch (Throwable $throwable) {
            Log::warning('DHCP snooping sync failed, continuing with port sync', [
                'switch' => $switchConfig->hostname,
                'error' => $throwable->getMessage(),
                'exception' => $throwable,
            ]);

            return null;
        }
    }

    /**
     * Persist pre-fetched snooping bindings and remove stale observations.
     * Must run inside the sync DB transaction.
     *
     * @param  Collection<int, array{ip: string, mac: string, vlan: int, interface: string, lease_seconds: int}>  $bindings
     */
    private function persistSnoopingBindings(Collection $bindings, SwitchConfig $switchConfig): void
    {
        $upsertedIds = [];

        foreach ($bindings as $binding) {
            // Normalize IPv6 addresses to lowercase (observations bypass the IpAddress model)
            $ip = str_contains($binding['ip'], ':') ? strtolower($binding['ip']) : $binding['ip'];

            $observation = DhcpSnoopingObservation::updateOrCreate(
                [
                    'switch_config_id' => $switchConfig->id,
                    'vlan' => $binding['vlan'],
                    'ip' => $ip,
                    'mac' => MacAddress::normalize($binding['mac']),
                ],
                [
                    'interface' => $binding['interface'],
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
