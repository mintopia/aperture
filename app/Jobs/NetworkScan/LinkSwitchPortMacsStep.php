<?php

declare(strict_types=1);

namespace App\Jobs\NetworkScan;

use App\Models\AuditLog;
use App\Models\MacAddress;
use App\Models\SwitchPortMac;
use App\Services\ValueObjects\ForwardingEntry;
use Illuminate\Support\Collection;

final class LinkSwitchPortMacsStep
{
    /**
     * @param  Collection<int, ForwardingEntry>  $forwardingEntries
     */
    public function __invoke(Collection $forwardingEntries): void
    {
        /** @var Collection<string, ForwardingEntry> $normalizedFwdMacs */
        $normalizedFwdMacs = $forwardingEntries->mapWithKeys(fn ($fwd): array => [MacAddress::normalize($fwd->mac) => $fwd]);
        $macRecords = MacAddress::whereIn('mac_address', $normalizedFwdMacs->keys())->get()->keyBy('mac_address');

        SwitchPortMac::whereIn('mac_address', $normalizedFwdMacs->keys())
            ->whereNull('mac_address_id')
            ->each(function (SwitchPortMac $spm) use ($macRecords): void {
                $macRecord = $macRecords->get($spm->mac_address);

                if ($macRecord !== null) {
                    $spm->mac_address_id = $macRecord->id;
                    $spm->save();

                    AuditLog::record(
                        action: 'port_mac.linked',
                        subject: $spm,
                        process: 'scan_network',
                        metadata: ['mac' => $spm->mac_address],
                    );
                }
            });
    }
}
