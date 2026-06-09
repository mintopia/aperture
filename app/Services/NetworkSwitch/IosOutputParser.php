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

            if (preg_match('/^(?P<interface>\S+)\s+(?P<description>.*?)\s+(?P<status>connected|notconnect|disabled|err-disabled|monitoring)\s+(?P<vlan>\S+)\s+(?P<duplex>\S+)\s+(?P<speed>\S+)/', $line, $matches)) {
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
     * Cisco IOS interface name abbreviation mapping.
     *
     * Maps full interface type names to their abbreviated forms
     * as used by `show interface status`.
     *
     * @var array<string, string>
     */
    private const array INTERFACE_ABBREVIATIONS = [
        'GigabitEthernet' => 'Gi',
        'FastEthernet' => 'Fa',
        'TenGigabitEthernet' => 'Te',
        'TwentyFiveGigE' => 'Twe',
        'FortyGigabitEthernet' => 'Fo',
        'HundredGigE' => 'Hu',
        'Port-channel' => 'Po',
        'Vlan' => 'Vl',
        'Loopback' => 'Lo',
        'Tunnel' => 'Tu',
        'Ethernet' => 'Eth',
    ];

    /**
     * Abbreviate a full Cisco IOS interface name to its short form.
     *
     * For example: "GigabitEthernet1/0/1" => "Gi1/0/1"
     * Names already abbreviated are returned as-is.
     */
    public function abbreviateInterfaceName(string $name): string
    {
        foreach (self::INTERFACE_ABBREVIATIONS as $full => $short) {
            if (str_starts_with($name, $full)) {
                return $short.substr($name, strlen($full));
            }
        }

        return $name;
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
     * Parse `show ip dhcp binding` output into structured records.
     *
     * @return array<int, array{ip: string, mac: string|null, expires: string, type: string, state: string, interface: string}>
     */
    public function parseDhcpBindingTable(string $output): array
    {
        if ($this->isErrorOutput($output)) {
            return [];
        }

        $lines = preg_split('/\r?\n/', $output) ?: [];
        $entries = [];

        foreach ($lines as $line) {
            // Match data lines: starts with an IP address
            if (! preg_match('/^(\d{1,3}(?:\.\d{1,3}){3})\s+(\S+)\s+(.+?)\s{2,}(\S+)\s+(\S+)\s+(\S+)\s*$/', $line, $matches)) {
                continue;
            }

            $entries[] = [
                'ip' => $matches[1],
                'mac' => $this->extractMacFromClientId($matches[2]),
                'expires' => trim($matches[3]),
                'type' => $matches[4],
                'state' => $matches[5],
                'interface' => $matches[6],
            ];
        }

        return $entries;
    }

    /**
     * Extract and normalise a MAC address from a Cisco DHCP client-ID string.
     *
     * Handles two formats:
     *  - 7-group dotted hex with hardware-type prefix: 0100.1122.3344.55
     *    (strip leading 01 byte, remaining 6 bytes are the MAC)
     *  - Standard 3-group dotted hex MAC: aabb.ccdd.eeff
     *
     * Returns the MAC in uppercase colon-separated format (AA:BB:CC:DD:EE:FF),
     * or null if the string is not a recognised format.
     */
    public function extractMacFromClientId(string $clientId): ?string
    {
        if ($clientId === '') {
            return null;
        }

        // Format with hardware-type prefix: 01XX.XXXX.XXXX.XX (7 hex groups of 2)
        if (preg_match('/^01([0-9a-fA-F]{2})\.([0-9a-fA-F]{4})\.([0-9a-fA-F]{4})\.([0-9a-fA-F]{2})$/', $clientId, $m)) {
            $hex = $m[1].$m[2].$m[3].$m[4];

            return implode(':', str_split(strtoupper($hex), 2));
        }

        // Raw 3-group dotted hex MAC: XXXX.XXXX.XXXX
        if (preg_match('/^([0-9a-fA-F]{4})\.([0-9a-fA-F]{4})\.([0-9a-fA-F]{4})$/', $clientId, $m)) {
            $hex = $m[1].$m[2].$m[3];

            return implode(':', str_split(strtoupper($hex), 2));
        }

        return null;
    }

    /**
     * Detect whether output is a Cisco IOS error response.
     *
     * IOS error lines begin with '%' followed by one of the known error keywords.
     */
    public function isErrorOutput(string $output): bool
    {
        return (bool) preg_match('/^%\s*(Invalid|Incomplete|Ambiguous)/m', $output);
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

    /**
     * Parse `show ip dhcp pool` output into pool statistics.
     *
     * @return array<int, array{name: string, total: string, leased: string}>
     */
    public function parseDhcpPoolStats(string $output): array
    {
        if ($this->isErrorOutput($output)) {
            return [];
        }

        $lines = preg_split('/\r?\n/', $output) ?: [];
        $pools = [];
        $current = null;

        foreach ($lines as $line) {
            if (preg_match('/^Pool\s+(\S+)\s*:/', $line, $m)) {
                if ($current !== null) {
                    $pools[] = $current;
                }

                $current = ['name' => $m[1], 'total' => '0', 'leased' => '0'];

                continue;
            }

            if ($current === null) {
                continue;
            }

            if (preg_match('/Total addresses\s*:\s*(\d+)/', $line, $m)) {
                $current['total'] = $m[1];
            } elseif (preg_match('/Leased addresses\s*:\s*(\d+)/', $line, $m)) {
                $current['leased'] = $m[1];
            }
        }

        if ($current !== null) {
            $pools[] = $current;
        }

        return $pools;
    }

    /**
     * Parse `show running-config | section ip dhcp` output into pool configuration and exclusions.
     *
     * @return array{pools: array<int, array{name: string, network: string, mask: string, gateway: string}>, excluded: array<int, array{start: string, end: string}>}
     */
    public function parseDhcpPoolConfig(string $output): array
    {
        if ($this->isErrorOutput($output)) {
            return ['pools' => [], 'excluded' => []];
        }

        $lines = preg_split('/\r?\n/', $output) ?: [];
        $pools = [];
        $excluded = [];
        $current = null;

        foreach ($lines as $line) {
            // Exclusion line: ip dhcp excluded-address <start> [end]
            if (preg_match('/^ip dhcp excluded-address\s+(\d{1,3}(?:\.\d{1,3}){3})(?:\s+(\d{1,3}(?:\.\d{1,3}){3}))?/', $line, $m)) {
                $start = $m[1];
                $end = isset($m[2]) ? $m[2] : $start;
                $excluded[] = ['start' => $start, 'end' => $end];

                continue;
            }

            // Pool header: ip dhcp pool <name>
            if (preg_match('/^ip dhcp pool\s+(\S+)/', $line, $m)) {
                if ($current !== null) {
                    $pools[] = $current;
                }

                $current = ['name' => $m[1], 'network' => '', 'mask' => '', 'gateway' => ''];

                continue;
            }

            if ($current === null) {
                continue;
            }

            // End of pool block
            if (trim($line) === '!') {
                $pools[] = $current;
                $current = null;

                continue;
            }

            if (preg_match('/^\s+network\s+(\d{1,3}(?:\.\d{1,3}){3})\s+(\d{1,3}(?:\.\d{1,3}){3})/', $line, $m)) {
                $current['network'] = $m[1];
                $current['mask'] = $m[2];
            } elseif (preg_match('/^\s+default-router\s+(\d{1,3}(?:\.\d{1,3}){3})/', $line, $m)) {
                $current['gateway'] = $m[1];
            }
        }

        if ($current !== null) {
            $pools[] = $current;
        }

        return ['pools' => $pools, 'excluded' => $excluded];
    }

    /**
     * Parse `show ipv6 dhcp binding` output into structured records.
     *
     * Only IA_NA (address) bindings are returned; IA_PD (prefix delegation) entries are skipped.
     * MAC addresses are extracted from the DUID where possible.
     *
     * @return array<int, array{ip: string, mac: string|null, expires: string, duid: string, iaid: string}>
     */
    public function parseDhcpv6BindingTable(string $output): array
    {
        if ($this->isErrorOutput($output)) {
            return [];
        }

        $lines = preg_split('/\r?\n/', $output) ?: [];
        $entries = [];
        $duid = '';
        $iaid = '';
        $inIaNa = false;
        $pendingIp = '';
        $pendingMac = null;
        $pendingDuid = '';
        $pendingIaid = '';
        $hasPending = false;

        foreach ($lines as $line) {
            if (preg_match('/^\s*DUID:\s*(\S+)/', $line, $m)) {
                if ($hasPending) {
                    $entries[] = $this->buildDhcpv6Entry($pendingIp, $pendingMac, '', $pendingDuid, $pendingIaid);
                    $hasPending = false;
                }

                $duid = $m[1];
                $iaid = '';
                $inIaNa = false;

                continue;
            }

            if (preg_match('/^\s+IA NA:\s+IA ID\s+(0x[0-9a-fA-F]+)/', $line, $m)) {
                if ($hasPending) {
                    $entries[] = $this->buildDhcpv6Entry($pendingIp, $pendingMac, '', $pendingDuid, $pendingIaid);
                    $hasPending = false;
                }

                $iaid = $m[1];
                $inIaNa = true;

                continue;
            }

            if (preg_match('/^\s+IA PD:/', $line)) {
                if ($hasPending) {
                    $entries[] = $this->buildDhcpv6Entry($pendingIp, $pendingMac, '', $pendingDuid, $pendingIaid);
                    $hasPending = false;
                }

                $inIaNa = false;

                continue;
            }

            if (! $inIaNa) {
                continue;
            }

            if (preg_match('/^\s+Address:\s+(\S+)/', $line, $m)) {
                if ($hasPending) {
                    $entries[] = $this->buildDhcpv6Entry($pendingIp, $pendingMac, '', $pendingDuid, $pendingIaid);
                }

                $pendingIp = $m[1];
                $pendingMac = $this->extractMacFromDuid($duid);
                $pendingDuid = $duid;
                $pendingIaid = $iaid;
                $hasPending = true;

                continue;
            }

            if ($hasPending && preg_match('/^\s+expires at (.+?)\s+\(\d+ seconds\)/', $line, $m)) {
                $entries[] = $this->buildDhcpv6Entry($pendingIp, $pendingMac, trim($m[1]), $pendingDuid, $pendingIaid);
                $hasPending = false;
            }
        }

        if ($hasPending) {
            $entries[] = $this->buildDhcpv6Entry($pendingIp, $pendingMac, '', $pendingDuid, $pendingIaid);
        }

        return $entries;
    }

    /**
     * Parse `show ipv6 dhcp pool` output into pool statistics.
     *
     * @return array<int, array{name: string, prefix: string, active_clients: string}>
     */
    public function parseDhcpv6PoolStats(string $output): array
    {
        if ($this->isErrorOutput($output)) {
            return [];
        }

        $lines = preg_split('/\r?\n/', $output) ?: [];
        $pools = [];
        $current = null;

        foreach ($lines as $line) {
            if (preg_match('/^DHCPv6 pool:\s+(\S+)/', $line, $m)) {
                if ($current !== null) {
                    $pools[] = $current;
                }

                $current = ['name' => $m[1], 'prefix' => '', 'active_clients' => '0'];

                continue;
            }

            if ($current === null) {
                continue;
            }

            if (preg_match('/^\s+Address allocation prefix:\s+(\S+)/', $line, $m)) {
                $current['prefix'] = $m[1];
            } elseif (preg_match('/^\s+Active clients:\s+(\d+)/', $line, $m)) {
                $current['active_clients'] = $m[1];
            }
        }

        if ($current !== null) {
            $pools[] = $current;
        }

        return $pools;
    }

    /**
     * Parse `show running-config | section ipv6 dhcp pool` output.
     *
     * @return array{pools: array<int, array{name: string, prefix: string}>}
     */
    public function parseDhcpv6PoolConfig(string $output): array
    {
        if ($this->isErrorOutput($output)) {
            return ['pools' => []];
        }

        $lines = preg_split('/\r?\n/', $output) ?: [];
        $pools = [];
        $current = null;

        foreach ($lines as $line) {
            if (preg_match('/^ipv6 dhcp pool\s+(\S+)/', $line, $m)) {
                if ($current !== null) {
                    $pools[] = $current;
                }

                $current = ['name' => $m[1], 'prefix' => ''];

                continue;
            }

            if ($current === null) {
                continue;
            }

            if (trim($line) === '!') {
                $pools[] = $current;
                $current = null;

                continue;
            }

            if (preg_match('/^\s+address prefix\s+(\S+)/', $line, $m)) {
                $current['prefix'] = $m[1];
            }
        }

        if ($current !== null) {
            $pools[] = $current;
        }

        return ['pools' => $pools];
    }

    /**
     * Parse `show ip dhcp snooping binding` output into structured records.
     *
     * MAC addresses are normalised to uppercase colon-separated format.
     * VLAN is returned as an integer.
     *
     * @return array<int, array{ip: string, mac: string, vlan: int, interface: string, lease_seconds: int}>
     */
    public function parseDhcpSnoopingTable(string $output): array
    {
        if ($this->isErrorOutput($output)) {
            return [];
        }

        $lines = preg_split('/\r?\n/', $output) ?: [];
        $entries = [];

        foreach ($lines as $line) {
            if (! preg_match('/^([0-9a-fA-F]{2}(?::[0-9a-fA-F]{2}){5})\s+(\d{1,3}(?:\.\d{1,3}){3})\s+(\d+)\s+\S+\s+(\d+)\s+(\S+)/', $line, $m)) {
                continue;
            }

            $entries[] = [
                'ip' => $m[2],
                'mac' => strtoupper($m[1]),
                'vlan' => (int) $m[4],
                'interface' => $m[5],
                'lease_seconds' => (int) $m[3],
            ];
        }

        return $entries;
    }

    /**
     * Extract and normalise a MAC address from a DHCPv6 DUID hex string.
     *
     * Supported DUID types:
     *  - DUID-LL  (type 0003): 0003 0001 XX:XX:XX:XX:XX:XX — last 6 bytes are MAC
     *  - DUID-LLT (type 0001): 0001 0001 TTTTTTTT XX:XX:XX:XX:XX:XX — last 6 bytes are MAC
     *
     * Returns null for DUID-EN (0002), DUID-UUID (0004), or unrecognised formats.
     */
    /**
     * Build a DHCPv6 binding entry with proper shape typing.
     *
     * @return array{ip: string, mac: string|null, expires: string, duid: string, iaid: string}
     */
    private function buildDhcpv6Entry(string $ip, ?string $mac, string $expires, string $duid, string $iaid): array
    {
        return [
            'ip' => $ip,
            'mac' => $mac,
            'expires' => $expires,
            'duid' => $duid,
            'iaid' => $iaid,
        ];
    }

    private function extractMacFromDuid(string $duid): ?string
    {
        $hex = strtoupper($duid);

        // DUID-LL: type 0003 + hardware type 0001 + 6-byte MAC = 20 hex chars
        if (str_starts_with($hex, '0003') && strlen($hex) >= 20) {
            $mac = substr($hex, 8, 12);

            return implode(':', str_split($mac, 2));
        }

        // DUID-LLT: type 0001 + hardware type 0001 + 4-byte time + 6-byte MAC = 28 hex chars
        if (str_starts_with($hex, '0001') && strlen($hex) >= 28) {
            $mac = substr($hex, 16, 12);

            return implode(':', str_split($mac, 2));
        }

        return null;
    }

    /**
     * Compute effective DHCP ranges by subtracting excluded addresses from each pool's usable range.
     *
     * Takes the output of parseDhcpPoolConfig() and returns the resulting address ranges per pool.
     * An exclusion in the middle of a range splits it into multiple entries (all carry the pool name).
     *
     * @param  array{pools: array<int, array{name: string, network: string, mask: string, gateway: string}>, excluded: array<int, array{start: string, end: string}>}  $poolConfig
     * @return array<int, array{name: string, subnet: string, range_from: string, range_to: string, total_addresses: string, gateway: string}>
     */
    public function computeEffectiveRanges(array $poolConfig): array
    {
        $results = [];

        foreach ($poolConfig['pools'] as $pool) {
            $networkLong = ip2long($pool['network']);
            $maskLong = ip2long($pool['mask']);

            if ($networkLong === false || $maskLong === false) {
                continue;
            }

            // CIDR prefix length: count the number of set bits in the mask
            $prefix = substr_count(sprintf('%032b', $maskLong & 0xFFFFFFFF), '1');

            $hostMin = $networkLong + 1;        // first usable (skip network address)
            $hostMax = ($networkLong | (~$maskLong & 0xFFFFFFFF)) - 1; // last usable (skip broadcast)

            $subnet = $pool['network'].'/'.$prefix;

            // Collect exclusion ranges that overlap this pool's usable space
            $excl = [];
            foreach ($poolConfig['excluded'] as $ex) {
                $exStart = ip2long($ex['start']);
                $exEnd = ip2long($ex['end']);
                if ($exStart === false || $exEnd === false) {
                    continue;
                }

                // Only keep exclusions that overlap [hostMin, hostMax]
                if ($exEnd < $hostMin || $exStart > $hostMax) {
                    continue;
                }

                // Clamp to usable range
                $excl[] = [max($exStart, $hostMin), min($exEnd, $hostMax)];
            }

            // Sort exclusions by start address
            usort($excl, fn (array $a, array $b): int => $a[0] <=> $b[0]);

            // Walk through the usable range, subtracting exclusions
            $cursor = $hostMin;
            foreach ($excl as [$exStart, $exEnd]) {
                if ($cursor > $hostMax) {
                    break;
                }

                if ($exStart > $cursor) {
                    // There is a usable segment before this exclusion
                    $from = long2ip($cursor);
                    $to = long2ip($exStart - 1);
                    $count = bcadd((string) ($exStart - 1 - $cursor), '1');
                    $results[] = [
                        'name' => $pool['name'],
                        'subnet' => $subnet,
                        'range_from' => $from,
                        'range_to' => $to,
                        'total_addresses' => $count,
                        'gateway' => $pool['gateway'],
                    ];
                }

                $cursor = $exEnd + 1;
            }

            // Remaining segment after all exclusions
            if ($cursor <= $hostMax) {
                $from = long2ip($cursor);
                $to = long2ip($hostMax);
                $count = bcadd((string) ($hostMax - $cursor), '1');
                $results[] = [
                    'name' => $pool['name'],
                    'subnet' => $subnet,
                    'range_from' => $from,
                    'range_to' => $to,
                    'total_addresses' => $count,
                    'gateway' => $pool['gateway'],
                ];
            }
        }

        return $results;
    }
}
