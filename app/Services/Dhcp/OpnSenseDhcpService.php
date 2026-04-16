<?php

declare(strict_types=1);

namespace App\Services\Dhcp;

use App\Services\Interfaces\DhcpInterface;
use App\Services\ValueObjects\DhcpLease;
use App\Services\ValueObjects\DhcpPoolStatus;
use App\Services\ValueObjects\DhcpRange;
use GuzzleHttp\Client;
use Illuminate\Support\Collection;
use Throwable;

class OpnSenseDhcpService implements DhcpInterface
{
    public function __construct(
        protected Client $client,
        protected int $poolSize = 0,
        protected string $leasesPath = '/api/dhcpv4/leases/searchLease',
        protected string $ipv4RangesPath = '/api/dhcpv4/service/searchSubnet',
        protected string $ipv6RangesPath = '/api/dhcpv6/service/searchSubnet',
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

        try {
            $response = $this->client->get($this->ipv4RangesPath, [
                'json' => (object) [],
            ]);

            /** @var array{rows?: list<array{interface?: string, subnet?: string, range_from?: string, range_to?: string, gateway?: string, description?: string}>} $data */
            $data = json_decode($response->getBody()->getContents(), true);

            foreach ($data['rows'] ?? [] as $row) {
                $ranges->push(new DhcpRange(
                    interface: $row['interface'] ?? '',
                    type: 'ipv4',
                    subnet: $row['subnet'] ?? null,
                    rangeFrom: $row['range_from'] ?? null,
                    rangeTo: $row['range_to'] ?? null,
                    prefix: null,
                    gateway: $row['gateway'] ?? null,
                    description: $row['description'] ?? null,
                ));
            }
        } catch (Throwable) {
            // IPv4 ranges not available
        }

        if ($this->ipv6RangesPath !== '') {
            try {
                $response = $this->client->get($this->ipv6RangesPath, [
                    'json' => (object) [],
                ]);

                /** @var array{rows?: list<array{interface?: string, prefix?: string, range_from?: string, range_to?: string, description?: string}>} $data */
                $data = json_decode($response->getBody()->getContents(), true);

                foreach ($data['rows'] ?? [] as $row) {
                    $ranges->push(new DhcpRange(
                        interface: $row['interface'] ?? '',
                        type: 'ipv6',
                        subnet: null,
                        rangeFrom: $row['range_from'] ?? null,
                        rangeTo: $row['range_to'] ?? null,
                        prefix: $row['prefix'] ?? null,
                        gateway: null,
                        description: $row['description'] ?? null,
                    ));
                }
            } catch (Throwable) {
                // IPv6 ranges not available
            }
        }

        return $ranges;
    }

    /**
     * @return Collection<int, array{address: string, mac: string, hostname: string, ends: string, status: string}>
     */
    protected function fetchLeases(): Collection
    {
        $response = $this->client->post($this->leasesPath, [
            'json' => (object) [],
        ]);

        /** @var array{rows?: list<array{address: string, mac: string, hostname: string, ends: string, status: string}>} $data */
        $data = json_decode($response->getBody()->getContents(), true);

        return collect($data['rows'] ?? []);
    }
}
