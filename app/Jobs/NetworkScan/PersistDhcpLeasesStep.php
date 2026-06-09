<?php

declare(strict_types=1);

namespace App\Jobs\NetworkScan;

use App\Models\DhcpLease;
use App\Models\IpAddress;
use App\Models\MacAddress;
use App\Services\NetworkRangeService;
use App\Services\ValueObjects\DhcpLease as DhcpLeaseVO;
use Illuminate\Support\Collection;

final class PersistDhcpLeasesStep
{
    /**
     * @param  Collection<int, DhcpLeaseVO>  $leases
     */
    public function __invoke(Collection $leases, NetworkRangeService $rangeService): void
    {
        $ips = IpAddress::whereIn('address', $leases->map(fn ($l): string => $l->ip))->get()->keyBy('address');
        $macs = MacAddress::whereIn('mac_address', $leases->filter(fn ($l): bool => $l->mac !== null)->map(fn ($l): string => MacAddress::normalize((string) $l->mac)))->get()->keyBy('mac_address');

        foreach ($leases as $lease) {
            if (! $rangeService->isManaged($lease->ip)) {
                continue;
            }

            if ($lease->mac === null) {
                continue;
            }

            $ip = $ips->get($lease->ip);
            $mac = $macs->get(MacAddress::normalize($lease->mac));

            if ($ip === null || $mac === null) {
                continue;
            }

            DhcpLease::updateOrCreate(
                ['ip_address_id' => $ip->id, 'mac_address_id' => $mac->id],
                [
                    'hostname' => $lease->hostname !== '' ? $lease->hostname : null,
                    'expires_at' => $lease->expires !== '' ? $lease->expires : null,
                ],
            );
        }
    }
}
