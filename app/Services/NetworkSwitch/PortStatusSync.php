<?php

declare(strict_types=1);

namespace App\Services\NetworkSwitch;

use App\Models\SwitchConfig;
use App\Models\SwitchPort;
use App\Services\ValueObjects\PortStatus;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;

class PortStatusSync
{
    /**
     * Upsert pre-fetched port statuses into the database.
     *
     * Pre-loads all existing ports in one query (avoids N+1) and matches
     * against the provided $portStatuses collection.
     *
     * @param  Collection<int, PortStatus>  $portStatuses
     * @return array{created: int, updated: int, stateChanges: array<int, array{switchPort: SwitchPort, oldStatus: ?string, newStatus: string}>}
     */
    public function sync(Collection $portStatuses, SwitchConfig $switchConfig, Carbon $syncStartedAt): array
    {
        // Pre-load all existing ports keyed by port_name to avoid N+1 queries.
        $existingPorts = SwitchPort::where('switch_config_id', $switchConfig->id)
            ->get()
            ->keyBy('port_name');

        $created = 0;
        $updated = 0;

        /** @var array<int, array{switchPort: SwitchPort, oldStatus: ?string, newStatus: string}> $stateChanges */
        $stateChanges = [];

        foreach ($portStatuses as $portStatus) {
            $accessVlan = $portStatus->vlan !== '' ? (int) $portStatus->vlan : null;
            $switchportMode = $portStatus->switchportMode !== '' ? $portStatus->switchportMode : null;
            $description = $portStatus->description !== '' ? $portStatus->description : null;

            /** @var SwitchPort|null $existingPort */
            $existingPort = $existingPorts->get($portStatus->interface);

            if ($existingPort instanceof SwitchPort) {
                $oldStatus = $existingPort->status;

                $existingPort->update([
                    'status' => $portStatus->status,
                    'admin_status' => $portStatus->adminStatus,
                    'speed' => $portStatus->speed,
                    'duplex' => $portStatus->duplex,
                    'access_vlan' => $accessVlan,
                    'switchport_mode' => $switchportMode,
                    'switch_description' => $description,
                    'last_synced_at' => $syncStartedAt,
                ]);
                $updated++;

                if ($oldStatus !== $portStatus->status) {
                    $stateChanges[] = [
                        'switchPort' => $existingPort,
                        'oldStatus' => $oldStatus,
                        'newStatus' => $portStatus->status,
                    ];
                }
            } else {
                SwitchPort::create([
                    'switch_config_id' => $switchConfig->id,
                    'port_name' => $portStatus->interface,
                    'port_number' => $portStatus->interface,
                    'status' => $portStatus->status,
                    'admin_status' => $portStatus->adminStatus,
                    'speed' => $portStatus->speed,
                    'duplex' => $portStatus->duplex,
                    'access_vlan' => $accessVlan,
                    'switchport_mode' => $switchportMode,
                    'switch_description' => $description,
                    'last_synced_at' => $syncStartedAt,
                ]);
                $created++;
            }
        }

        return compact('created', 'updated', 'stateChanges');
    }
}
