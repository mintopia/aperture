<?php

declare(strict_types=1);

namespace App\Services\LibreNms;

use App\Models\IpAddress;
use App\Services\Interfaces\IpMacResolverInterface;
use App\Services\ValueObjects\IpMacEntry;
use Illuminate\Support\Collection;

class LibreNmsIpMacResolver implements IpMacResolverInterface
{
    public function __construct(
        protected LibreNmsService $libreNms,
    ) {}

    public function getIpMacTable(): Collection
    {
        $arp = $this->libreNms->getIpMacTable();
        $ipv6 = $this->libreNms->getIpv6Neighbors();

        return $arp->concat($ipv6)
            ->map(fn (IpMacEntry $entry): IpMacEntry => new IpMacEntry(
                ip: IpAddress::normalize($entry->ip),
                mac: $entry->mac,
            ))
            ->unique(fn (IpMacEntry $entry): string => $entry->ip.'|'.$entry->mac)
            ->values();
    }
}
