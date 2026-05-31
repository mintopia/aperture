<?php

declare(strict_types=1);

namespace App\Services\VyOs;

use App\Services\Interfaces\DhcpInterface;
use App\Services\ValueObjects\DhcpLease;
use App\Services\ValueObjects\DhcpPoolStatus;
use App\Services\ValueObjects\DhcpRange;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Log;
use Throwable;

class VyOsDhcpService implements DhcpInterface
{
    public function __construct(
        private VyOsClient $client,
        private int $poolSize = 0,
    ) {}

    public function getPoolStatus(): DhcpPoolStatus
    {
        $leaseCount = $this->getLeases()->count();

        return new DhcpPoolStatus(
            total: $this->poolSize,
            used: $leaseCount,
            available: max(0, $this->poolSize - $leaseCount),
            utilisation: $this->poolSize > 0 ? round($leaseCount / $this->poolSize, 4) : 0.0,
        );
    }

    /** @return Collection<int, DhcpLease> */
    public function getLeases(): Collection
    {
        $v4Leases = $this->fetchDhcpv4Leases();
        $v6Leases = $this->fetchDhcpv6Leases();

        return $v4Leases->concat($v6Leases)->values();
    }

    public function getLease(string $ipAddress): ?DhcpLease
    {
        return $this->getLeases()->first(fn (DhcpLease $lease): bool => $lease->ip === $ipAddress);
    }

    /** @return Collection<int, DhcpRange> */
    public function getRanges(): Collection
    {
        $ranges = collect();

        $v4Ranges = $this->fetchDhcpv4Ranges();
        $v6Ranges = $this->fetchDhcpv6Ranges();

        $ranges = $ranges->concat($v4Ranges)->concat($v6Ranges);

        if ($ranges->isEmpty()) {
            return $ranges;
        }

        $leases = $this->getLeases();

        return $ranges->map(fn (DhcpRange $range): DhcpRange => $this->enrichRangeWithUsage($range, $leases))->values();
    }

    /** @return Collection<int, DhcpLease> */
    private function fetchDhcpv4Leases(): Collection
    {
        try {
            $data = $this->client->show(['dhcp', 'server', 'leases']);

            return collect($data)->map(fn (array $entry, string $ip): DhcpLease => new DhcpLease(
                ip: $ip,
                mac: (string) ($entry['hardware_address'] ?? ''),
                hostname: (string) ($entry['hostname'] ?? ''),
                expires: (string) ($entry['expires'] ?? ''),
            ))->values();
        } catch (Throwable $e) {
            Log::warning('Failed to fetch VyOS DHCPv4 leases', ['error' => $e->getMessage()]);

            return collect();
        }
    }

    /** @return Collection<int, DhcpLease> */
    private function fetchDhcpv6Leases(): Collection
    {
        try {
            $data = $this->client->show(['dhcpv6', 'server', 'leases']);

            return collect($data)->map(fn (array $entry, string $ip): DhcpLease => new DhcpLease(
                ip: $ip,
                mac: '',
                hostname: '',
                expires: (string) ($entry['expires'] ?? ''),
            ))->values();
        } catch (Throwable $e) {
            Log::warning('Failed to fetch VyOS DHCPv6 leases', ['error' => $e->getMessage()]);

            return collect();
        }
    }

    /** @return Collection<int, DhcpRange> */
    private function fetchDhcpv4Ranges(): Collection
    {
        try {
            $data = $this->client->retrieve(['service', 'dhcp-server', 'shared-network-name']);

            return $this->parseRangesFromConfig($data, 'ipv4');
        } catch (Throwable $e) {
            Log::warning('Failed to fetch VyOS DHCPv4 ranges', ['error' => $e->getMessage()]);

            return collect();
        }
    }

    /** @return Collection<int, DhcpRange> */
    private function fetchDhcpv6Ranges(): Collection
    {
        try {
            $data = $this->client->retrieve(['service', 'dhcpv6-server', 'shared-network-name']);

            return $this->parseRangesFromConfig($data, 'ipv6');
        } catch (Throwable $e) {
            Log::warning('Failed to fetch VyOS DHCPv6 ranges', ['error' => $e->getMessage()]);

            return collect();
        }
    }

    /**
     * @param  array<string, mixed>  $networkData
     * @return Collection<int, DhcpRange>
     */
    private function parseRangesFromConfig(array $networkData, string $type): Collection
    {
        $ranges = collect();

        foreach ($networkData as $networkName => $network) {
            if (! is_array($network) || ! isset($network['subnet'])) {
                continue;
            }

            foreach ($network['subnet'] as $subnetCidr => $subnetConfig) {
                if (! is_array($subnetConfig)) {
                    continue;
                }

                if ($type === 'ipv4') {
                    $this->extractIpv4Ranges($ranges, (string) $networkName, (string) $subnetCidr, $subnetConfig);
                } else {
                    $this->extractIpv6Ranges($ranges, (string) $networkName, (string) $subnetCidr, $subnetConfig);
                }
            }
        }

        return $ranges;
    }

    /**
     * @param  Collection<int, DhcpRange>  $ranges
     * @param  array<string, mixed>  $subnetConfig
     */
    private function extractIpv4Ranges(Collection $ranges, string $networkName, string $subnetCidr, array $subnetConfig): void
    {
        $rangeData = $subnetConfig['range'] ?? [];
        if (! is_array($rangeData)) {
            return;
        }

        foreach ($rangeData as $rangeName => $range) {
            if (! is_array($range) || ! isset($range['start'], $range['stop'])) {
                continue;
            }

            $ranges->push(new DhcpRange(
                interface: $networkName,
                type: 'ipv4',
                subnet: $subnetCidr,
                rangeFrom: (string) $range['start'],
                rangeTo: (string) $range['stop'],
                prefix: null,
                gateway: isset($subnetConfig['default-router']) ? (string) $subnetConfig['default-router'] : null,
                description: $networkName,
            ));
        }
    }

    /**
     * @param  Collection<int, DhcpRange>  $ranges
     * @param  array<string, mixed>  $subnetConfig
     */
    private function extractIpv6Ranges(Collection $ranges, string $networkName, string $subnetCidr, array $subnetConfig): void
    {
        $addressRange = $subnetConfig['address-range'] ?? [];
        if (! is_array($addressRange) || ! isset($addressRange['start'])) {
            return;
        }

        foreach ($addressRange['start'] as $startAddr => $rangeConfig) {
            if (! is_array($rangeConfig) || ! isset($rangeConfig['stop'])) {
                continue;
            }

            $ranges->push(new DhcpRange(
                interface: $networkName,
                type: 'ipv6',
                subnet: $subnetCidr,
                rangeFrom: (string) $startAddr,
                rangeTo: (string) $rangeConfig['stop'],
                prefix: $subnetCidr,
                gateway: null,
                description: $networkName,
            ));
        }
    }

    /**
     * @param  Collection<int, DhcpLease>  $leases
     */
    private function enrichRangeWithUsage(DhcpRange $range, Collection $leases): DhcpRange
    {
        if ($range->rangeFrom === null || $range->rangeTo === null) {
            return $range; // @codeCoverageIgnore
        }

        if ($range->type === 'ipv6') {
            return $this->enrichIpv6RangeWithUsage($range, $leases);
        }

        return $this->enrichIpv4RangeWithUsage($range, $leases);
    }

    /**
     * @param  Collection<int, DhcpLease>  $leases
     */
    private function enrichIpv4RangeWithUsage(DhcpRange $range, Collection $leases): DhcpRange
    {
        $fromLong = ip2long((string) $range->rangeFrom);
        $toLong = ip2long((string) $range->rangeTo);

        if ($fromLong === false || $toLong === false) {
            return $range; // @codeCoverageIgnore
        }

        $totalAddresses = $toLong - $fromLong + 1;

        $usedAddresses = $leases->filter(function (DhcpLease $lease) use ($fromLong, $toLong): bool {
            $leaseIp = ip2long($lease->ip);

            return $leaseIp !== false && $leaseIp >= $fromLong && $leaseIp <= $toLong;
        })->count();

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
            utilisation: $totalAddresses > 0 ? round($usedAddresses / $totalAddresses, 4) : 0.0,
        );
    }

    /**
     * @param  Collection<int, DhcpLease>  $leases
     */
    private function enrichIpv6RangeWithUsage(DhcpRange $range, Collection $leases): DhcpRange
    {
        $fromBin = inet_pton((string) $range->rangeFrom);
        $toBin = inet_pton((string) $range->rangeTo);

        if ($fromBin === false || $toBin === false) {
            return $range; // @codeCoverageIgnore
        }

        $totalAddresses = $this->ipv6Diff($fromBin, $toBin) + 1;

        $usedAddresses = $leases->filter(function (DhcpLease $lease) use ($fromBin, $toBin): bool {
            $leaseBin = inet_pton($lease->ip);

            return $leaseBin !== false && $leaseBin >= $fromBin && $leaseBin <= $toBin;
        })->count();

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
            utilisation: $totalAddresses > 0 ? round($usedAddresses / $totalAddresses, 4) : 0.0,
        );
    }

    private function ipv6Diff(string $fromBin, string $toBin): int
    {
        $result = 0;

        for ($i = 0; $i <= 15; $i++) {
            $diff = ord($toBin[$i]) - ord($fromBin[$i]);
            $result = ($result << 8) + $diff;

            if ($result > PHP_INT_MAX >> 8) {
                return PHP_INT_MAX; // @codeCoverageIgnore
            }
        }

        return max(0, $result);
    }
}
