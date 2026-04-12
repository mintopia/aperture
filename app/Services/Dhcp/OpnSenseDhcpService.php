<?php

declare(strict_types=1);

namespace App\Services\Dhcp;

use App\Services\Interfaces\DhcpInterface;
use GuzzleHttp\Client;
use Illuminate\Support\Collection;

class OpnSenseDhcpService implements DhcpInterface
{
    public function __construct(
        protected Client $client
    ) {}

    /**
     * @return array{total: int, used: int, available: int, utilisation: float}
     */
    public function getPoolStatus(): array
    {
        $leases = $this->fetchLeases();
        $activeCount = $leases->where('status', 'active')->count();
        $poolSize = (int) config('aperture.dhcp.pool_size', 0);

        return [
            'total' => $poolSize,
            'used' => $activeCount,
            'available' => max(0, $poolSize - $activeCount),
            'utilisation' => $poolSize > 0 ? round($activeCount / $poolSize, 4) : 0.0,
        ];
    }

    /**
     * @return Collection<int, array{ip: string, mac: string, hostname: string, expires: string}>
     */
    public function getLeases(): Collection
    {
        return $this->fetchLeases()->map(fn (array $row): array => [
            'ip' => $row['address'],
            'mac' => $row['mac'],
            'hostname' => $row['hostname'],
            'expires' => $row['ends'],
        ])->values();
    }

    /**
     * @return array{ip: string, mac: string, hostname: string, expires: string}|null
     */
    public function getLease(string $ipAddress): ?array
    {
        $leases = $this->fetchLeases();
        $match = $leases->firstWhere('address', $ipAddress);

        if ($match === null) {
            return null;
        }

        return [
            'ip' => $match['address'],
            'mac' => $match['mac'],
            'hostname' => $match['hostname'],
            'expires' => $match['ends'],
        ];
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
