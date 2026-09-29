<?php

declare(strict_types=1);

namespace App\Services\Kea;

use App\Services\Interfaces\DhcpInterface;
use App\Services\Interfaces\IpMacResolverInterface;
use App\Services\ValueObjects\DhcpLease;
use App\Services\ValueObjects\IpMacEntry;
use Illuminate\Support\Collection;

class KeaIpMacResolver implements IpMacResolverInterface
{
    public function __construct(private readonly ?DhcpInterface $kea = null) {}

    /** @return Collection<int, IpMacEntry> */
    public function getIpMacTable(): Collection
    {
        if (! $this->kea instanceof DhcpInterface) {
            return collect();
        }

        return $this->kea->snapshot()->leases
            ->filter(fn (DhcpLease $lease): bool => $lease->mac !== null)
            ->map(fn (DhcpLease $lease): IpMacEntry => new IpMacEntry(ip: $lease->ip, mac: (string) $lease->mac))
            ->unique(fn (IpMacEntry $entry): string => $entry->ip.'|'.$entry->mac)
            ->values();
    }
}
