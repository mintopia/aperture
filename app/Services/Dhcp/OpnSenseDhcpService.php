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
     * @param  array{interface: string, subnet: string, range_from: string, range_to: string, gateway: string, description: string, prefix: string}  $rangeFieldMap
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
                    $ranges->push(new DhcpRange(
                        interface: (string) ($row[$this->rangeFieldMap['interface']] ?? ''),
                        type: 'ipv4',
                        subnet: isset($row[$this->rangeFieldMap['subnet']]) ? (string) $row[$this->rangeFieldMap['subnet']] : null,
                        rangeFrom: isset($row[$this->rangeFieldMap['range_from']]) ? (string) $row[$this->rangeFieldMap['range_from']] : null,
                        rangeTo: isset($row[$this->rangeFieldMap['range_to']]) ? (string) $row[$this->rangeFieldMap['range_to']] : null,
                        prefix: null,
                        gateway: isset($row[$this->rangeFieldMap['gateway']]) ? (string) $row[$this->rangeFieldMap['gateway']] : null,
                        description: isset($row[$this->rangeFieldMap['description']]) ? (string) $row[$this->rangeFieldMap['description']] : null,
                    ));
                }
            } catch (Throwable $e) {
                Log::warning('Failed to fetch IPv4 DHCP ranges', ['error' => $e->getMessage(), 'path' => $this->ipv4RangesPath]);
            }
        }

        if ($this->ipv6RangesPath !== '') {
            try {
                $response = $this->client->get($this->ipv6RangesPath);

                /** @var array{rows?: list<array<string, mixed>>} $data */
                $data = json_decode($response->getBody()->getContents(), true);

                foreach ($data['rows'] ?? [] as $row) {
                    $ranges->push(new DhcpRange(
                        interface: (string) ($row[$this->rangeFieldMap['interface']] ?? ''),
                        type: 'ipv6',
                        subnet: null,
                        rangeFrom: isset($row[$this->rangeFieldMap['range_from']]) ? (string) $row[$this->rangeFieldMap['range_from']] : null,
                        rangeTo: isset($row[$this->rangeFieldMap['range_to']]) ? (string) $row[$this->rangeFieldMap['range_to']] : null,
                        prefix: isset($row[$this->rangeFieldMap['prefix']]) ? (string) $row[$this->rangeFieldMap['prefix']] : null,
                        gateway: null,
                        description: isset($row[$this->rangeFieldMap['description']]) ? (string) $row[$this->rangeFieldMap['description']] : null,
                    ));
                }
            } catch (Throwable $e) {
                Log::warning('Failed to fetch IPv6 DHCP ranges', ['error' => $e->getMessage(), 'path' => $this->ipv6RangesPath]);
            }
        }

        return $ranges;
    }

    /**
     * @return Collection<int, array{address: string, mac: string, hostname: string, ends: string, status: string}>
     */
    protected function fetchLeases(): Collection
    {
        $response = $this->client->get($this->leasesPath);

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
