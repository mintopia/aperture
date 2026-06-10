<?php

declare(strict_types=1);

namespace App\Services\LibreNms;

use App\Models\IpAddress;
use App\Services\Interfaces\IpMacResolverInterface;
use App\Services\ValueObjects\ArpEntry;
use Illuminate\Support\Collection;

class LibreNmsIpMacResolver implements IpMacResolverInterface
{
    public function __construct(
        protected LibreNmsService $libreNms,
    ) {}

    public function getArpTable(): Collection
    {
        $arp = $this->libreNms->getArpTable();
        $ipv6 = $this->libreNms->getIpv6Neighbors();

        return $arp->concat($ipv6)
            ->map(fn (ArpEntry $entry): ArpEntry => new ArpEntry(
                ip: IpAddress::normalize($entry->ip),
                mac: $entry->mac,
            ))
            ->unique(fn (ArpEntry $entry): string => $entry->ip.'|'.$entry->mac)
            ->values();
    }
}
