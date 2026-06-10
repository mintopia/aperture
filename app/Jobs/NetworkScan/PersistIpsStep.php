<?php

declare(strict_types=1);

namespace App\Jobs\NetworkScan;

use App\Models\AuditLog;
use App\Models\IpAddress;
use App\Services\NetworkRangeService;
use App\Services\ValueObjects\ArpEntry;
use App\Services\ValueObjects\DhcpLease;
use Illuminate\Support\Collection;

final class PersistIpsStep
{
    /**
     * @param  Collection<int, DhcpLease>  $leases
     * @param  Collection<int, ArpEntry>  $arpEntries
     */
    public function __invoke(Collection $leases, Collection $arpEntries, NetworkRangeService $rangeService): void
    {
        /** @var Collection<string, string> $allIps */
        $allIps = collect();

        foreach ($leases as $lease) {
            $address = IpAddress::normalize($lease->ip);
            if ($address !== '' && ! $allIps->has($address)) {
                $allIps->put($address, 'dhcp');
            }
        }

        foreach ($arpEntries as $arp) {
            $address = IpAddress::normalize($arp->ip);
            if ($address !== '' && ! $allIps->has($address)) {
                $allIps->put($address, 'arp');
            }
        }

        $existingIps = IpAddress::whereIn('address', $allIps->keys())->get()->keyBy('address');

        foreach ($allIps as $ipAddress => $source) {
            if (! $rangeService->isManaged($ipAddress)) {
                continue;
            }

            $existing = $existingIps->get($ipAddress);

            if ($existing !== null) {
                $existing->last_seen_at = now();
                $existing->save();
            } else {
                $ip = new IpAddress;
                $ip->address = $ipAddress;
                $ip->last_seen_at = now();
                $ip->save();

                AuditLog::record(
                    action: 'ip.created',
                    subject: $ip,
                    process: 'scan_network',
                    metadata: ['source' => $source],
                );
            }
        }
    }
}
