<?php

declare(strict_types=1);

namespace App\Services\NetworkSwitch;

use App\Models\SwitchConfig;
use App\Models\SwitchPort;
use App\Models\SwitchPortConfig;
use App\Services\Interfaces\NetworkSwitchInterface;
use App\Services\Interfaces\SupportsBulkOperations;
use App\Services\Interfaces\SupportsInterfaceOutputCapture;
use App\Services\ValueObjects\PortStatus;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Log;
use RuntimeException;
use Throwable;

final class PortConfigSync
{
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
    public function fetchFromNetwork(
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
     * Persist pre-fetched port configs to the database (DB-only, no network I/O).
     *
     * @param  array<string, array{rawConfig: string|null, rawInterfaceOutput: string|null}>  $portConfigData
     */
    public function sync(
        SwitchConfig $switchConfig,
        array $portConfigData,
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
                    Log::debug('PortConfigSync: port not found in bulk output, skipping', [
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

                Log::debug('PortConfigSync: config sync succeeded for port', [
                    'switch' => $switchConfig->hostname,
                    'port' => $portName,
                ]);
            } catch (Throwable $e) {
                Log::debug('PortConfigSync: config sync failed for port', [
                    'switch' => $switchConfig->hostname,
                    'port' => $portName,
                    'error' => $e->getMessage(),
                ]);
            }
        }
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

        Log::debug('PortConfigSync: bulk config fetch completed', [
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
                Log::debug('PortConfigSync: fetching running config for port', [
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
                    Log::debug('PortConfigSync: interface output fetch failed for port', [
                        'switch' => $switchConfig->hostname,
                        'port' => $portStatus->interface,
                        'error' => $throwable->getMessage(),
                    ]);
                }

                $result[$portStatus->interface] = [
                    'rawConfig' => $rawConfig,
                    'rawInterfaceOutput' => $rawInterfaceOutput,
                ];

                Log::debug('PortConfigSync: config fetch succeeded for port', [
                    'switch' => $switchConfig->hostname,
                    'port' => $portStatus->interface,
                ]);
            } catch (Throwable $e) {
                Log::debug('PortConfigSync: config fetch failed for port', [
                    'switch' => $switchConfig->hostname,
                    'port' => $portStatus->interface,
                    'error' => $e->getMessage(),
                ]);
            }
        }

        return $result;
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
