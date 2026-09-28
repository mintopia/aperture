<?php

declare(strict_types=1);

namespace App\Services;

use App\Services\Interfaces\DhcpInterface;
use App\Services\Interfaces\IpMacResolverInterface;
use App\Services\Interfaces\MacAddressResolverInterface;
use App\Services\ValueObjects\DhcpLease;

class MacAddressResolver implements MacAddressResolverInterface
{
    public function __construct(
        protected DhcpInterface $dhcp,
        protected IpMacResolverInterface $ipMac,
    ) {}

    public function resolveIpToMac(string $ipAddress): ?string
    {
        $lease = $this->dhcp->getLease($ipAddress);
        if ($lease instanceof DhcpLease && $lease->mac !== null && ($lease->mac !== '' && $lease->mac !== '0')) {
            return $this->normalizeMac($lease->mac);
        }

        $entry = $this->ipMac->getIpMacTable()->firstWhere('ip', $ipAddress);
        if ($entry !== null && ! empty($entry->mac)) {
            return $this->normalizeMac($entry->mac);
        }

        return null;
    }

    /** @return array<int, array{ip: string, hostname: string|null}> */
    public function resolveMacToIps(string $macAddress): array
    {
        $normalized = $this->normalizeMac($macAddress);

        return $this->dhcp->snapshot()->leases
            ->filter(fn (DhcpLease $lease): bool => $lease->mac !== null && $this->normalizeMac($lease->mac) === $normalized)
            ->map(fn (DhcpLease $lease): array => [
                'ip' => $lease->ip,
                'hostname' => $lease->hostname,
            ])
            ->values()
            ->all();
    }

    private function normalizeMac(string $mac): string
    {
        $hex = strtoupper(preg_replace('/[^0-9A-Fa-f]/', '', $mac) ?? '');

        return implode(':', str_split($hex, 2));
    }
}
