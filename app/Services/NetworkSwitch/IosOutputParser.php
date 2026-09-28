<?php

declare(strict_types=1);

namespace App\Services\NetworkSwitch;

use App\Services\ValueObjects\ForwardingEntry;
use App\Services\ValueObjects\PortStatistics;
use App\Services\ValueObjects\PortStatus;
use App\Support\Duid;

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
        $records = $this->collectDhcpBindingRecords($lines);

        $entries = [];

        foreach ($records as $record) {
            $entries[] = [
                'ip' => $record['ip'],
                'mac' => $this->extractMacFromClientId($record['clientId']),
                'expires' => $record['expires'],
                'type' => $record['type'],
                'state' => $record['state'],
                'interface' => $record['interface'],
            ];
        }

        return $entries;
    }

    /**
     * Collect each binding record's columns plus its (possibly wrapped) client-ID.
     *
     * The client-ID can span several indented continuation lines while the
     * remaining columns stay on the first line of the record.
     *
     * @param  array<int, string>  $lines
     * @return list<array<string, string>> each with keys ip, clientId, expires, type, state, interface
     */
    private function collectDhcpBindingRecords(array $lines): array
    {
        $records = [];
        $current = null;

        foreach ($lines as $line) {
            if ($line === '') {
                continue;
            }

            if (($line[0] === ' ' || $line[0] === "\t") && $current !== null) {
                $records[$current]['clientId'] .= trim($line);

                continue;
            }

            if (! preg_match('/^(\d{1,3}(?:\.\d{1,3}){3})\s+(\S+)\s+(.+?)\s{2,}(\S+)\s+(\S+)\s+(\S+)\s*$/', $line, $matches)) {
                $current = null;

                continue;
            }

            $records[] = [
                'ip' => $matches[1],
                'clientId' => $matches[2],
                'expires' => trim($matches[3]),
                'type' => $matches[4],
                'state' => $matches[5],
                'interface' => $matches[6],
            ];
            $current = count($records) - 1;
        }

        return $records;
    }

    private const int RFC4361_TYPE_BYTE_HEX_LENGTH = 2;

    private const int RFC4361_IAID_HEX_LENGTH = 8;

    private const int RFC4361_PREFIX_HEX_LENGTH = self::RFC4361_TYPE_BYTE_HEX_LENGTH + self::RFC4361_IAID_HEX_LENGTH;

    /**
     * Extract and normalise a MAC address from a Cisco DHCP client-ID string.
     *
     * Handles three formats:
     *  - 7-group dotted hex with hardware-type prefix: 0100.1122.3344.55
     *    (strip leading 01 byte, remaining 6 bytes are the MAC)
     *  - Standard 3-group dotted hex MAC: aabb.ccdd.eeff
     *  - RFC 4361 client-ID: type ff + 4-byte IAID + embedded DUID, where the
     *    DUID-LL/DUID-LLT carries the hardware MAC in its trailing 6 bytes
     *    (e.g. ff11.98f7.4000.0100.0131.756f.acbc.2411.98f7.40)
     *
     * Returns the MAC in uppercase colon-separated format (AA:BB:CC:DD:EE:FF),
     * or null if the string is not a recognised format.
     */
    public function extractMacFromClientId(string $clientId): ?string
    {
        if ($clientId === '') {
            return null;
        }

        $normalised = strtolower(str_replace('.', '', $clientId));
        if (str_starts_with($normalised, 'ff') && ctype_xdigit($normalised)) {
            $mac = Duid::macAddress(substr($normalised, self::RFC4361_PREFIX_HEX_LENGTH));

            if ($mac !== null) {
                return $mac;
            }
        }

        if (($mac = $this->extractMacFromHardwarePrefixedClientId($clientId)) !== null) {
            return $mac;
        }

        if (($mac = $this->extractMacFromDottedHexClientId($clientId)) !== null) {
            return $mac;
        }

        return null;
    }

    private function extractMacFromHardwarePrefixedClientId(string $clientId): ?string
    {
        if (preg_match('/^01([0-9a-fA-F]{2})\.([0-9a-fA-F]{4})\.([0-9a-fA-F]{4})\.([0-9a-fA-F]{2})$/', $clientId, $m)) {
            $hex = $m[1].$m[2].$m[3].$m[4];

            return implode(':', str_split(strtoupper($hex), 2));
        }

        return null;
    }

    private function extractMacFromDottedHexClientId(string $clientId): ?string
    {
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
            $exclusion = $this->parseExcludedAddressLine($line);
            if ($exclusion !== null) {
                $excluded[] = $exclusion;

                continue;
            }

            $poolHeader = $this->parsePoolHeaderLine($line);
            if ($poolHeader !== null) {
                if ($current !== null) {
                    $pools[] = $current;
                }

                $current = $poolHeader;

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
     * Parse an `ip dhcp excluded-address <start> [end]` line.
     *
     * @return array{start: string, end: string}|null
     */
    private function parseExcludedAddressLine(string $line): ?array
    {
        if (! preg_match('/^ip dhcp excluded-address\s+(\d{1,3}(?:\.\d{1,3}){3})(?:\s+(\d{1,3}(?:\.\d{1,3}){3}))?/', $line, $m)) {
            return null;
        }

        $start = $m[1];
        $end = isset($m[2]) ? $m[2] : $start;

        return ['start' => $start, 'end' => $end];
    }

    /**
     * Parse an `ip dhcp pool <name>` header line into a fresh pool record.
     *
     * @return array{name: string, network: string, mask: string, gateway: string}|null
     */
    private function parsePoolHeaderLine(string $line): ?array
    {
        if (! preg_match('/^ip dhcp pool\s+(\S+)/', $line, $m)) {
            return null;
        }

        return ['name' => $m[1], 'network' => '', 'mask' => '', 'gateway' => ''];
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
                $pendingMac = Duid::macAddress($duid);
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

    /**
     * Compute effective DHCP ranges by subtracting excluded addresses from each pool's usable range.
     *
     * Takes the output of parseDhcpPoolConfig() and returns the resulting address ranges per pool.
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

            $prefix = $this->maskToPrefixLength($maskLong);

            $hostMin = $this->firstUsableHost($networkLong);
            $hostMax = $this->lastUsableHost($networkLong, $maskLong);

            $subnet = $pool['network'].'/'.$prefix;

            $exclusions = $this->overlappingExclusionsClampedToRange($poolConfig['excluded'], $hostMin, $hostMax);
            usort($exclusions, fn (array $a, array $b): int => $a['start'] <=> $b['start']);

            $results = array_merge(
                $results,
                $this->subtractExclusions($hostMin, $hostMax, $exclusions, $pool, $subnet)
            );
        }

        return $results;
    }

    private function maskToPrefixLength(int $maskLong): int
    {
        return substr_count(sprintf('%032b', $maskLong & 0xFFFFFFFF), '1');
    }

    private function firstUsableHost(int $networkLong): int
    {
        return $networkLong + 1;
    }

    private function lastUsableHost(int $networkLong, int $maskLong): int
    {
        $broadcastLong = $networkLong | (~$maskLong & 0xFFFFFFFF);

        return $broadcastLong - 1;
    }

    /**
     * @param  array<int, array{start: string, end: string}>  $excluded
     * @return array<int, array{start: int, end: int}>
     */
    private function overlappingExclusionsClampedToRange(array $excluded, int $hostMin, int $hostMax): array
    {
        $overlapping = [];

        foreach ($excluded as $ex) {
            $exStart = ip2long($ex['start']);
            $exEnd = ip2long($ex['end']);
            if ($exStart === false || $exEnd === false) {
                continue;
            }

            $overlapsUsableRange = $exEnd >= $hostMin && $exStart <= $hostMax;
            if (! $overlapsUsableRange) {
                continue;
            }

            $overlapping[] = ['start' => max($exStart, $hostMin), 'end' => min($exEnd, $hostMax)];
        }

        return $overlapping;
    }

    /**
     * @param  array<int, array{start: int, end: int}>  $exclusions  sorted by start address
     * @param  array{name: string, network: string, mask: string, gateway: string}  $pool
     * @return array<int, array{name: string, subnet: string, range_from: string, range_to: string, total_addresses: string, gateway: string}>
     */
    private function subtractExclusions(int $hostMin, int $hostMax, array $exclusions, array $pool, string $subnet): array
    {
        $ranges = [];
        $cursor = $hostMin;

        foreach ($exclusions as $exclusion) {
            if ($cursor > $hostMax) {
                break;
            }

            $exStart = $exclusion['start'];
            $exEnd = $exclusion['end'];

            $hasGapBeforeExclusion = $exStart > $cursor;
            if ($hasGapBeforeExclusion) {
                $from = long2ip($cursor);
                $to = long2ip($exStart - 1);
                $count = bcadd((string) ($exStart - 1 - $cursor), '1');
                $ranges[] = [
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

        if ($cursor <= $hostMax) {
            $from = long2ip($cursor);
            $to = long2ip($hostMax);
            $count = bcadd((string) ($hostMax - $cursor), '1');
            $ranges[] = [
                'name' => $pool['name'],
                'subnet' => $subnet,
                'range_from' => $from,
                'range_to' => $to,
                'total_addresses' => $count,
                'gateway' => $pool['gateway'],
            ];
        }

        return $ranges;
    }
}
