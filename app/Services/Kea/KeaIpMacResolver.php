<?php

declare(strict_types=1);

namespace App\Services\Kea;

use App\Enums\Integration;
use App\Models\DhcpLease;
use App\Services\Interfaces\IpMacResolverInterface;
use App\Services\ValueObjects\IpMacEntry;
use Illuminate\Support\Collection;

class KeaIpMacResolver implements IpMacResolverInterface
{
    /** @return Collection<int, IpMacEntry> */
    public function getIpMacTable(): Collection
    {
        return DhcpLease::query()
            ->where('integration', Integration::Kea->value)
            ->whereNotNull('mac_address_id')
            ->where(function ($query): void {
                $query->whereNull('expires_at')
                    ->orWhere('expires_at', '>', now());
            })
            ->with(['ipAddress', 'macAddress'])
            ->get()
            ->map(fn (DhcpLease $lease): IpMacEntry => new IpMacEntry(
                ip: $lease->ipAddress->address ?? '',
                mac: $lease->macAddress->mac_address ?? '',
            ))
            ->unique(fn (IpMacEntry $entry): string => $entry->ip.'|'.$entry->mac)
            ->values();
    }
}
