<?php

declare(strict_types=1);

namespace App\Jobs\NetworkScan;

use App\Models\AuditLog;
use App\Models\MacAddress;
use App\Models\Setting;
use Illuminate\Support\Collection;

final class ApplyOuiPolicyStep
{
    public function __invoke(): void
    {
        $raw = Setting::get('network.oui_auto_allow');

        if ($raw === null) {
            return;
        }

        $prefixes = is_string($raw) ? json_decode($raw, true) : $raw;

        if (! is_array($prefixes) || $prefixes === []) {
            return;
        }

        /** @var list<string> $prefixes */
        $prefixes = array_map('strtoupper', $prefixes);

        MacAddress::query()
            ->with('ipAddresses')
            ->where(function ($query) use ($prefixes): void {
                foreach ($prefixes as $prefix) {
                    $query->orWhere('mac_address', 'like', $prefix.'%');
                }
            })
            ->chunkById(200, function (Collection $macs): void {
                /** @var Collection<int, MacAddress> $macs */
                foreach ($macs as $mac) {
                    foreach ($mac->ipAddresses as $ip) {
                        if (! $ip->internet_enabled) {
                            $ip->internet_enabled = true;
                            $ip->save();

                            AuditLog::record(
                                action: 'oui.auto_allowed',
                                subject: $ip,
                                related: $mac,
                                process: 'oui_policy',
                                metadata: ['mac' => $mac->mac_address],
                            );
                        }
                    }
                }
            });
    }
}
