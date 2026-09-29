<?php

declare(strict_types=1);

namespace App\Services\OpnSense;

use App\Enums\AddressFamily;
use App\Models\IpAddress;
use App\Services\Dhcp\RangeUsageCalculator;
use App\Services\Interfaces\DhcpInterface;
use App\Services\ValueObjects\DhcpFetchStatus;
use App\Services\ValueObjects\DhcpLease;
use App\Services\ValueObjects\DhcpPoolStatus;
use App\Services\ValueObjects\DhcpRange;
use App\Services\ValueObjects\DhcpSnapshot;
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

    /**
     * @param  array{ip: string, mac: string, hostname: string, expires: string, status: string}  $leaseFieldMap
     * @param  array{interface: string, subnet: string, range_from: string, range_to: string, gateway: string, description: string, prefix: string, subnet_mask?: string, pools?: string}  $rangeFieldMap
     */
    public function __construct(
        protected PendingRequest $client,
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

    public function snapshot(): DhcpSnapshot
    {
        $ipv4RangesOk = true;
        $ipv6RangesOk = true;
        $ranges = collect();

        if ($this->ipv4RangesPath !== '') {
            $shared = $this->ipv4RangesPath === $this->ipv6RangesPath;
            $fetched = $this->fetchRangesFrom($this->ipv4RangesPath, 'IPv4');

            if ($fetched === null) {
                $ipv4RangesOk = false;
                $ipv6RangesOk = $shared ? false : $ipv6RangesOk;
            } else {
                $ranges = $ranges->concat($fetched);
            }
        }

        if ($this->ipv6RangesPath !== '' && $this->ipv6RangesPath !== $this->ipv4RangesPath) {
            $fetched = $this->fetchRangesFrom($this->ipv6RangesPath, 'IPv6');

            if ($fetched === null) {
                $ipv6RangesOk = false;
            } else {
                $ranges = $ranges->concat($fetched);
            }
        }

        $rows = $this->fetchLeases();
        $leasesOk = $rows instanceof Collection;
        $rows ??= collect();

        $leases = $rows->map(fn (array $row): DhcpLease => $this->toLease($row))->values();

        $leaseIps = $rows->pluck('address');
        $ranges = $ranges->map(fn (DhcpRange $range): DhcpRange => RangeUsageCalculator::enrich($range, $leaseIps));

        $activeCount = $rows->where('status', 'active')->count();

        return DhcpSnapshot::create(
            $leases,
            $ranges,
            new DhcpFetchStatus($leasesOk, $ipv4RangesOk),
            new DhcpFetchStatus($leasesOk, $ipv6RangesOk),
            [AddressFamily::IPv4->value => new DhcpPoolStatus(
                total: $this->poolSize,
                used: $activeCount,
                available: max(0, $this->poolSize - $activeCount),
                utilisation: $this->poolSize > 0 ? round($activeCount / $this->poolSize, 4) : 0.0,
            )],
        );
    }

    public function getLease(string $ipAddress): ?DhcpLease
    {
        $needle = IpAddress::normalize($ipAddress);

        $match = $this->fetchLeases()?->first(fn (array $row): bool => IpAddress::normalize($row['address']) === $needle);

        return $match === null ? null : $this->toLease($match);
    }

    /**
     * @param  array{address: string, mac: string, hostname: string, ends: string, status: string}  $row
     */
    private function toLease(array $row): DhcpLease
    {
        return new DhcpLease(
            ip: $row['address'],
            mac: $row['mac'],
            hostname: $row['hostname'],
            expires: $row['ends'],
        );
    }

    /**
     * @return Collection<int, DhcpRange>|null
     */
    private function fetchRangesFrom(string $path, string $label): ?Collection
    {
        try {
            $data = $this->client->get($path)->throw()->json();

            if (! is_array($data) || ! is_array($data['rows'] ?? null)) {
                throw new UnexpectedValueException('Unexpected ranges response');
            }

            /** @var list<array<string, mixed>> $rows */
            $rows = $data['rows'];

            return collect($rows)->map(fn (array $row): DhcpRange => $this->buildRangeFromRow($row))->values();
        } catch (Throwable $throwable) {
            Log::warning(sprintf('Failed to fetch %s DHCP ranges', $label), ['error' => $throwable->getMessage(), 'path' => $path]);

            return null;
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
            type: $this->detectAddressFamily($subnet, $rangeFrom, $prefix),
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

    private function detectAddressFamily(?string $subnet, ?string $rangeFrom, ?string $prefix): AddressFamily
    {
        foreach ([$rangeFrom, $subnet, $prefix] as $value) {
            if ($value !== null && AddressFamily::fromIp($value) === AddressFamily::IPv6) {
                return AddressFamily::IPv6;
            }
        }

        return AddressFamily::IPv4;
    }

    /**
     * @return Collection<int, array{address: string, mac: string, hostname: string, ends: string, status: string}>|null
     */
    protected function fetchLeases(): ?Collection
    {
        try {
            $rows = $this->leasesUsePost ? $this->fetchAllLeaseRows() : $this->fetchLeaseRows($this->client->get($this->leasesPath))['rows'];
        } catch (Throwable $throwable) {
            Log::warning('Failed to fetch DHCP leases', ['error' => $throwable->getMessage(), 'path' => $this->leasesPath]);

            return null;
        }

        return collect($rows)->map(fn (array $row): array => [
            'address' => (string) ($row[$this->leaseFieldMap['ip']] ?? ''),
            'mac' => (string) ($row[$this->leaseFieldMap['mac']] ?? ''),
            'hostname' => (string) ($row[$this->leaseFieldMap['hostname']] ?? ''),
            'ends' => (string) ($row[$this->leaseFieldMap['expires']] ?? ''),
            'status' => (string) ($row[$this->leaseFieldMap['status']] ?? 'active'),
        ]);
    }

    /**
     * @return list<array<string, mixed>>
     */
    private function fetchAllLeaseRows(): array
    {
        $all = [];

        for ($page = 1; $page <= self::MAX_LEASE_PAGES; $page++) {
            $result = $this->fetchLeaseRows($this->client->post($this->leasesPath, ['current' => $page, 'rowCount' => self::LEASE_PAGE_SIZE]));
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
}
