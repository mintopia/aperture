<?php

declare(strict_types=1);

namespace App\Services\VyOs;

use App\Enums\AddressFamily;
use App\Models\IpAddress;
use App\Services\Dhcp\RangeUsageCalculator;
use App\Services\Interfaces\DhcpInterface;
use App\Services\ValueObjects\DhcpFetchStatus;
use App\Services\ValueObjects\DhcpLease;
use App\Services\ValueObjects\DhcpPoolStatus;
use App\Services\ValueObjects\DhcpRange;
use App\Services\ValueObjects\DhcpSnapshot;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Log;
use Throwable;
use UnexpectedValueException;

class VyOsDhcpService implements DhcpInterface
{
    public function __construct(
        private VyOsClient $client,
        private int $poolSize = 0,
    ) {}

    public function snapshot(): DhcpSnapshot
    {
        $v4Leases = $this->fetchLeases(AddressFamily::IPv4);
        $v6Leases = $this->fetchLeases(AddressFamily::IPv6);
        $v4Ranges = $this->fetchRanges(AddressFamily::IPv4);
        $v6Ranges = $this->fetchRanges(AddressFamily::IPv6);

        $leases = ($v4Leases ?? collect())->concat($v6Leases ?? collect())->values();
        $leaseIps = $leases->map(fn (DhcpLease $lease): string => $lease->ip);

        $ranges = ($v4Ranges ?? collect())->concat($v6Ranges ?? collect())
            ->map(fn (DhcpRange $range): DhcpRange => RangeUsageCalculator::enrich($range, $leaseIps))
            ->values();

        $leaseCount = $leases->count();

        return DhcpSnapshot::create(
            $leases,
            $ranges,
            new DhcpFetchStatus($v4Leases !== null, $v4Ranges !== null),
            new DhcpFetchStatus($v6Leases !== null, $v6Ranges !== null),
            [AddressFamily::IPv4->value => new DhcpPoolStatus(
                total: $this->poolSize,
                used: $leaseCount,
                available: max(0, $this->poolSize - $leaseCount),
                utilisation: $this->poolSize > 0 ? round($leaseCount / $this->poolSize, 4) : 0.0,
            )],
        );
    }

    public function getLease(string $ipAddress): ?DhcpLease
    {
        $needle = IpAddress::normalize($ipAddress);

        return $this->snapshot()->leases->first(fn (DhcpLease $lease): bool => IpAddress::normalize($lease->ip) === $needle);
    }

    /** @return Collection<int, DhcpLease>|null null on fetch failure */
    private function fetchLeases(AddressFamily $family): ?Collection
    {
        $isV4 = $family === AddressFamily::IPv4;
        $ipColumn = $isV4 ? 'ip address' : 'ipv6 address';

        try {
            $text = $this->client->showText([$isV4 ? 'dhcp' : 'dhcpv6', 'server', 'leases']);

            return collect($this->parseTextTable($text))
                ->filter(fn (array $row): bool => ($row[$ipColumn] ?? '') !== '')
                ->map(fn (array $row): DhcpLease => new DhcpLease(
                    ip: $row[$ipColumn],
                    mac: $row['mac address'] ?? '',
                    hostname: $row['hostname'] ?? '',
                    expires: $row['lease expiration'] ?? '',
                ))->values();
        } catch (Throwable $throwable) {
            Log::warning(sprintf('Failed to fetch VyOS DHCPv%d leases', $isV4 ? 4 : 6), ['error' => $throwable->getMessage()]);

            return null;
        }
    }

    /** @return Collection<int, DhcpRange>|null null on fetch failure */
    private function fetchRanges(AddressFamily $family): ?Collection
    {
        $isV4 = $family === AddressFamily::IPv4;

        try {
            $data = $this->client->retrieve(['service', $isV4 ? 'dhcp-server' : 'dhcpv6-server', 'shared-network-name']);
            $networks = $data['shared-network-name'] ?? $data;

            return $this->parseRangesFromConfig($networks, $family);
        } catch (Throwable $throwable) {
            Log::warning(sprintf('Failed to fetch VyOS DHCPv%d ranges', $isV4 ? 4 : 6), ['error' => $throwable->getMessage()]);

            return null;
        }
    }

    /**
     * @param  array<string, mixed>  $networkData
     * @return Collection<int, DhcpRange>
     */
    private function parseRangesFromConfig(array $networkData, AddressFamily $type): Collection
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
    private function extractRanges(Collection $ranges, string $networkName, string $subnetCidr, array $subnetConfig, AddressFamily $type): void
    {
        $rangeData = $subnetConfig['range'] ?? [];
        if (! is_array($rangeData)) {
            return;
        }

        foreach ($rangeData as $range) {
            if (! is_array($range) || ! isset($range['start'], $range['stop'])) {
                continue;
            }

            $gateway = $type === AddressFamily::IPv4
                ? ($subnetConfig['option']['default-router'] ?? null)
                : null;

            $ranges->push(new DhcpRange(
                interface: $networkName,
                type: $type,
                subnet: $subnetCidr,
                rangeFrom: (string) $range['start'],
                rangeTo: (string) $range['stop'],
                prefix: $type === AddressFamily::IPv6 ? IpAddress::normalize($subnetCidr) : null,
                gateway: $gateway !== null ? (string) $gateway : null,
                description: $networkName,
            ));
        }
    }

    /**
     * @return list<array<string, string>>
     */
    private function parseTextTable(string $text): array
    {
        $sawSeparator = false;
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
                $sawSeparator = true;
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

        // Blank output is a valid empty table; anything else without a header separator is not.
        if (! $sawSeparator && trim($text) !== '') {
            throw new UnexpectedValueException('Unparseable DHCP lease table');
        }

        return $rows;
    }
}
