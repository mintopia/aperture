<?php

declare(strict_types=1);

namespace App\Services;

use App\Models\MacAddress;
use App\Services\Interfaces\DhcpInterface;
use App\Services\Interfaces\IpMacResolverInterface;
use App\Services\Interfaces\MacAddressResolverInterface;
use App\Services\NetworkScan\DhcpSnoopingResolver;
use App\Services\ValueObjects\DhcpLease;

class MacAddressResolver implements MacAddressResolverInterface
{
    public function __construct(
        protected DhcpInterface $dhcp,
        protected IpMacResolverInterface $ipMac,
        protected ?DhcpSnoopingResolver $snooping = null,
    ) {}

    public function resolveIpToMac(string $ipAddress): ?string
    {
        $lease = $this->dhcp->getLease($ipAddress);
        if ($lease instanceof DhcpLease && $lease->mac !== null && ($lease->mac !== '' && $lease->mac !== '0')) {
            return MacAddress::normalize($lease->mac);
        }

        $table = $this->ipMac->getIpMacTable();
        $table = $this->snooping instanceof DhcpSnoopingResolver ? $this->snooping->supplement($table) : $table;
        $entry = $table->firstWhere('ip', $ipAddress);
        if ($entry !== null && ! empty($entry->mac)) {
            return MacAddress::normalize($entry->mac);
        }

        return null;
    }
}
