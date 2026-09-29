<?php

declare(strict_types=1);

namespace App\Services\NetworkScan;

use App\Models\DhcpSnoopingObservation;
use App\Services\ValueObjects\IpMacEntry;
use Illuminate\Support\Collection;

class DhcpSnoopingResolver
{
    /** @return Collection<int, IpMacEntry> */
    public function getObservedMappings(): Collection
    {
        return DhcpSnoopingObservation::query()
            ->where(function ($query): void {
                $query->whereNull('expires_at')
                    ->orWhere('expires_at', '>', now());
            })
            ->get()
            ->map(fn (DhcpSnoopingObservation $obs): IpMacEntry => new IpMacEntry(
                ip: $obs->ip,
                mac: $obs->mac,
            ))
            ->unique(fn (IpMacEntry $entry): string => $entry->ip.'|'.$entry->mac)
            ->values();
    }

    /**
     * @param  Collection<int, IpMacEntry>  $primary
     * @return Collection<int, IpMacEntry>
     */
    public function supplement(Collection $primary): Collection
    {
        $known = $primary->pluck('ip')->flip();

        return $primary
            ->concat($this->getObservedMappings()->reject(fn (IpMacEntry $entry): bool => $known->has($entry->ip)))
            ->unique(fn (IpMacEntry $entry): string => $entry->ip.'|'.$entry->mac)
            ->values();
    }
}
