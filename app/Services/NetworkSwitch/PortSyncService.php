<?php

declare(strict_types=1);

namespace App\Services\NetworkSwitch;

use App\Models\DhcpSnoopingObservation;
use App\Models\IpAddress;
use App\Models\MacAddress;
use App\Models\SwitchConfig;
use App\Models\SwitchPort;
use App\Models\SwitchSyncRun;
use App\Services\Interfaces\NetworkSwitchInterface;
use App\Services\Interfaces\SupportsDhcpSnooping;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Throwable;

class PortSyncService
{
    public function __construct(
        protected SwitchServiceFactory $factory,
        protected PortStatusSync $portStatusSync,
        protected PortMacSync $portMacSync,
        protected PortConfigSync $portConfigSync,
    ) {}

    public function syncSwitch(SwitchConfig $switchConfig): SyncResult
    {
        SwitchSyncRun::cleanStale($switchConfig);

        $syncStartedAt = now();
        $syncRun = SwitchSyncRun::start($switchConfig);

        /** @var array<int, array{switchPort: SwitchPort, oldStatus: ?string, newStatus: string}> $portStateChanges */
        $portStateChanges = [];

        try {
            $snapshot = $this->fetchSnapshot($switchConfig);

            $portsCreated = 0;
            $portsUpdated = 0;
            $macsCreated = 0;
            $macsUpdated = 0;

            DB::transaction(function () use ($snapshot, $switchConfig, $syncStartedAt, &$portsCreated, &$portsUpdated, &$macsCreated, &$macsUpdated, &$portStateChanges): void {
                $applied = $this->applySnapshot($snapshot, $switchConfig, $syncStartedAt);
                $portsCreated = $applied['portsCreated'];
                $portsUpdated = $applied['portsUpdated'];
                $macsCreated = $applied['macsCreated'];
                $macsUpdated = $applied['macsUpdated'];
                $portStateChanges = $applied['portStateChanges'];
            });

            $syncRun->complete($portsCreated, $portsUpdated, $macsCreated, $macsUpdated);
        } catch (Throwable $throwable) {
            $syncRun->fail($throwable->getMessage());

            throw SwitchSyncFailedException::recorded($throwable);
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

    public function fetchSnapshot(SwitchConfig $switchConfig): SwitchSnapshot
    {
        $adapter = $this->factory->make($switchConfig);

        $portStatuses = $adapter->getAllPorts();
        $portConfigData = $this->portConfigSync->fetchFromNetwork($adapter, $switchConfig, $portStatuses);
        $macEntries = $adapter->getForwardingDatabase();

        return new SwitchSnapshot(
            $portStatuses,
            $portConfigData,
            $macEntries,
            $this->fetchSnoopingBindings($adapter, $switchConfig),
        );
    }

    /**
     * @return array{portsCreated: int, portsUpdated: int, macsCreated: int, macsUpdated: int, portStateChanges: array<int, array{switchPort: SwitchPort, oldStatus: ?string, newStatus: string}>}
     */
    public function applySnapshot(SwitchSnapshot $snapshot, SwitchConfig $switchConfig, Carbon $syncStartedAt): array
    {
        $statusResult = $this->portStatusSync->sync($snapshot->portStatuses, $switchConfig, $syncStartedAt);

        $this->portConfigSync->sync($switchConfig, $snapshot->portConfigData, $syncStartedAt);

        $macResult = $this->portMacSync->sync($snapshot->macEntries, $switchConfig, $syncStartedAt);

        if ($snapshot->macEntries->isEmpty()) {
            Log::warning('Empty or unparseable MAC address table, skipping stale MAC cleanup', [
                'switch' => $switchConfig->hostname,
            ]);
        } else {
            $this->portMacSync->cleanStaleMacs($switchConfig, $macResult['syncedMacIds']);
        }

        if ($snapshot->snooping->wasFetched()) {
            $this->persistSnoopingBindings($snapshot->snooping->bindings, $switchConfig);
        }

        return [
            'portsCreated' => $statusResult['created'],
            'portsUpdated' => $statusResult['updated'],
            'macsCreated' => $macResult['created'],
            'macsUpdated' => $macResult['updated'],
            'portStateChanges' => $statusResult['stateChanges'],
        ];
    }

    private function fetchSnoopingBindings(NetworkSwitchInterface $adapter, SwitchConfig $switchConfig): SnoopingFetchResult
    {
        if (! $adapter instanceof SupportsDhcpSnooping) {
            return SnoopingFetchResult::unsupported();
        }

        try {
            return SnoopingFetchResult::fetched($adapter->getDhcpSnoopingBindings());
        } catch (Throwable $throwable) {
            Log::warning('DHCP snooping sync failed, continuing with port sync', [
                'switch' => $switchConfig->hostname,
                'error' => $throwable->getMessage(),
                'exception' => $throwable,
            ]);

            return SnoopingFetchResult::failed();
        }
    }

    /**
     * @param  Collection<int, array{ip: string, mac: string, vlan: int, interface: string, lease_seconds: int}>  $bindings
     */
    private function persistSnoopingBindings(Collection $bindings, SwitchConfig $switchConfig): void
    {
        $upsertedIds = [];

        foreach ($bindings as $binding) {
            $ip = IpAddress::normalize($binding['ip']);
            $mac = MacAddress::normalize($binding['mac']);
            if ($mac === null) {
                continue;
            }

            $observation = DhcpSnoopingObservation::updateOrCreate(
                [
                    'switch_config_id' => $switchConfig->id,
                    'vlan' => $binding['vlan'],
                    'ip' => $ip,
                    'mac' => $mac,
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

        DhcpSnoopingObservation::where('switch_config_id', $switchConfig->id)
            ->whereNotIn('id', $upsertedIds)
            ->delete();
    }
}
