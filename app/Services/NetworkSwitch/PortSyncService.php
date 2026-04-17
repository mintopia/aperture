<?php

declare(strict_types=1);

namespace App\Services\NetworkSwitch;

use App\Models\SwitchConfig;
use App\Models\SwitchPort;
use App\Models\SwitchPortConfig;
use App\Models\SwitchPortMac;
use App\Models\SwitchSyncRun;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Throwable;

class PortSyncService
{
    public function __construct(
        protected SwitchServiceFactory $factory,
    ) {}

    public function syncSwitch(SwitchConfig $switchConfig): SwitchSyncRun
    {
        // Clean up stale runs (stuck for > 5 minutes)
        SwitchSyncRun::where('switch_config_id', $switchConfig->id)
            ->where('status', 'running')
            ->where('started_at', '<', now()->subMinutes(5))
            ->update([
                'status' => 'failed',
                'finished_at' => now(),
                'error' => 'Sync timed out (stale run cleanup)',
            ]);

        $syncStartedAt = now();

        $syncRun = SwitchSyncRun::create([
            'switch_config_id' => $switchConfig->id,
            'status' => 'running',
            'started_at' => $syncStartedAt,
        ]);

        try {
            $adapter = $this->factory->make($switchConfig);

            $portsCreated = 0;
            $portsUpdated = 0;
            $macsCreated = 0;
            $macsUpdated = 0;

            DB::transaction(function () use ($adapter, $switchConfig, $syncStartedAt, &$portsCreated, &$portsUpdated, &$macsCreated, &$macsUpdated): void {
                // Step 1: Fetch and upsert ports
                $portStatuses = $adapter->getAllPorts();

                foreach ($portStatuses as $portStatus) {
                    $accessVlan = $portStatus->vlan !== '' ? (int) $portStatus->vlan : null;
                    $switchportMode = $portStatus->switchportMode !== '' ? $portStatus->switchportMode : null;
                    $description = $portStatus->description !== '' ? $portStatus->description : null;

                    $existingPort = SwitchPort::where('switch_config_id', $switchConfig->id)
                        ->where('port_name', $portStatus->interface)
                        ->first();

                    if ($existingPort instanceof SwitchPort) {
                        $existingPort->update([
                            'status' => $portStatus->status,
                            'speed' => $portStatus->speed,
                            'duplex' => $portStatus->duplex,
                            'access_vlan' => $accessVlan,
                            'switchport_mode' => $switchportMode,
                            'switch_description' => $description,
                            'last_synced_at' => $syncStartedAt,
                        ]);
                        $portsUpdated++;
                    } else {
                        SwitchPort::create([
                            'switch_config_id' => $switchConfig->id,
                            'port_name' => $portStatus->interface,
                            // port_number is set to the same value as port_name (interface name from switch).
                            // PortStatus VO only provides a single interface identifier; abbreviated forms
                            // could be derived in future if needed for display purposes.
                            'port_number' => $portStatus->interface,
                            'status' => $portStatus->status,
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

                // Step 1.5: Fetch and store per-port running config
                $switchPorts = SwitchPort::where('switch_config_id', $switchConfig->id)
                    ->with('config')
                    ->get();

                foreach ($switchPorts as $port) {
                    try {
                        Log::debug('PortSyncService: fetching running config for port', [
                            'switch' => $switchConfig->hostname,
                            'port' => $port->port_name,
                        ]);

                        $configText = $adapter->getPortRunningConfig($port->port_name);
                        $configHash = md5($configText);
                        $existingConfig = $port->config;

                        if ($existingConfig instanceof SwitchPortConfig && $existingConfig->config_hash === $configHash) {
                            $existingConfig->update(['last_fetched_at' => $syncStartedAt]);
                        } elseif ($existingConfig instanceof SwitchPortConfig) {
                            $existingConfig->update([
                                'config_text' => $configText,
                                'config_hash' => $configHash,
                                'last_fetched_at' => $syncStartedAt,
                            ]);
                        } else {
                            SwitchPortConfig::create([
                                'switch_port_id' => $port->id,
                                'config_text' => $configText,
                                'config_hash' => $configHash,
                                'last_fetched_at' => $syncStartedAt,
                            ]);
                        }

                        Log::debug('PortSyncService: config fetch succeeded for port', [
                            'switch' => $switchConfig->hostname,
                            'port' => $port->port_name,
                        ]);
                    } catch (Throwable $e) {
                        Log::debug('PortSyncService: config fetch failed for port', [
                            'switch' => $switchConfig->hostname,
                            'port' => $port->port_name,
                            'error' => $e->getMessage(),
                        ]);
                        continue;
                    }
                }

                // Step 2: Fetch and upsert MACs
                $forwardingEntries = $adapter->getForwardingDatabase();

                $switchPorts = SwitchPort::where('switch_config_id', $switchConfig->id)
                    ->get()
                    ->keyBy('port_name');

                /** @var list<int> $syncedMacIds */
                $syncedMacIds = [];

                foreach ($forwardingEntries as $entry) {
                    /** @var SwitchPort|null $port */
                    $port = $switchPorts->get($entry->port);

                    if (! $port instanceof SwitchPort) {
                        continue;
                    }

                    $existingMac = SwitchPortMac::where('switch_port_id', $port->id)
                        ->where('mac_address', $entry->mac)
                        ->where('vlan', $entry->vlan)
                        ->first();

                    if ($existingMac instanceof SwitchPortMac) {
                        $existingMac->update(['last_seen_at' => $syncStartedAt]);
                        $syncedMacIds[] = (int) $existingMac->id;
                        $macsUpdated++;
                    } else {
                        $newMac = SwitchPortMac::create([
                            'switch_port_id' => $port->id,
                            'mac_address' => $entry->mac,
                            'vlan' => $entry->vlan,
                            'last_seen_at' => $syncStartedAt,
                        ]);
                        $syncedMacIds[] = (int) $newMac->id;
                        $macsCreated++;
                    }
                }

                // Step 3: Remove stale MACs (those not seen in this sync)
                $portIds = $switchPorts->pluck('id');
                $staleQuery = SwitchPortMac::whereIn('switch_port_id', $portIds);

                if ($syncedMacIds !== []) {
                    $staleQuery->whereNotIn('id', $syncedMacIds);
                }

                $staleQuery->delete();
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

        return $syncRun;
    }
}
