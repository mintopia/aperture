<?php

declare(strict_types=1);

namespace App\Services\VyOs;

use App\Models\IpAddress;
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
        $needle = IpAddress::normalize($ipAddress);

        return $this->getLeases()->first(fn (DhcpLease $lease): bool => IpAddress::normalize($lease->ip) === $needle);
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
            $text = $this->client->showText(['dhcp', 'server', 'leases']);
            $rows = $this->parseTextTable($text);

            return collect($rows)
                ->filter(fn (array $row): bool => ($row['ip address'] ?? '') !== '')
                ->map(fn (array $row): DhcpLease => new DhcpLease(
                    ip: $row['ip address'],
                    mac: $row['mac address'] ?? '',
                    hostname: $row['hostname'] ?? '',
                    expires: $row['lease expiration'] ?? '',
                ))->values();
        } catch (Throwable $throwable) {
            Log::warning('Failed to fetch VyOS DHCPv4 leases', ['error' => $throwable->getMessage()]);

            return collect();
        }
    }

    /** @return Collection<int, DhcpLease> */
    private function fetchDhcpv6Leases(): Collection
    {
        try {
            $text = $this->client->showText(['dhcpv6', 'server', 'leases']);
            $rows = $this->parseTextTable($text);

            return collect($rows)
                ->filter(fn (array $row): bool => ($row['ipv6 address'] ?? '') !== '')
                ->map(fn (array $row): DhcpLease => new DhcpLease(
                    ip: $row['ipv6 address'],
                    mac: $row['mac address'] ?? '',
                    hostname: $row['hostname'] ?? '',
                    expires: $row['lease expiration'] ?? '',
                ))->values();
        } catch (Throwable $throwable) {
            Log::warning('Failed to fetch VyOS DHCPv6 leases', ['error' => $throwable->getMessage()]);

            return collect();
        }
    }

    /** @return Collection<int, DhcpRange> */
    private function fetchDhcpv4Ranges(): Collection
    {
        try {
            $data = $this->client->retrieve(['service', 'dhcp-server', 'shared-network-name']);
            $networks = $data['shared-network-name'] ?? $data;

            return $this->parseRangesFromConfig($networks, 'ipv4');
        } catch (Throwable $throwable) {
            Log::warning('Failed to fetch VyOS DHCPv4 ranges', ['error' => $throwable->getMessage()]);

            return collect();
        }
    }

    /** @return Collection<int, DhcpRange> */
    private function fetchDhcpv6Ranges(): Collection
    {
        try {
            $data = $this->client->retrieve(['service', 'dhcpv6-server', 'shared-network-name']);
            $networks = $data['shared-network-name'] ?? $data;

            return $this->parseRangesFromConfig($networks, 'ipv6');
        } catch (Throwable $throwable) {
            Log::warning('Failed to fetch VyOS DHCPv6 ranges', ['error' => $throwable->getMessage()]);

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

                $this->extractRanges($ranges, (string) $networkName, (string) $subnetCidr, $subnetConfig, $type);
            }
        }

        return $ranges;
    }

    /**
     * @param  Collection<int, DhcpRange>  $ranges
     * @param  array<string, mixed>  $subnetConfig
     */
    private function extractRanges(Collection $ranges, string $networkName, string $subnetCidr, array $subnetConfig, string $type): void
    {
        $rangeData = $subnetConfig['range'] ?? [];
        if (! is_array($rangeData)) {
            return;
        }

        foreach ($rangeData as $range) {
            if (! is_array($range) || ! isset($range['start'], $range['stop'])) {
                continue;
            }

            $gateway = $type === 'ipv4'
                ? ($subnetConfig['option']['default-router'] ?? null)
                : null;

            $ranges->push(new DhcpRange(
                interface: $networkName,
                type: $type,
                subnet: $subnetCidr,
                rangeFrom: (string) $range['start'],
                rangeTo: (string) $range['stop'],
                prefix: $type === 'ipv6' ? IpAddress::normalize($subnetCidr) : null,
                gateway: $gateway !== null ? (string) $gateway : null,
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

    /**
     * @return list<array<string, string>>
     */
    private function parseTextTable(string $text): array
    {
        $lines = explode("\n", $text);
        /** @var list<string> $headers */
        $headers = [];
        /** @var list<array{start: int, length: int}> $columnBounds */
        $columnBounds = [];
        $rows = [];
        $separatorFound = false;

        foreach ($lines as $i => $line) {
            if (! $separatorFound && preg_match('/^[-\s]+$/', $line) && trim($line) !== '') {
                $separatorFound = true;
                preg_match_all('/(-+)/', $line, $matches, PREG_OFFSET_CAPTURE);

                foreach ($matches[1] as $match) {
                    $columnBounds[] = ['start' => $match[1], 'length' => strlen($match[0])];
                }

                if (isset($lines[$i - 1])) {
                    foreach ($columnBounds as $col) {
                        $headers[] = strtolower(trim(substr($lines[$i - 1], $col['start'], $col['length'])));
                    }
                }

                continue;
            }

            if (! $separatorFound || $columnBounds === [] || trim($line) === '') {
                continue;
            }

            $row = [];
            $lastIdx = count($columnBounds) - 1;

            foreach ($columnBounds as $j => $col) {
                if ($col['start'] >= strlen($line)) {
                    $row[$headers[$j] ?? (string) $j] = '';

                    continue;
                }

                $raw = $j === $lastIdx
                    ? substr($line, $col['start'])
                    : substr($line, $col['start'], $col['length']);

                $row[$headers[$j] ?? (string) $j] = trim($raw);
            }

            $rows[] = $row;
        }

        return $rows;
    }
}
