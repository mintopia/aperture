<?php

declare(strict_types=1);

namespace App\Services\NetworkSwitch;

use App\Models\SwitchConfig;
use App\Models\SwitchPort;
use App\Models\SwitchPortConfig;
use App\Models\SwitchPortMac;
use App\Models\SwitchSyncRun;
use App\Services\Interfaces\SupportsInterfaceOutputCapture;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use RuntimeException;
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
                            'admin_status' => $portStatus->adminStatus,
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

                        $existingConfig = $port->config;
                        $configText = $this->trimRunningConfigPreamble(
                            $adapter->getPortRunningConfig($port->port_name),
                        );
                        $interfaceOutput = null;

                        try {
                            if ($adapter instanceof SupportsInterfaceOutputCapture) {
                                $rawInterfaceOutput = $adapter->getPortInterfaceOutput($port->port_name);
                                $interfaceOutput = $rawInterfaceOutput !== '' ? $rawInterfaceOutput : null;
                            } else {
                                $portStatus = $adapter->getPortStatus($port->port_name);
                                $interfaceOutput = $portStatus->description !== '' ? $portStatus->description : null;
                            }
                        } catch (Throwable $throwable) {
                            Log::debug('PortSyncService: interface output fetch failed for port', [
                                'switch' => $switchConfig->hostname,
                                'port' => $port->port_name,
                                'error' => $throwable->getMessage(),
                            ]);
                        }

                        $hasUsableRunningConfig = ! $this->isCiscoCliErrorOutput($configText) && ! $this->isSwitchportOutput($configText);

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
