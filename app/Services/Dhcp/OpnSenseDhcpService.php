<?php

declare(strict_types=1);

namespace App\Services\Dhcp;

use App\Services\Interfaces\DhcpInterface;
use App\Services\ValueObjects\DhcpLease;
use App\Services\ValueObjects\DhcpPoolStatus;
use App\Services\ValueObjects\DhcpRange;
use GuzzleHttp\Client;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Log;
use Throwable;

class OpnSenseDhcpService implements DhcpInterface
{
    /**
     * @param  array{ip: string, mac: string, hostname: string, expires: string, status: string}  $leaseFieldMap
     * @param  array{interface: string, subnet: string, range_from: string, range_to: string, gateway: string, description: string, prefix: string, subnet_mask?: string, pools?: string}  $rangeFieldMap
     */
    public function __construct(
        protected Client $client,
        protected int $poolSize = 0,
        protected string $leasesPath = '/api/dhcpv4/leases/search_lease',
        protected string $ipv4RangesPath = '',
        protected string $ipv6RangesPath = '',
        protected array $leaseFieldMap = [
            'ip' => 'address',
            'mac' => 'mac',
            'hostname' => 'hostname',
            'expires' => 'ends',
            'status' => 'status',
        ],
        protected array $rangeFieldMap = [
            'interface' => 'interface',
            'subnet' => 'subnet',
            'range_from' => 'range_from',
            'range_to' => 'range_to',
            'gateway' => 'gateway',
            'description' => 'description',
            'prefix' => 'prefix',
        ],
        protected bool $leasesUsePost = false,
    ) {}

    public function getPoolStatus(): DhcpPoolStatus
    {
        $leases = $this->fetchLeases();
        $activeCount = $leases->where('status', 'active')->count();

        return new DhcpPoolStatus(
            total: $this->poolSize,
            used: $activeCount,
            available: max(0, $this->poolSize - $activeCount),
            utilisation: $this->poolSize > 0 ? round($activeCount / $this->poolSize, 4) : 0.0,
        );
    }

    /** @return Collection<int, DhcpLease> */
    public function getLeases(): Collection
    {
        return $this->fetchLeases()->map(fn (array $row): DhcpLease => new DhcpLease(
            ip: $row['address'],
            mac: $row['mac'],
            hostname: $row['hostname'],
            expires: $row['ends'],
        ))->values();
    }

    public function getLease(string $ipAddress): ?DhcpLease
    {
        $leases = $this->fetchLeases();
        $match = $leases->firstWhere('address', $ipAddress);

        if ($match === null) {
            return null;
        }

        return new DhcpLease(
            ip: $match['address'],
            mac: $match['mac'],
            hostname: $match['hostname'],
            expires: $match['ends'],
        );
    }

    /** @return Collection<int, DhcpRange> */
    public function getRanges(): Collection
    {
        $ranges = collect();

        if ($this->ipv4RangesPath !== '') {
            try {
                $response = $this->client->get($this->ipv4RangesPath);

                /** @var array{rows?: list<array<string, mixed>>} $data */
                $data = json_decode($response->getBody()->getContents(), true);

                foreach ($data['rows'] ?? [] as $row) {
                    $ranges->push($this->buildRangeFromRow($row));
                }
            } catch (Throwable $e) {
                Log::warning('Failed to fetch IPv4 DHCP ranges', ['error' => $e->getMessage(), 'path' => $this->ipv4RangesPath]);
            }
        }

        if ($this->ipv6RangesPath !== '' && $this->ipv6RangesPath !== $this->ipv4RangesPath) {
            try {
                $response = $this->client->get($this->ipv6RangesPath);

                /** @var array{rows?: list<array<string, mixed>>} $data */
                $data = json_decode($response->getBody()->getContents(), true);

                foreach ($data['rows'] ?? [] as $row) {
                    $ranges->push($this->buildRangeFromRow($row));
                }
            } catch (Throwable $e) {
                Log::warning('Failed to fetch IPv6 DHCP ranges', ['error' => $e->getMessage(), 'path' => $this->ipv6RangesPath]);
            }
        }

        if ($ranges->isEmpty()) {
            return $ranges;
        }

        $leases = $this->fetchLeases();

        return $ranges->map(fn (DhcpRange $range): DhcpRange => $this->enrichRangeWithUsage($range, $leases));
    }

    /**
     * @param  array<string, mixed>  $row
     */
    private function buildRangeFromRow(array $row): DhcpRange
    {
        $subnet = isset($row[$this->rangeFieldMap['subnet']]) ? (string) $row[$this->rangeFieldMap['subnet']] : null;
        $rangeFrom = isset($row[$this->rangeFieldMap['range_from']]) ? (string) $row[$this->rangeFieldMap['range_from']] : null;
        $rangeTo = isset($row[$this->rangeFieldMap['range_to']]) ? (string) $row[$this->rangeFieldMap['range_to']] : null;
        $prefix = isset($row[$this->rangeFieldMap['prefix']]) ? (string) $row[$this->rangeFieldMap['prefix']] : null;

        // Handle Kea pools format: "START - END"
        if (isset($this->rangeFieldMap['pools']) && isset($row[$this->rangeFieldMap['pools']])) {
            $pools = (string) $row[$this->rangeFieldMap['pools']];
            if (preg_match('/^\s*([^\s-]+)\s*-\s*([^\s-]+)\s*$/', $pools, $matches)) {
                $rangeFrom = $rangeFrom ?: $matches[1];
                $rangeTo = $rangeTo ?: $matches[2];
            }
        }

        // Calculate subnet from start_addr and subnet_mask (dnsmasq IPv4)
        if ($subnet === null && $rangeFrom !== null && isset($this->rangeFieldMap['subnet_mask']) && isset($row[$this->rangeFieldMap['subnet_mask']])) {
            $subnetMask = (string) $row[$this->rangeFieldMap['subnet_mask']];
            if ($subnetMask !== '') {
                $subnet = $this->calculateSubnet($rangeFrom, $subnetMask);
            }
        }

        // Handle dnsmasq IPv6 prefix_len: construct prefix from start address and prefix length
        if ($prefix !== null && $rangeFrom !== null && str_contains($rangeFrom, ':')) {
            // IPv6: construct prefix notation like "fd00::1/64" from start_addr and prefix_len
            $prefixLen = $prefix;
            if (is_numeric($prefixLen)) {
                $prefix = $rangeFrom.'/'.$prefixLen;
            }
        }

        return new DhcpRange(
            interface: (string) ($row[$this->rangeFieldMap['interface']] ?? ''),
            type: $this->detectIpVersion($subnet, $rangeFrom, $prefix),
            subnet: $subnet,
            rangeFrom: $rangeFrom,
            rangeTo: $rangeTo,
            prefix: $prefix,
            gateway: isset($row[$this->rangeFieldMap['gateway']]) ? (string) $row[$this->rangeFieldMap['gateway']] : null,
            description: isset($row[$this->rangeFieldMap['description']]) ? (string) $row[$this->rangeFieldMap['description']] : null,
        );
    }

    private function calculateSubnet(string $ipAddress, string $subnetMask): ?string
    {
        $ip = ip2long($ipAddress);
        $mask = ip2long($subnetMask);

        if ($ip === false || $mask === false) {
            return null;
        }

        $network = $ip & $mask;
        $cidr = $this->subnetMaskToCidr($subnetMask);

        return long2ip($network).($cidr !== null ? '/'.$cidr : '');
    }

    private function subnetMaskToCidr(string $subnetMask): ?int
    {
        $long = ip2long($subnetMask);
        if ($long === false) {
            return null;
        }

        $base = ip2long('255.255.255.255');
        if ($base === false) {
            return null;
        }

        return (int) (32 - log(($long ^ $base) + 1, 2));
    }

    private function detectIpVersion(?string $subnet, ?string $rangeFrom, ?string $prefix): string
    {
        if ($rangeFrom !== null && str_contains($rangeFrom, ':')) {
            return 'ipv6';
        }

        if ($subnet !== null && str_contains($subnet, ':')) {
            return 'ipv6';
        }

        if ($prefix !== null && str_contains($prefix, ':')) {
            return 'ipv6';
        }

        return 'ipv4';
    }

    /**
     * @param  Collection<int, array{address: string, mac: string, hostname: string, ends: string, status: string}>  $leases
     */
    private function enrichRangeWithUsage(DhcpRange $range, Collection $leases): DhcpRange
    {
        if ($range->rangeFrom === null || $range->rangeTo === null) {
            return $range;
        }

        if ($range->type === 'ipv6') {
            return $this->enrichIpv6RangeWithUsage($range, $leases);
        }

        return $this->enrichIpv4RangeWithUsage($range, $leases);
    }

    /**
     * @param  Collection<int, array{address: string, mac: string, hostname: string, ends: string, status: string}>  $leases
     */
    private function enrichIpv4RangeWithUsage(DhcpRange $range, Collection $leases): DhcpRange
    {
        $fromLong = ip2long((string) $range->rangeFrom);
        $toLong = ip2long((string) $range->rangeTo);

        if ($fromLong === false || $toLong === false) {
            return $range;
        }

        $totalAddresses = $toLong - $fromLong + 1;

        $usedAddresses = $leases->filter(function (array $lease) use ($fromLong, $toLong): bool {
            $leaseIp = ip2long($lease['address']);

            return $leaseIp !== false && $leaseIp >= $fromLong && $leaseIp <= $toLong;
        })->count();

        $utilisation = $totalAddresses > 0 ? round($usedAddresses / $totalAddresses, 4) : 0.0;

        return new DhcpRange(
            interface: $range->interface,
            type: $range->type,
            subnet: $range->subnet,
            rangeFrom: $range->rangeFrom,
            rangeTo: $range->rangeTo,
            prefix: $range->prefix,
            gateway: $range->gateway,
            description: $range->description,
            totalAddresses: $totalAddresses,
            usedAddresses: $usedAddresses,
            utilisation: $utilisation,
        );
    }

    /**
     * @param  Collection<int, array{address: string, mac: string, hostname: string, ends: string, status: string}>  $leases
     */
    private function enrichIpv6RangeWithUsage(DhcpRange $range, Collection $leases): DhcpRange
    {
        $fromBin = inet_pton((string) $range->rangeFrom);
        $toBin = inet_pton((string) $range->rangeTo);

        if ($fromBin === false || $toBin === false) {
            return $range;
        }

        $totalAddresses = $this->ipv6Diff($fromBin, $toBin) + 1;

        $usedAddresses = $leases->filter(function (array $lease) use ($fromBin, $toBin): bool {
            $leaseBin = inet_pton($lease['address']);

            return $leaseBin !== false && $leaseBin >= $fromBin && $leaseBin <= $toBin;
        })->count();

        $utilisation = $totalAddresses > 0 ? round($usedAddresses / $totalAddresses, 4) : 0.0;

        return new DhcpRange(
            interface: $range->interface,
            type: $range->type,
            subnet: $range->subnet,
            rangeFrom: $range->rangeFrom,
            rangeTo: $range->rangeTo,
            prefix: $range->prefix,
            gateway: $range->gateway,
            description: $range->description,
            totalAddresses: $totalAddresses,
            usedAddresses: $usedAddresses,
            utilisation: $utilisation,
        );
    }

    private function ipv6Diff(string $fromBin, string $toBin): int
    {
        $result = 0;

        for ($i = 0; $i <= 15; $i++) {
            $diff = ord($toBin[$i]) - ord($fromBin[$i]);
            $result = ($result << 8) + $diff;

            if ($result > PHP_INT_MAX >> 8) {
                return PHP_INT_MAX;
            }
        }

        return max(0, $result);
    }

    /**
     * @return Collection<int, array{address: string, mac: string, hostname: string, ends: string, status: string}>
     */
    protected function fetchLeases(): Collection
    {
        if ($this->leasesUsePost) {
            $response = $this->client->post($this->leasesPath, [
                'json' => ['current' => 1, 'rowCount' => 100],
            ]);
        } else {
            $response = $this->client->get($this->leasesPath);
        }

        /** @var array{rows?: list<array<string, mixed>>} $data */
        $data = json_decode($response->getBody()->getContents(), true);

        return collect($data['rows'] ?? [])->map(fn (array $row): array => [
            'address' => (string) ($row[$this->leaseFieldMap['ip']] ?? ''),
            'mac' => (string) ($row[$this->leaseFieldMap['mac']] ?? ''),
            'hostname' => (string) ($row[$this->leaseFieldMap['hostname']] ?? ''),
            'ends' => (string) ($row[$this->leaseFieldMap['expires']] ?? ''),
            'status' => (string) ($row[$this->leaseFieldMap['status']] ?? 'active'),
        ]);
    }
}
