<?php

declare(strict_types=1);

namespace App\Services;

use App\Services\Interfaces\DhcpInterface;
use App\Services\Interfaces\MacAddressResolverInterface;
use App\Services\Interfaces\NetworkInventoryInterface;
use App\Services\ValueObjects\DhcpLease;

class MacAddressResolver implements MacAddressResolverInterface
{
    public function __construct(
        protected DhcpInterface $dhcp,
        protected NetworkInventoryInterface $inventory,
    ) {}

    public function resolveIpToMac(string $ipAddress): ?string
    {
        $lease = $this->dhcp->getLease($ipAddress);
        if ($lease instanceof DhcpLease && ($lease->mac !== '' && $lease->mac !== '0')) {
            return $this->normalizeMac($lease->mac);
        }

        $arpEntry = $this->inventory->getArpTable()->firstWhere('ip', $ipAddress);
        if ($arpEntry !== null && ! empty($arpEntry->mac)) {
            return $this->normalizeMac($arpEntry->mac);
        }

        return null;
    }

    /** @return array<int, array{ip: string, hostname: string}> */
    public function resolveMacToIps(string $macAddress): array
    {
        $normalized = $this->normalizeMac($macAddress);

        return $this->dhcp->getLeases()
            ->filter(fn (DhcpLease $lease) => $this->normalizeMac($lease->mac) === $normalized)
            ->map(fn (DhcpLease $lease) => [
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
