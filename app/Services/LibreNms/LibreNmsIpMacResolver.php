<?php

declare(strict_types=1);

namespace App\Services\LibreNms;

use App\Services\Interfaces\IpMacResolverInterface;
use App\Services\LibreNmsService;
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

        return $arp->concat($ipv6)->unique(fn ($entry) => $entry->ip.'|'.$entry->mac)->values();
    }
}
