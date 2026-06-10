<?php

declare(strict_types=1);

namespace App\Jobs\NetworkScan;

use App\Events\IpMacLinked;
use App\Models\AuditLog;
use App\Models\IpAddress;
use App\Models\MacAddress;
use App\Services\NetworkRangeService;
use App\Services\ValueObjects\ArpEntry;
use App\Services\ValueObjects\DhcpLease;
use Illuminate\Support\Collection;

final class LinkIpMacStep
{
    /**
     * @param  Collection<int, DhcpLease>  $leases
     * @param  Collection<int, ArpEntry>  $arpEntries
     */
    public function __invoke(Collection $leases, Collection $arpEntries, NetworkRangeService $rangeService): void
    {
        /** @var list<array{ip: string, mac: string, source: string}> $pairs */
        $pairs = [];

        foreach ($leases as $lease) {
            if ($lease->mac === null) {
                continue;
            }

            $normalized = MacAddress::normalize($lease->mac);
            $address = IpAddress::normalize($lease->ip);
            if ($address !== '' && $normalized !== '') {
                $pairs[] = ['ip' => $address, 'mac' => $normalized, 'source' => 'dhcp'];
            }
        }

        foreach ($arpEntries as $arp) {
            $normalized = MacAddress::normalize($arp->mac);
            $address = IpAddress::normalize($arp->ip);
            if ($address !== '' && $normalized !== '') {
                $pairs[] = ['ip' => $address, 'mac' => $normalized, 'source' => 'arp'];
            }
        }

        $pairsCollection = collect($pairs);
        $ips = IpAddress::whereIn('address', $pairsCollection->pluck('ip')->unique())->get()->keyBy('address');
        $macs = MacAddress::whereIn('mac_address', $pairsCollection->pluck('mac')->unique())->get()->keyBy('mac_address');

        foreach ($pairs as $pair) {
            if (! $rangeService->isManaged($pair['ip'])) {
                continue;
            }

            $ip = $ips->get($pair['ip']);
            $mac = $macs->get($pair['mac']);

            if ($ip === null || $mac === null) {
                continue;
            }

            $existing = $ip->macAddresses()->where('mac_addresses.id', $mac->id)->first();

            if ($existing !== null) {
                $ip->macAddresses()->updateExistingPivot($mac->id, [
                    'last_seen_at' => now(),
                ]);
            } else {
                $ip->macAddresses()->attach($mac, [
                    'source' => $pair['source'],
                    'last_seen_at' => now(),
                ]);

                AuditLog::record(
                    action: 'ip_mac.linked',
                    subject: $ip,
                    related: $mac,
                    process: 'scan_network',
                    metadata: ['source' => $pair['source']],
                );
            }

            // Dispatch on refresh too, so existing links that never received a
            // user association can heal on the next scan (ADR-011).
            IpMacLinked::dispatch($ip, $mac, $pair['source'], 'scan_network');
        }
    }
}
