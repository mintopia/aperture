<?php

declare(strict_types=1);

namespace App\Services\NetworkSwitch;

use App\Services\ValueObjects\ForwardingEntry;
use App\Services\ValueObjects\PortStatistics;
use App\Services\ValueObjects\PortStatus;

class IosOutputParser
{
    /**
     * Parse `show interface {name}` output into structured data.
     */
    public function parseShowInterface(string $output): PortStatus
    {
        $interface = '';
        $status = '';
        $speed = '';
        $duplex = '';
        $description = '';

        if (preg_match('/^(\S+) is (.+?), line protocol/', $output, $matches)) {
            $interface = $matches[1];
            $status = $matches[2];
        }

        if (preg_match('/^\s+Description:\s*(.+)$/m', $output, $matches)) {
            $description = trim($matches[1]);
        }

        if (preg_match('/(\S*-?[Dd]uplex), ([^,\s]+)/', $output, $matches)) {
            $duplex = $matches[1];
            $speed = $matches[2];
        }

        $adminStatus = str_contains($status, 'administratively down') ? 'down' : 'up';

        return new PortStatus(
            interface: $interface,
            status: $status,
            speed: $speed,
            duplex: $duplex,
            vlan: '',
            description: $description,
            adminStatus: $adminStatus,
        );
    }

    /**
     * Parse counters from `show interface {name}` output.
     */
    public function parseInterfaceCounters(string $output): PortStatistics
    {
        $inBytes = 0;
        $outBytes = 0;
        $inErrors = 0;
        $outErrors = 0;

        if (preg_match('/(\d+) packets input, (\d+) bytes/', $output, $matches)) {
            $inBytes = (int) $matches[2];
        }

        if (preg_match('/(\d+) packets output, (\d+) bytes/', $output, $matches)) {
            $outBytes = (int) $matches[2];
        }

        if (preg_match('/(\d+) input errors/', $output, $matches)) {
            $inErrors = (int) $matches[1];
        }

        if (preg_match('/(\d+) output errors/', $output, $matches)) {
            $outErrors = (int) $matches[1];
        }

        return new PortStatistics(
            inBytes: $inBytes,
            outBytes: $outBytes,
            inErrors: $inErrors,
            outErrors: $outErrors,
        );
    }

    /**
     * Parse `show interface status` tabular output.
     *
     * @return array<int, PortStatus>
     */
    public function parseInterfaceStatusTable(string $output): array
    {
        $lines = preg_split('/\r?\n/', $output) ?: [];
        $ports = [];

        foreach ($lines as $line) {
            if (preg_match('/^Port\s+Name/', $line) || trim($line) === '') {
                continue;
            }

            if (preg_match('/^(?P<interface>\S+)\s+(?P<description>.*?)\s{2,}(?P<status>connected|notconnect|disabled|err-disabled|monitoring)\s+(?P<vlan>\S+)\s+(?P<duplex>\S+)\s+(?P<speed>\S+)/', $line, $matches)) {
                $nonNumericModes = ['trunk', 'routed', 'unassigned', 'suspended'];
                $switchportMode = in_array($matches['vlan'], $nonNumericModes, true) ? $matches['vlan'] : 'access';
                $vlan = $switchportMode === 'access' ? $matches['vlan'] : '';

                $ports[] = new PortStatus(
                    interface: $matches['interface'],
                    status: $matches['status'],
                    speed: $matches['speed'],
                    duplex: $matches['duplex'],
                    vlan: $vlan,
                    description: trim($matches['description']),
                    switchportMode: $switchportMode,
                    adminStatus: $matches['status'] === 'disabled' ? 'down' : 'up',
                );
            }
        }

        return $ports;
    }

    /**
     * Parse `show mac address-table` output.
     *
     * @return array<int, ForwardingEntry>
     */
    public function parseMacAddressTable(string $output): array
    {
        $lines = preg_split('/\r?\n/', $output) ?: [];
        $entries = [];

        foreach ($lines as $line) {
            if (preg_match('/^\s*(\d+)\s+([0-9a-fA-F]{4}\.[0-9a-fA-F]{4}\.[0-9a-fA-F]{4})\s+\S+\s+(\S+)/', $line, $matches)) {
                $entries[] = new ForwardingEntry(
                    mac: $matches[2],
                    port: $matches[3],
                    vlan: (int) $matches[1],
                );
            }
        }

        return $entries;
    }

    /**
     * Split bulk `show interface` output into per-interface blocks.
     *
     * @return array<string, string> interface name => output block
     */
    public function splitBulkShowInterface(string $output): array
    {
        if (trim($output) === '') {
            return [];
        }

        $pattern = '/^(\S+)\s+is\s+/m';

        $parts = preg_split($pattern, $output, -1, PREG_SPLIT_DELIM_CAPTURE | PREG_SPLIT_NO_EMPTY);
        if ($parts === false) {
            return [];
        }

        $result = [];
        $partCount = count($parts);
        for ($i = 0; $i < $partCount - 1; $i += 2) {
            $interfaceName = $parts[$i];
            $block = $interfaceName.' is '.$parts[$i + 1];
            $result[$interfaceName] = trim($block);
        }

        return $result;
    }

    /**
     * Split bulk `show running-config | section ^interface` output into per-interface blocks.
     *
     * @return array<string, string> interface name => config block
     */
    public function splitBulkRunningConfig(string $output): array
    {
        if (trim($output) === '') {
            return [];
        }

        $lines = preg_split('/\r?\n/', $output) ?: [];
        $result = [];
        $currentInterface = null;
        $currentBlock = '';

        foreach ($lines as $line) {
            if (preg_match('/^interface\s+(\S+)/', $line, $matches)) {
                if ($currentInterface !== null) {
                    $result[$currentInterface] = trim($currentBlock);
                }

                $currentInterface = $matches[1];
                $currentBlock = $line;
            } elseif ($currentInterface !== null) {
                if ($line === '!') {
                    $result[$currentInterface] = trim($currentBlock);
                    $currentInterface = null;
                    $currentBlock = '';
                } else {
                    $currentBlock .= "\n".$line;
                }
            }
        }

        if ($currentInterface !== null) {
            $result[$currentInterface] = trim($currentBlock);
        }

        return $result;
    }
}
