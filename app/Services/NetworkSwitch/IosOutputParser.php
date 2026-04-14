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

        if (preg_match('/^(\S+) is (.+?), line protocol/', $output, $matches)) {
            $interface = $matches[1];
            $status = $matches[2];
        }

        if (preg_match('/(\S*-?[Dd]uplex), ([^,\s]+)/', $output, $matches)) {
            $duplex = $matches[1];
            $speed = $matches[2];
        }

        return new PortStatus(
            interface: $interface,
            status: $status,
            speed: $speed,
            duplex: $duplex,
            vlan: '',
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

            if (preg_match('/^(\S+)\s+(.{0,18}?)\s+(connected|notconnect|disabled|err-disabled|monitoring)\s+(\S+)\s+(\S+)\s+(\S+)/', $line, $matches)) {
                $ports[] = new PortStatus(
                    interface: $matches[1],
                    status: $matches[3],
                    speed: $matches[6],
                    vlan: $matches[4],
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
}
