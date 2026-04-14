<?php

declare(strict_types=1);

namespace App\Services\Dhcp;

use App\Services\Interfaces\DhcpInterface;
use App\Services\ValueObjects\DhcpLease;
use App\Services\ValueObjects\DhcpPoolStatus;
use GuzzleHttp\Client;
use Illuminate\Support\Collection;

class OpnSenseDhcpService implements DhcpInterface
{
    public function __construct(
        protected Client $client,
        protected int $poolSize = 0,
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

    /**
     * @return Collection<int, array{address: string, mac: string, hostname: string, ends: string, status: string}>
     */
    protected function fetchLeases(): Collection
    {
        $response = $this->client->post('/api/dhcpv4/leases/searchLease', [
            'json' => (object) [],
        ]);

        /** @var array{rows?: list<array{address: string, mac: string, hostname: string, ends: string, status: string}>} $data */
        $data = json_decode($response->getBody()->getContents(), true);

        return collect($data['rows'] ?? []);
    }
}
