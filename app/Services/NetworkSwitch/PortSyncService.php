<?php

declare(strict_types=1);

namespace App\Services\NetworkSwitch;

use App\Models\MacAddress;
use App\Models\SwitchConfig;
use App\Models\SwitchPort;
use App\Models\SwitchPortConfig;
use App\Models\SwitchPortMac;
use App\Models\SwitchSyncRun;
use App\Services\Interfaces\NetworkSwitchInterface;
use App\Services\Interfaces\SupportsBulkOperations;
use App\Services\Interfaces\SupportsInterfaceOutputCapture;
use App\Services\ValueObjects\ForwardingEntry;
use App\Services\ValueObjects\PortStatus;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use RuntimeException;
use Throwable;

class PortSyncService
{
    public function __construct(
        protected SwitchServiceFactory $factory,
    ) {}

    public function syncSwitch(SwitchConfig $switchConfig): SyncResult
    {
        $this->cleanStaleRuns($switchConfig);

        $syncStartedAt = now();

        $syncRun = SwitchSyncRun::create([
            'switch_config_id' => $switchConfig->id,
            'status' => 'running',
            'started_at' => $syncStartedAt,
        ]);

        /** @var array<int, array{switchPort: SwitchPort, oldStatus: ?string, newStatus: string}> $portStateChanges */
        $portStateChanges = [];

        try {
            $adapter = $this->factory->make($switchConfig);

            // ── Fetch all network data BEFORE opening a DB transaction ──────
            // This prevents long-held DB locks during slow SSH operations.
            $portStatuses = $adapter->getAllPorts();
            $portConfigData = $this->fetchPortConfigsFromNetwork($adapter, $switchConfig, $portStatuses);
            $macEntries = $adapter->getForwardingDatabase();
            // ────────────────────────────────────────────────────────────────

            $portsCreated = 0;
            $portsUpdated = 0;
            $macsCreated = 0;
            $macsUpdated = 0;

            DB::transaction(function () use ($portStatuses, $portConfigData, $macEntries, $switchConfig, $syncStartedAt, &$portsCreated, &$portsUpdated, &$macsCreated, &$macsUpdated, &$portStateChanges): void {
                $portStateChanges = $this->syncPortStatuses($portStatuses, $switchConfig, $syncStartedAt, $portsCreated, $portsUpdated);
                $this->syncPortConfigsFromData($portConfigData, $switchConfig, $syncStartedAt);
                $syncedMacIds = $this->syncPortMacs($macEntries, $switchConfig, $syncStartedAt, $macsCreated, $macsUpdated);
                $this->cleanStaleMacs($switchConfig, $syncedMacIds);
            });

            $syncRun->update([
                'status' => 'completed',
                'finished_at' => now(),
                'ports_created' => $portsCreated,
                'ports_updated' => $portsUpdated,
                'macs_created' => $macsCreated,
                'macs_updated' => $macsUpdated,
            ]);
        } catch (Throwable $throwable) {
            $syncRun->update([
                'status' => 'failed',
                'finished_at' => now(),
                'error' => $throwable->getMessage(),
            ]);

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
     * Clean up stale sync runs that have been stuck in 'running' state for more than 5 minutes.
     */
    private function cleanStaleRuns(SwitchConfig $switchConfig): void
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

    /**
     * Upsert pre-fetched port statuses into the database.
     *
     * Pre-loads all existing ports in one query (fixes N+1) and matches
     * against the pre-fetched $portStatuses collection.
     *
     * @param  Collection<int, PortStatus>  $portStatuses
     * @return array<int, array{switchPort: SwitchPort, oldStatus: ?string, newStatus: string}>
     */
    private function syncPortStatuses(
        Collection $portStatuses,
        SwitchConfig $switchConfig,
        Carbon $syncStartedAt,
        int &$portsCreated,
        int &$portsUpdated,
    ): array {
        // Pre-load all existing ports keyed by port_name to avoid N+1 queries.
        $existingPorts = SwitchPort::where('switch_config_id', $switchConfig->id)
            ->get()
            ->keyBy('port_name');

        /** @var array<int, array{switchPort: SwitchPort, oldStatus: ?string, newStatus: string}> $stateChanges */
        $stateChanges = [];

        foreach ($portStatuses as $portStatus) {
            $accessVlan = $portStatus->vlan !== '' ? (int) $portStatus->vlan : null;
            $switchportMode = $portStatus->switchportMode !== '' ? $portStatus->switchportMode : null;
            $description = $portStatus->description !== '' ? $portStatus->description : null;

            /** @var SwitchPort|null $existingPort */
            $existingPort = $existingPorts->get($portStatus->interface);

            if ($existingPort instanceof SwitchPort) {
                $oldStatus = $existingPort->status;

                $existingPort->update([
                    'status' => $portStatus->status,
                    'admin_status' => $portStatus->adminStatus,
                    'speed' => $portStatus->speed,
                    'duplex' => $portStatus->duplex,
                    'access_vlan' => $accessVlan,
                    'switchport_mode' => $switchportMode,
                    'switch_description' => $description,
                    'last_synced_at' => $syncStartedAt,
                ]);
                $portsUpdated++;

                if ($oldStatus !== $portStatus->status) {
                    $stateChanges[] = [
                        'switchPort' => $existingPort,
                        'oldStatus' => $oldStatus,
                        'newStatus' => $portStatus->status,
                    ];
                }
            } else {
                SwitchPort::create([
                    'switch_config_id' => $switchConfig->id,
                    'port_name' => $portStatus->interface,
                    'port_number' => $portStatus->interface,
                    'status' => $portStatus->status,
                    'admin_status' => $portStatus->adminStatus,
                    'speed' => $portStatus->speed,
                    'duplex' => $portStatus->duplex,
                    'access_vlan' => $accessVlan,
                    'switchport_mode' => $switchportMode,
                    'switch_description' => $description,
                    'last_synced_at' => $syncStartedAt,
                ]);
                $portsCreated++;
            }
        }

        return $stateChanges;
    }

    /**
     * Fetch all port running configs and interface outputs from the network adapter
     * BEFORE opening any DB transaction, so SSH calls never hold a DB lock.
     *
     * Uses bulk commands when the adapter supports SupportsBulkOperations,
     * reducing 96+ individual SSH commands to just 2 bulk commands for a
     * 48-port switch.
     *
     * @param  Collection<int, PortStatus>  $portStatuses
     * @return array<string, array{rawConfig: string|null, rawInterfaceOutput: string|null}>
     */
    private function fetchPortConfigsFromNetwork(
        NetworkSwitchInterface $adapter,
        SwitchConfig $switchConfig,
        Collection $portStatuses,
    ): array {
        if ($adapter instanceof SupportsBulkOperations) {
            return $this->fetchBulkPortConfigs($adapter, $switchConfig);
        }

        return $this->fetchPerPortConfigs($adapter, $switchConfig, $portStatuses);
    }

    /**
     * Fetch all port configs in two bulk SSH commands.
     *
     * @return array<string, array{rawConfig: string|null, rawInterfaceOutput: string|null}>
     */
    private function fetchBulkPortConfigs(
        NetworkSwitchInterface&SupportsBulkOperations $adapter,
        SwitchConfig $switchConfig,
    ): array {
        $bulkConfigs = $adapter->getAllPortRunningConfigs();
        $bulkInterfaceOutputs = $adapter instanceof SupportsInterfaceOutputCapture
            ? $adapter->getAllPortInterfaceOutputs()
            : [];

        Log::debug('PortSyncService: bulk config fetch completed', [
            'switch' => $switchConfig->hostname,
            'configs_fetched' => count($bulkConfigs),
            'interface_outputs_fetched' => count($bulkInterfaceOutputs),
        ]);

        // Collect all unique port names from both bulk responses.
        $portNames = array_keys($bulkConfigs + $bulkInterfaceOutputs);

        $result = [];

        foreach ($portNames as $portName) {
            $result[$portName] = [
                'rawConfig' => $bulkConfigs[$portName] ?? null,
                'rawInterfaceOutput' => $bulkInterfaceOutputs[$portName] ?? null,
            ];
        }

        return $result;
    }

    /**
     * Fetch port configs individually (per-port SSH commands, legacy fallback).
     *
     * @param  Collection<int, PortStatus>  $portStatuses
     * @return array<string, array{rawConfig: string|null, rawInterfaceOutput: string|null}>
     */
    private function fetchPerPortConfigs(
        NetworkSwitchInterface $adapter,
        SwitchConfig $switchConfig,
        Collection $portStatuses,
    ): array {
        $result = [];

        foreach ($portStatuses as $portStatus) {
            try {
                Log::debug('PortSyncService: fetching running config for port', [
                    'switch' => $switchConfig->hostname,
                    'port' => $portStatus->interface,
                ]);

                $rawConfig = $adapter->getPortRunningConfig($portStatus->interface);
                $rawInterfaceOutput = null;

                try {
                    if ($adapter instanceof SupportsInterfaceOutputCapture) {
                        $output = $adapter->getPortInterfaceOutput($portStatus->interface);
                        $rawInterfaceOutput = $output !== '' ? $output : null;
                    } else {
                        $portStatusDetail = $adapter->getPortStatus($portStatus->interface);
                        $rawInterfaceOutput = $portStatusDetail->description !== '' ? $portStatusDetail->description : null;
                    }
                } catch (Throwable $throwable) {
                    Log::debug('PortSyncService: interface output fetch failed for port', [
                        'switch' => $switchConfig->hostname,
                        'port' => $portStatus->interface,
                        'error' => $throwable->getMessage(),
                    ]);
                }

                $result[$portStatus->interface] = [
                    'rawConfig' => $rawConfig,
                    'rawInterfaceOutput' => $rawInterfaceOutput,
                ];

                Log::debug('PortSyncService: config fetch succeeded for port', [
                    'switch' => $switchConfig->hostname,
                    'port' => $portStatus->interface,
                ]);
            } catch (Throwable $e) {
                Log::debug('PortSyncService: config fetch failed for port', [
                    'switch' => $switchConfig->hostname,
                    'port' => $portStatus->interface,
                    'error' => $e->getMessage(),
                ]);
            }
        }

        return $result;
    }

    /**
     * Persist pre-fetched port configs to the database (DB-only, no network I/O).
     *
     * @param  array<string, array{rawConfig: string|null, rawInterfaceOutput: string|null}>  $portConfigData
     */
    private function syncPortConfigsFromData(
        array $portConfigData,
        SwitchConfig $switchConfig,
        Carbon $syncStartedAt,
    ): void {
        if ($portConfigData === []) {
            return;
        }

        // Load all ports from DB keyed by port_name (eager-load config relationship).
        $switchPorts = SwitchPort::where('switch_config_id', $switchConfig->id)
            ->with('config')
            ->get()
            ->keyBy('port_name');

        foreach ($portConfigData as $portName => $data) {
            /** @var SwitchPort|null $port */
            $port = $switchPorts->get($portName);

            if (! $port instanceof SwitchPort) {
                continue;
            }

            $rawConfigText = $data['rawConfig'];
            $rawInterfaceOutput = $data['rawInterfaceOutput'];

            try {
                if ($rawConfigText === null && $rawInterfaceOutput === null) {
                    Log::debug('PortSyncService: port not found in bulk output, skipping', [
                        'switch' => $switchConfig->hostname,
                        'port' => $portName,
                    ]);

                    continue;
                }

                $existingConfig = $port->config;
                $configText = $rawConfigText !== null
                    ? $this->trimRunningConfigPreamble($rawConfigText)
                    : '';
                $interfaceOutput = ($rawInterfaceOutput !== null && $rawInterfaceOutput !== '')
                    ? $rawInterfaceOutput
                    : null;

                $hasUsableRunningConfig = $rawConfigText !== null
                    && ! $this->isCiscoCliErrorOutput($configText)
                    && ! $this->isSwitchportOutput($configText);

                if (! $hasUsableRunningConfig && ! is_string($interfaceOutput)) {
                    if ($existingConfig instanceof SwitchPortConfig && $this->isCiscoCliErrorOutput($existingConfig->config_text)) {
                        $existingConfig->delete();
                    }

                    throw new RuntimeException('Cisco CLI returned error output while fetching running config.');
                }

                $persistedConfigText = $hasUsableRunningConfig ? $configText : '';

                if (
                    ! $hasUsableRunningConfig
                    && $existingConfig instanceof SwitchPortConfig
                    && $existingConfig->config_text !== ''
                ) {
                    $persistedConfigText = $existingConfig->config_text;
                }

                $this->persistPortConfig($port, $existingConfig, $persistedConfigText, $interfaceOutput, $syncStartedAt);

                Log::debug('PortSyncService: config sync succeeded for port', [
                    'switch' => $switchConfig->hostname,
                    'port' => $portName,
                ]);
            } catch (Throwable $e) {
                Log::debug('PortSyncService: config sync failed for port', [
                    'switch' => $switchConfig->hostname,
                    'port' => $portName,
                    'error' => $e->getMessage(),
                ]);
            }
        }
    }

    /**
     * Persist a port's config to the database (create/update as needed).
     */
    private function persistPortConfig(
        SwitchPort $port,
        ?SwitchPortConfig $existingConfig,
        string $persistedConfigText,
        ?string $interfaceOutput,
        Carbon $syncStartedAt,
    ): void {
        $configHash = md5($persistedConfigText);

        if ($existingConfig instanceof SwitchPortConfig && $existingConfig->config_hash === $configHash) {
            $existingConfig->update([
                'interface_output' => $interfaceOutput,
                'last_fetched_at' => $syncStartedAt,
            ]);
        } elseif ($existingConfig instanceof SwitchPortConfig) {
            $existingConfig->update([
                'config_text' => $persistedConfigText,
                'config_hash' => $configHash,
                'interface_output' => $interfaceOutput,
                'last_fetched_at' => $syncStartedAt,
            ]);
        } else {
            SwitchPortConfig::create([
                'switch_port_id' => $port->id,
                'config_text' => $persistedConfigText,
                'config_hash' => $configHash,
                'interface_output' => $interfaceOutput,
                'last_fetched_at' => $syncStartedAt,
            ]);
        }
    }

    /**
     * Upsert pre-fetched MAC address forwarding table entries into the database.
     *
     * @param  Collection<int, ForwardingEntry>  $macEntries
     * @return list<int> The IDs of all MAC entries that were synced in this run.
     */
    private function syncPortMacs(
        Collection $macEntries,
        SwitchConfig $switchConfig,
        Carbon $syncStartedAt,
        int &$macsCreated,
        int &$macsUpdated,
    ): array {
        $switchPorts = SwitchPort::where('switch_config_id', $switchConfig->id)
            ->get()
            ->keyBy('port_name');

        /** @var list<int> $syncedMacIds */
        $syncedMacIds = [];

        foreach ($macEntries as $entry) {
            /** @var SwitchPort|null $port */
            $port = $switchPorts->get($entry->port);

            if (! $port instanceof SwitchPort) {
                continue;
            }

            if ($port->switchport_mode === 'trunk') {
                continue;
            }

            $normalizedMac = MacAddress::normalize($entry->mac);

            $macRecord = MacAddress::firstOrCreate(
                ['mac_address' => $normalizedMac],
                ['source' => 'switch'],
            );

            $existingMac = SwitchPortMac::where('switch_port_id', $port->id)
                ->where('mac_address', $normalizedMac)
                ->where('vlan', $entry->vlan)
                ->first();

            if ($existingMac instanceof SwitchPortMac) {
                $existingMac->update([
                    'last_seen_at' => $syncStartedAt,
                    'mac_address_id' => $macRecord->id,
                ]);
                $syncedMacIds[] = (int) $existingMac->id;
                $macsUpdated++;
            } else {
                $newMac = SwitchPortMac::create([
                    'switch_port_id' => $port->id,
                    'mac_address' => $normalizedMac,
                    'mac_address_id' => $macRecord->id,
                    'vlan' => $entry->vlan,
                    'last_seen_at' => $syncStartedAt,
                ]);
                $syncedMacIds[] = (int) $newMac->id;
                $macsCreated++;
            }
        }

        return $syncedMacIds;
    }

    /**
     * Remove MAC entries that were not seen during the current sync run.
     *
     * @param  list<int>  $syncedMacIds  IDs of MAC entries synced in this run.
     */
    private function cleanStaleMacs(SwitchConfig $switchConfig, array $syncedMacIds): void
    {
        $portIds = SwitchPort::where('switch_config_id', $switchConfig->id)
            ->pluck('id');

        $staleQuery = SwitchPortMac::whereIn('switch_port_id', $portIds);

        if ($syncedMacIds !== []) {
            $staleQuery->whereNotIn('id', $syncedMacIds);
        }

        $staleQuery->delete();
    }

    private function isCiscoCliErrorOutput(string $output): bool
    {
        return (bool) preg_match(
            '/^\s*%\s+Invalid input detected at \'\^\' marker\.?\s*$/mi',
            $output,
        );
    }

    private function isSwitchportOutput(string $output): bool
    {
        return str_contains($output, 'Switchport:') && str_contains($output, 'Administrative Mode:');
    }

    private function trimRunningConfigPreamble(string $configText): string
    {
        $preambleTrimCount = 0;
        $trimmedConfigText = preg_replace(
            '/\A(?:[ \t]*\R)*(?:(?:Building configuration\.\.\.[ \t]*\R(?:[ \t]*\R)*)?(?:Current configuration\s*:\s*[0-9,]+\s+bytes[ \t]*\R)|(?:Building configuration\.\.\.[ \t]*\R))(?:[ \t]*\R)*/i',
            '',
            $configText,
            1,
            $preambleTrimCount,
        ) ?? $configText;

        if ($preambleTrimCount === 0) {
            return $trimmedConfigText;
        }

        return preg_replace('/\A(?:[ \t]*![ \t]*(?:\R|$))+/', '', $trimmedConfigText, 1) ?? $trimmedConfigText;
    }
}
