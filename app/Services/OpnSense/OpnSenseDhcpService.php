<?php

declare(strict_types=1);

namespace App\Services\OpnSense;

use App\Models\IpAddress;
use App\Services\Http\ExternalHttp;
use App\Services\Interfaces\DhcpInterface;
use App\Services\ValueObjects\DhcpLease;
use App\Services\ValueObjects\DhcpPoolStatus;
use App\Services\ValueObjects\DhcpRange;
use Illuminate\Http\Client\PendingRequest;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Log;
use Throwable;
use UnexpectedValueException;

class OpnSenseDhcpService implements DhcpInterface
{
    private const LEASE_PAGE_SIZE = 100;

    private const MAX_LEASE_PAGES = 200;

    private const HEALTHY = ['ipv4' => true, 'ipv6' => true, 'ipv4_ranges' => true, 'ipv6_ranges' => true];

    /** @var array{ipv4: bool, ipv6: bool, ipv4_ranges: bool, ipv6_ranges: bool} */
    private array $fetchStatus = self::HEALTHY;

    /**
     * @param  array{ip: string, mac: string, hostname: string, expires: string, status: string}  $leaseFieldMap
     * @param  array{interface: string, subnet: string, range_from: string, range_to: string, gateway: string, description: string, prefix: string, subnet_mask?: string, pools?: string}  $rangeFieldMap
     */
    public function __construct(
        protected string $endpoint,
        protected string $key,
        protected string $secret,
        protected bool $verifySsl = true,
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

    public function getPoolStatus(string $family = 'ipv4'): DhcpPoolStatus
    {
        if ($family !== 'ipv4') {
            return new DhcpPoolStatus(total: 0, used: 0, available: 0, utilisation: 0.0);
        }

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
        $needle = IpAddress::normalize($ipAddress);

        $leases = $this->fetchLeases();
        $match = $leases->first(fn (array $row): bool => IpAddress::normalize($row['address']) === $needle);

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
            $ranges = $ranges->concat($this->fetchRangesFrom($this->ipv4RangesPath, 'IPv4', $this->ipv4RangesPath === $this->ipv6RangesPath ? ['ipv4_ranges', 'ipv6_ranges'] : ['ipv4_ranges']));
        }

        if ($this->ipv6RangesPath !== '' && $this->ipv6RangesPath !== $this->ipv4RangesPath) {
            $ranges = $ranges->concat($this->fetchRangesFrom($this->ipv6RangesPath, 'IPv6', ['ipv6_ranges']));
        }

        if ($ranges->isEmpty()) {
            return $ranges;
        }

        $leases = $this->fetchLeases();

        return $ranges->map(fn (DhcpRange $range): DhcpRange => $this->enrichRangeWithUsage($range, $leases));
    }

    /**
     * @param  list<'ipv4_ranges'|'ipv6_ranges'>  $statusKeys
     * @return Collection<int, DhcpRange>
     */
    private function fetchRangesFrom(string $path, string $label, array $statusKeys): Collection
    {
        try {
            $data = $this->http()->get($path)->throw()->json();

            if (! is_array($data) || ! is_array($data['rows'] ?? null)) {
                throw new UnexpectedValueException('Unexpected ranges response');
            }

            /** @var list<array<string, mixed>> $rows */
            $rows = $data['rows'];

            return collect($rows)->map(fn (array $row): DhcpRange => $this->buildRangeFromRow($row))->values();
        } catch (Throwable $throwable) {
            foreach ($statusKeys as $key) {
                $this->fetchStatus[$key] = false;
            }

            Log::warning(sprintf('Failed to fetch %s DHCP ranges', $label), ['error' => $throwable->getMessage(), 'path' => $path]);

            return collect();
        }
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

        // IPv6 prefixes are normalized to lowercase for consistent storage/display
        if ($prefix !== null) {
            $prefix = IpAddress::normalize($prefix);
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

        return (int) (32 - log(($long ^ 0xFFFFFFFF) + 1, 2));
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

        return $this->buildEnrichedRange($range, $totalAddresses, $usedAddresses);
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

        return $this->buildEnrichedRange($range, $totalAddresses, $usedAddresses);
    }

    private function buildEnrichedRange(DhcpRange $range, int $totalAddresses, int $usedAddresses): DhcpRange
    {
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
            totalAddresses: (string) $totalAddresses,
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
     * A failed fetch yields an empty collection and marks both families failed
     * (the leases endpoint is shared, so the affected family is unknown).
     *
     * @return Collection<int, array{address: string, mac: string, hostname: string, ends: string, status: string}>
     */
    protected function fetchLeases(): Collection
    {
        try {
            $rows = $this->leasesUsePost ? $this->fetchAllLeaseRows() : $this->fetchLeaseRows($this->http()->get($this->leasesPath))['rows'];
        } catch (Throwable $throwable) {
            $this->fetchStatus['ipv4'] = false;
            $this->fetchStatus['ipv6'] = false;
            Log::warning('Failed to fetch DHCP leases', ['error' => $throwable->getMessage(), 'path' => $this->leasesPath]);

            return collect();
        }

        return collect($rows)->map(fn (array $row): array => [
            'address' => (string) ($row[$this->leaseFieldMap['ip']] ?? ''),
            'mac' => (string) ($row[$this->leaseFieldMap['mac']] ?? ''),
            'hostname' => (string) ($row[$this->leaseFieldMap['hostname']] ?? ''),
            'ends' => (string) ($row[$this->leaseFieldMap['expires']] ?? ''),
            'status' => (string) ($row[$this->leaseFieldMap['status']] ?? 'active'),
        ]);
    }

    protected function http(): PendingRequest
    {
        return ExternalHttp::request($this->endpoint, $this->verifySsl)
            ->withBasicAuth($this->key, $this->secret);
    }

    /**
     * @return list<array<string, mixed>>
     */
    private function fetchAllLeaseRows(): array
    {
        $all = [];

        for ($page = 1; $page <= self::MAX_LEASE_PAGES; $page++) {
            $result = $this->fetchLeaseRows($this->http()->post($this->leasesPath, ['current' => $page, 'rowCount' => self::LEASE_PAGE_SIZE]));
            $all = array_merge($all, $result['rows']);

            $done = $result['total'] !== null
                ? count($all) >= $result['total'] || $result['rows'] === []
                : count($result['rows']) < self::LEASE_PAGE_SIZE;

            if ($done) {
                return $all;
            }
        }

        throw new UnexpectedValueException('Lease pagination exceeded '.self::MAX_LEASE_PAGES.' pages');
    }

    /**
     * @return array{rows: list<array<string, mixed>>, total: int|null}
     */
    private function fetchLeaseRows(Response $response): array
    {
        $data = $response->throw()->json();

        if (! is_array($data) || ! is_array($data['rows'] ?? null)) {
            throw new UnexpectedValueException('Unexpected leases response');
        }

        /** @var list<array<string, mixed>> $rows */
        $rows = $data['rows'];

        return ['rows' => $rows, 'total' => isset($data['total']) && is_numeric($data['total']) ? (int) $data['total'] : null];
    }

    /** @return array{ipv4: bool, ipv6: bool, ipv4_ranges: bool, ipv6_ranges: bool} */
    public function getFetchStatus(): array
    {
        return $this->fetchStatus;
    }

    public function resetSnapshot(): void
    {
        $this->fetchStatus = self::HEALTHY;
    }
}
