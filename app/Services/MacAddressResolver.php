<?php

declare(strict_types=1);

namespace App\Services;

use App\Services\Interfaces\DhcpInterface;
use App\Services\Interfaces\MacAddressResolverInterface;
use App\Services\Interfaces\NetworkInventoryInterface;

class MacAddressResolver implements MacAddressResolverInterface
{
    public function __construct(
        protected DhcpInterface $dhcp,
        protected NetworkInventoryInterface $inventory,
    ) {}

    public function resolveIpToMac(string $ipAddress): ?string
    {
        $lease = $this->dhcp->getLease($ipAddress);
        if ($lease !== null && ! empty($lease['mac'])) {
            return $this->normalizeMac($lease['mac']);
        }

        $arpEntry = $this->inventory->getArpTable()->firstWhere('ip', $ipAddress);
        if ($arpEntry !== null && ! empty($arpEntry['mac'])) {
            return $this->normalizeMac($arpEntry['mac']);
        }

        return null;
    }

    private function normalizeMac(string $mac): string
    {
        $hex = strtoupper(preg_replace('/[^0-9A-Fa-f]/', '', $mac) ?? '');

        return implode(':', str_split($hex, 2));
    }
}
