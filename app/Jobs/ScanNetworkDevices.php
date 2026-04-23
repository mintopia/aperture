<?php

declare(strict_types=1);

namespace App\Jobs;

use App\Models\IntegrationConfig;
use App\Models\IpAddress;
use App\Models\MacAddress;
use App\Services\Interfaces\DhcpInterface;
use App\Services\Interfaces\MacAddressResolverInterface;
use App\Services\Interfaces\NetworkInventoryInterface;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;

class ScanNetworkDevices implements ShouldQueue
{
    use Queueable;

    public int $tries = 1;

    public function handle(): void
    {
        $dbConfig = IntegrationConfig::getAll('auto_allow');
        $enabled = (bool) ($dbConfig['enabled'] ?? false);
        if (! $enabled) {
            return;
        }

        $resolver = app(MacAddressResolverInterface::class);
        $dhcp = app(DhcpInterface::class);
        $inventory = app(NetworkInventoryInterface::class);

        // Step 1: Resolve MACs for unlinked IPs
        $unlinkedIps = IpAddress::whereNull('mac_address_id')->get();
        foreach ($unlinkedIps as $ip) {
            $mac = $resolver->resolveIpToMac($ip->address);
            if ($mac !== null) {
                $macAddress = MacAddress::firstOrCreate(
                    ['mac_address' => $mac],
                    ['source' => 'auth'],
                );
                $ip->mac_address_id = (int) $macAddress->id;
                $ip->save();
            }
        }

        // Collect all network entries (DHCP leases + ARP)
        $entries = collect();
        foreach ($dhcp->getLeases() as $lease) {
            $entries->push(['ip' => $lease->ip, 'mac' => $lease->mac]);
        }

        foreach ($inventory->getArpTable() as $arp) {
            $entries->push(['ip' => $arp->ip, 'mac' => $arp->mac]);
        }

        // Deduplicate by IP
        $entries = $entries->unique('ip');

        /** @var array<int, string> $ouiPrefixes */
        $ouiPrefixes = [];
        $ouiPrefixesRaw = $dbConfig['oui_prefixes'] ?? null;
        if (is_string($ouiPrefixesRaw) && $ouiPrefixesRaw !== '') {
            $ouiPrefixes = array_filter(array_map('trim', explode(',', $ouiPrefixesRaw)));
        }

        // Batch-load all MacAddress records to avoid N+1 queries in the loop below
        $normalizedMacs = $entries->map(fn (array $entry): string => $this->normalizeMac($entry['mac']))->values()->all();
        $existingMacs = MacAddress::whereIn('mac_address', $normalizedMacs)->get()->keyBy('mac_address');

        foreach ($entries as $entry) {
            $normalizedMac = $this->normalizeMac($entry['mac']);

            // Step 2: Auto-allow IPs for known allowed MACs
            $existingMac = $existingMacs->get($normalizedMac);
            if ($existingMac && $existingMac->allowed) {
                $this->autoAllowIp($entry['ip'], $existingMac);

                continue;
            }

            // Step 3: Xbox/console OUI detection
            if ($existingMac === null) {
                $prefix = strtoupper(substr($normalizedMac, 0, 8));
                $matchedOui = false;
                foreach ($ouiPrefixes as $ouiPrefix) {
                    if (strtoupper($ouiPrefix) === $prefix) {
                        $matchedOui = true;

                        break;
                    }
                }

                if ($matchedOui) {
                    $macAddress = MacAddress::firstOrCreate(
                        ['mac_address' => $normalizedMac],
                        [
                            'source' => 'xbox',
                            'allowed' => true,
                            'allowed_at' => now(),
                            'description' => 'Xbox Console',
                        ],
                    );
                    $existingMacs->put($normalizedMac, $macAddress);
                    $this->autoAllowIp($entry['ip'], $macAddress);
                }
            }
        }
    }

    private function autoAllowIp(string $ipAddress, MacAddress $macAddress): void
    {
        if ($macAddress->user_id !== null && $macAddress->user) {
            $ip = $macAddress->user->addIp($ipAddress);
            if ($ip === null) {
                return;
            }
        } else {
            $ip = IpAddress::where('address', $ipAddress)->first();
            if ($ip === null) {
                $ip = new IpAddress;
                $ip->address = $ipAddress;
                $ip->last_seen_at = now();
                $ip->save();
            }
        }

        $ip->mac_address_id = (int) $macAddress->id;
        $ip->save();

        if (! $ip->internet_enabled) {
            $ip->internet_enabled = true;
            $ip->save();
        }
    }

    private function normalizeMac(string $mac): string
    {
        $hex = strtoupper(preg_replace('/[^0-9A-Fa-f]/', '', $mac) ?? '');

        return implode(':', str_split($hex, 2));
    }
}
