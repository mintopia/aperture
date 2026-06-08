<?php

declare(strict_types=1);

namespace App\Jobs\NetworkScan;

use App\Models\AuditLog;
use App\Models\MacAddress;
use App\Services\ValueObjects\ArpEntry;
use App\Services\ValueObjects\DhcpLease;
use App\Services\ValueObjects\ForwardingEntry;
use Illuminate\Support\Collection;

final class PersistMacsStep
{
    /**
     * @param  Collection<int, DhcpLease>  $leases
     * @param  Collection<int, ArpEntry>  $arpEntries
     * @param  Collection<int, ForwardingEntry>  $forwardingEntries
     */
    public function __invoke(Collection $leases, Collection $arpEntries, Collection $forwardingEntries): void
    {
        /** @var Collection<string, string> $allMacs */
        $allMacs = collect();

        foreach ($leases as $lease) {
            if ($lease->mac === null) {
                continue;
            }

            $normalized = MacAddress::normalize($lease->mac);
            if ($normalized !== '') {
                $allMacs->put($normalized, 'dhcp');
            }
        }

        foreach ($arpEntries as $arp) {
            $normalized = MacAddress::normalize($arp->mac);
            if ($normalized !== '' && ! $allMacs->has($normalized)) {
                $allMacs->put($normalized, 'arp');
            }
        }

        foreach ($forwardingEntries as $fwd) {
            $normalized = MacAddress::normalize($fwd->mac);
            if ($normalized !== '' && ! $allMacs->has($normalized)) {
                $allMacs->put($normalized, 'switch');
            }
        }

        $existingMacs = MacAddress::whereIn('mac_address', $allMacs->keys())->pluck('id', 'mac_address');

        foreach ($allMacs as $mac => $source) {
            if (! $existingMacs->has($mac)) {
                $record = MacAddress::create([
                    'mac_address' => $mac,
                    'source' => $source,
                ]);
                $existingMacs->put($mac, $record->id);

                AuditLog::record(
                    action: 'mac.created',
                    subject: $record,
                    process: 'scan_network',
                    metadata: ['source' => $source],
                );
            }
        }
    }
}
