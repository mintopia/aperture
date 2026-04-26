<?php

declare(strict_types=1);

namespace App\Jobs;

use App\Models\AuditLog;
use App\Models\DhcpLease;
use App\Models\IpAddress;
use App\Models\MacAddress;
use App\Models\Setting;
use App\Models\SwitchPortMac;
use App\Services\Interfaces\DhcpInterface;
use App\Services\Interfaces\IpMacResolverInterface;
use App\Services\Interfaces\PortMacInterface;
use App\Services\NetworkRangeService;
use App\Services\ValueObjects\ArpEntry;
use App\Services\ValueObjects\ForwardingEntry;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Log;
use Throwable;

class ScanNetworkDevices implements ShouldQueue
{
    use Queueable;

    public int $tries = 3;

    public int $timeout = 120;

    /**
     * @return list<int>
     */
    public function backoff(): array
    {
        return [10, 30, 60];
    }

    public function handle(
        ?DhcpInterface $dhcp = null,
        ?IpMacResolverInterface $ipMac = null,
        ?PortMacInterface $portMac = null,
        ?NetworkRangeService $rangeService = null,
    ): void {
        $dhcp ??= app(DhcpInterface::class);
        $ipMac ??= app(IpMacResolverInterface::class);
        $portMac ??= app(PortMacInterface::class);
        $rangeService ??= app(NetworkRangeService::class);

        $leases = $dhcp->getLeases();
        $arpEntries = $ipMac->getArpTable();
        $forwardingEntries = $portMac->getForwardingDatabase();

        // Phase 1: Discovery
        $this->persistMacs($leases, $arpEntries, $forwardingEntries);
        $this->persistIps($leases, $arpEntries, $rangeService);
        $this->linkIpMac($leases, $arpEntries, $rangeService);
        $this->persistDhcpLeases($leases, $rangeService);
        $this->linkSwitchPortMacs($forwardingEntries);

        // Phase 2: OUI Policy
        $this->applyOuiPolicy();
    }

    /**
     * @param  Collection<int, \App\Services\ValueObjects\DhcpLease>  $leases
     * @param  Collection<int, ArpEntry>  $arpEntries
     * @param  Collection<int, ForwardingEntry>  $forwardingEntries
     */
    private function persistMacs(Collection $leases, Collection $arpEntries, Collection $forwardingEntries): void
    {
        /** @var Collection<string, string> $allMacs */
        $allMacs = collect();

        foreach ($leases as $lease) {
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

    /**
     * @param  Collection<int, \App\Services\ValueObjects\DhcpLease>  $leases
     * @param  Collection<int, ArpEntry>  $arpEntries
     */
    private function persistIps(Collection $leases, Collection $arpEntries, NetworkRangeService $rangeService): void
    {
        /** @var Collection<string, string> $allIps */
        $allIps = collect();

        foreach ($leases as $lease) {
            if ($lease->ip !== '' && ! $allIps->has($lease->ip)) {
                $allIps->put($lease->ip, 'dhcp');
            }
        }

        foreach ($arpEntries as $arp) {
            if ($arp->ip !== '' && ! $allIps->has($arp->ip)) {
                $allIps->put($arp->ip, 'arp');
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

    /**
     * @param  Collection<int, \App\Services\ValueObjects\DhcpLease>  $leases
     * @param  Collection<int, ArpEntry>  $arpEntries
     */
    private function linkIpMac(Collection $leases, Collection $arpEntries, NetworkRangeService $rangeService): void
    {
        /** @var list<array{ip: string, mac: string, source: string}> $pairs */
        $pairs = [];

        foreach ($leases as $lease) {
            $normalized = MacAddress::normalize($lease->mac);
            if ($lease->ip !== '' && $normalized !== '') {
                $pairs[] = ['ip' => $lease->ip, 'mac' => $normalized, 'source' => 'dhcp'];
            }
        }

        foreach ($arpEntries as $arp) {
            $normalized = MacAddress::normalize($arp->mac);
            if ($arp->ip !== '' && $normalized !== '') {
                $pairs[] = ['ip' => $arp->ip, 'mac' => $normalized, 'source' => 'arp'];
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
        }
    }

    /**
     * @param  Collection<int, \App\Services\ValueObjects\DhcpLease>  $leases
     */
    private function persistDhcpLeases(Collection $leases, NetworkRangeService $rangeService): void
    {
        $ips = IpAddress::whereIn('address', $leases->map(fn ($l): string => $l->ip))->get()->keyBy('address');
        $macs = MacAddress::whereIn('mac_address', $leases->map(fn ($l): string => MacAddress::normalize($l->mac)))->get()->keyBy('mac_address');

        foreach ($leases as $lease) {
            if (! $rangeService->isManaged($lease->ip)) {
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

    /**
     * @param  Collection<int, ForwardingEntry>  $forwardingEntries
     */
    private function linkSwitchPortMacs(Collection $forwardingEntries): void
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

    private function applyOuiPolicy(): void
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

    public function failed(Throwable $exception): void
    {
        Log::error('ScanNetworkDevices failed', [
            'error' => $exception->getMessage(),
        ]);
    }
}
