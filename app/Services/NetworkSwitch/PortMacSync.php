<?php

declare(strict_types=1);

namespace App\Services\NetworkSwitch;

use App\Models\MacAddress;
use App\Models\SwitchConfig;
use App\Models\SwitchPort;
use App\Models\SwitchPortMac;
use App\Services\ValueObjects\ForwardingEntry;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;

class PortMacSync
{
    /**
     * Upsert pre-fetched MAC address forwarding table entries into the database.
     *
     * @param  Collection<int, ForwardingEntry>  $macEntries
     * @return array{created: int, updated: int, syncedMacIds: list<int>}
     */
    public function sync(Collection $macEntries, SwitchConfig $switchConfig, Carbon $syncStartedAt): array
    {
        $switchPorts = SwitchPort::where('switch_config_id', $switchConfig->id)
            ->get()
            ->keyBy('port_name');

        $created = 0;
        $updated = 0;

        /** @var list<int> $syncedMacIds */
        $syncedMacIds = [];

        foreach ($macEntries as $entry) {
            /** @var SwitchPort|null $port */
            $port = $switchPorts->get($entry->port);

            if (! $port instanceof SwitchPort) {
                continue;
            }

            if ($port->switchport_mode === 'trunk') {
                continue;
            }

            $normalizedMac = MacAddress::normalize($entry->mac);

            $macRecord = MacAddress::firstOrCreate(
                ['mac_address' => $normalizedMac],
                ['source' => 'switch'],
            );

            $existingMac = SwitchPortMac::where('switch_port_id', $port->id)
                ->where('mac_address', $normalizedMac)
                ->where('vlan', $entry->vlan)
                ->first();

            if ($existingMac instanceof SwitchPortMac) {
                $existingMac->update([
                    'last_seen_at' => $syncStartedAt,
                    'mac_address_id' => $macRecord->id,
                ]);
                $syncedMacIds[] = (int) $existingMac->id;
                $updated++;
            } else {
                $newMac = SwitchPortMac::create([
                    'switch_port_id' => $port->id,
                    'mac_address' => $normalizedMac,
                    'mac_address_id' => $macRecord->id,
                    'vlan' => $entry->vlan,
                    'last_seen_at' => $syncStartedAt,
                ]);
                $syncedMacIds[] = (int) $newMac->id;
                $created++;
            }
        }

        return ['created' => $created, 'updated' => $updated, 'syncedMacIds' => $syncedMacIds];
    }

    /**
     * Remove MAC address entries for this switch that were not seen in the current sync run.
     *
     * @param  list<int>  $syncedMacIds  IDs of SwitchPortMac records synced in this run.
     */
    public function cleanStaleMacs(SwitchConfig $switchConfig, array $syncedMacIds): void
    {
        $portIds = SwitchPort::where('switch_config_id', $switchConfig->id)
            ->pluck('id');

        $staleQuery = SwitchPortMac::whereIn('switch_port_id', $portIds);

        if ($syncedMacIds !== []) {
            $staleQuery->whereNotIn('id', $syncedMacIds);
        }

        $staleQuery->delete();
    }
}
