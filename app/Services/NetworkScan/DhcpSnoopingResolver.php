<?php

declare(strict_types=1);

namespace App\Services\NetworkScan;

use App\Models\DhcpSnoopingObservation;
use App\Services\ValueObjects\ArpEntry;
use Illuminate\Support\Collection;

class DhcpSnoopingResolver
{
    /** @return Collection<int, ArpEntry> */
    public function getObservedMappings(): Collection
    {
        return DhcpSnoopingObservation::query()
            ->where(function ($query): void {
                $query->whereNull('expires_at')
                    ->orWhere('expires_at', '>', now());
            })
            ->get()
            ->map(fn (DhcpSnoopingObservation $obs): ArpEntry => new ArpEntry(
                ip: $obs->ip,
                mac: $obs->mac,
            ))
            ->unique(fn (ArpEntry $entry): string => $entry->ip.'|'.$entry->mac)
            ->values();
    }
}
