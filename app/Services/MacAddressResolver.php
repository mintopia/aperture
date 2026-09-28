<?php

declare(strict_types=1);

namespace App\Services;

use App\Models\MacAddress;
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
            return MacAddress::normalize($lease->mac);
        }

        $arpEntry = $this->ipMac->getArpTable()->firstWhere('ip', $ipAddress);
        if ($arpEntry !== null && ! empty($arpEntry->mac)) {
            return MacAddress::normalize($arpEntry->mac);
        }

        return null;
    }

    /** @return array<int, array{ip: string, hostname: string}> */
    public function resolveMacToIps(string $macAddress): array
    {
        $normalized = MacAddress::normalize($macAddress);

        return $this->dhcp->getLeases()
            ->filter(fn (DhcpLease $lease): bool => $lease->mac !== null && MacAddress::normalize($lease->mac) === $normalized)
            ->map(fn (DhcpLease $lease): array => [
                'ip' => $lease->ip,
                'hostname' => $lease->hostname,
            ])
            ->values()
            ->all();
    }
}
