<?php

declare(strict_types=1);

namespace App\Services\Kea;

use App\Enums\Integration;
use App\Models\DhcpLease;
use App\Services\Interfaces\IpMacResolverInterface;
use App\Services\ValueObjects\ArpEntry;
use Illuminate\Support\Collection;

class KeaIpMacResolver implements IpMacResolverInterface
{
    /** @return Collection<int, ArpEntry> */
    public function getArpTable(): Collection
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
            ->map(fn (DhcpLease $lease): ArpEntry => new ArpEntry(
                ip: $lease->ipAddress->address ?? '',
                mac: $lease->macAddress->mac_address ?? '',
            ))
            ->unique(fn (ArpEntry $entry): string => $entry->ip.'|'.$entry->mac)
            ->values();
    }
}
