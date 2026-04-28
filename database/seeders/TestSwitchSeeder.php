<?php

declare(strict_types=1);

namespace Database\Seeders;

use App\Models\SwitchConfig;
use App\Models\SwitchPort;
use App\Models\SwitchPortConfig;
use App\Models\SwitchPortMac;
use Illuminate\Database\Seeder;

class TestSwitchSeeder extends Seeder
{
    public function run(): void
    {
        $switch = SwitchConfig::factory()->create([
            'name' => 'Test Switch - 48 Port',
            'hostname' => 'test-sw-48.local',
            'type' => 'cisco',
            'enabled' => true,
        ]);

        $portDefinitions = $this->portDefinitions();

        foreach ($portDefinitions as $i => $def) {
            $portNum = $i + 1;
            $port = SwitchPort::factory()->create(array_merge([
                'switch_config_id' => $switch->id,
                'port_name' => "GigabitEthernet1/0/{$portNum}",
                'port_number' => "Gi1/0/{$portNum}",
                'last_synced_at' => now()->subMinutes(fake()->numberBetween(1, 30)),
            ], $def));

            if ($port->status === 'up' && fake()->boolean(60)) {
                SwitchPortMac::factory()->create([
                    'switch_port_id' => $port->id,
                ]);
            }

            if (fake()->boolean(40)) {
                $configText = $this->generateConfigText($portNum, $def);
                SwitchPortConfig::factory()->create([
                    'switch_port_id' => $port->id,
                    'config_text' => $configText,
                    'config_hash' => md5($configText),
                    'last_fetched_at' => now()->subMinutes(fake()->numberBetween(1, 60)),
                ]);
            }
        }
    }

    /**
     * @return list<array<string, mixed>>
     */
    private function portDefinitions(): array
    {
        return [
            // Ports 1-2: Trunk uplinks (up)
            ['switchport_mode' => 'trunk', 'access_vlan' => null, 'speed' => '1000', 'status' => 'up', 'admin_status' => 'up', 'duplex' => 'full', 'poe_status' => null, 'switch_description' => 'Uplink to Core-SW1'],
            ['switchport_mode' => 'trunk', 'access_vlan' => null, 'speed' => '1000', 'status' => 'up', 'admin_status' => 'up', 'duplex' => 'full', 'poe_status' => null, 'switch_description' => 'Uplink to Core-SW2'],

            // Ports 3-10: Active access ports, various VLANs (up)
            ['switchport_mode' => 'access', 'access_vlan' => 100, 'speed' => '1000', 'status' => 'up', 'admin_status' => 'up', 'duplex' => 'full', 'poe_status' => 'on', 'switch_description' => 'Office - Desk 3A'],
            ['switchport_mode' => 'access', 'access_vlan' => 100, 'speed' => '100', 'status' => 'up', 'admin_status' => 'up', 'duplex' => 'full', 'poe_status' => 'on', 'switch_description' => 'Office - Desk 3B'],
            ['switchport_mode' => 'access', 'access_vlan' => 200, 'speed' => '1000', 'status' => 'up', 'admin_status' => 'up', 'duplex' => 'full', 'poe_status' => null, 'switch_description' => 'Server Room - Rack 1'],
            ['switchport_mode' => 'access', 'access_vlan' => 200, 'speed' => '1000', 'status' => 'up', 'admin_status' => 'up', 'duplex' => 'full', 'poe_status' => null, 'switch_description' => 'Server Room - Rack 2'],
            ['switchport_mode' => 'access', 'access_vlan' => 300, 'speed' => '1000', 'status' => 'up', 'admin_status' => 'up', 'duplex' => 'full', 'poe_status' => 'on', 'switch_description' => 'VoIP Phone - Ext 201'],
            ['switchport_mode' => 'access', 'access_vlan' => 300, 'speed' => '100', 'status' => 'up', 'admin_status' => 'up', 'duplex' => 'half', 'poe_status' => 'on', 'switch_description' => 'VoIP Phone - Ext 202'],
            ['switchport_mode' => 'access', 'access_vlan' => 100, 'speed' => '1000', 'status' => 'up', 'admin_status' => 'up', 'duplex' => 'full', 'poe_status' => 'on', 'switch_description' => 'AP - Floor 2 East'],
            ['switchport_mode' => 'access', 'access_vlan' => 400, 'speed' => '1000', 'status' => 'up', 'admin_status' => 'up', 'duplex' => 'full', 'poe_status' => null, 'switch_description' => 'CCTV - Lobby'],

            // Ports 11-16: Down (link down, admin up - nothing plugged in)
            ['switchport_mode' => 'access', 'access_vlan' => 100, 'speed' => null, 'status' => 'down', 'admin_status' => 'up', 'duplex' => null, 'poe_status' => null, 'switch_description' => 'Office - Desk 4A'],
            ['switchport_mode' => 'access', 'access_vlan' => 100, 'speed' => null, 'status' => 'down', 'admin_status' => 'up', 'duplex' => null, 'poe_status' => null, 'switch_description' => 'Office - Desk 4B'],
            ['switchport_mode' => 'access', 'access_vlan' => 100, 'speed' => null, 'status' => 'down', 'admin_status' => 'up', 'duplex' => null, 'poe_status' => null, 'switch_description' => null],
            ['switchport_mode' => 'access', 'access_vlan' => 200, 'speed' => null, 'status' => 'down', 'admin_status' => 'up', 'duplex' => null, 'poe_status' => null, 'switch_description' => 'Server Room - Spare'],
            ['switchport_mode' => 'access', 'access_vlan' => 100, 'speed' => null, 'status' => 'down', 'admin_status' => 'up', 'duplex' => null, 'poe_status' => null, 'switch_description' => null],
            ['switchport_mode' => 'access', 'access_vlan' => 300, 'speed' => null, 'status' => 'down', 'admin_status' => 'up', 'duplex' => null, 'poe_status' => null, 'switch_description' => 'VoIP - Spare'],

            // Ports 17-22: Admin shutdown
            ['switchport_mode' => 'access', 'access_vlan' => 100, 'speed' => null, 'status' => 'down', 'admin_status' => 'down', 'duplex' => null, 'poe_status' => null, 'switch_description' => 'DISABLED - Security incident'],
            ['switchport_mode' => 'access', 'access_vlan' => 100, 'speed' => null, 'status' => 'down', 'admin_status' => 'down', 'duplex' => null, 'poe_status' => null, 'switch_description' => 'DISABLED - Decommissioned'],
            ['switchport_mode' => 'access', 'access_vlan' => 100, 'speed' => null, 'status' => 'down', 'admin_status' => 'down', 'duplex' => null, 'poe_status' => null, 'switch_description' => null],
            ['switchport_mode' => 'access', 'access_vlan' => 100, 'speed' => null, 'status' => 'down', 'admin_status' => 'down', 'duplex' => null, 'poe_status' => null, 'switch_description' => 'DISABLED - Maintenance'],
            ['switchport_mode' => 'access', 'access_vlan' => 200, 'speed' => null, 'status' => 'down', 'admin_status' => 'down', 'duplex' => null, 'poe_status' => null, 'switch_description' => null],
            ['switchport_mode' => 'access', 'access_vlan' => 100, 'speed' => null, 'status' => 'down', 'admin_status' => 'down', 'duplex' => null, 'poe_status' => null, 'switch_description' => 'DISABLED - Unused'],

            // Ports 23-30: More active access ports with varied configs
            ['switchport_mode' => 'access', 'access_vlan' => 100, 'speed' => '1000', 'status' => 'up', 'admin_status' => 'up', 'duplex' => 'full', 'poe_status' => 'on', 'switch_description' => 'Office - Desk 5A'],
            ['switchport_mode' => 'access', 'access_vlan' => 100, 'speed' => '1000', 'status' => 'up', 'admin_status' => 'up', 'duplex' => 'full', 'poe_status' => null, 'switch_description' => 'Office - Desk 5B'],
            ['switchport_mode' => 'access', 'access_vlan' => 500, 'speed' => '100', 'status' => 'up', 'admin_status' => 'up', 'duplex' => 'full', 'poe_status' => 'on', 'switch_description' => 'Printer - Floor 2'],
            ['switchport_mode' => 'access', 'access_vlan' => 100, 'speed' => '1000', 'status' => 'up', 'admin_status' => 'up', 'duplex' => 'full', 'poe_status' => 'on', 'switch_description' => 'AP - Floor 2 West'],
            ['switchport_mode' => 'access', 'access_vlan' => 200, 'speed' => '1000', 'status' => 'up', 'admin_status' => 'up', 'duplex' => 'full', 'poe_status' => null, 'switch_description' => 'Server Room - Rack 3'],
            ['switchport_mode' => 'access', 'access_vlan' => 400, 'speed' => '100', 'status' => 'up', 'admin_status' => 'up', 'duplex' => 'full', 'poe_status' => 'on', 'switch_description' => 'CCTV - Hallway'],
            ['switchport_mode' => 'access', 'access_vlan' => 300, 'speed' => '1000', 'status' => 'up', 'admin_status' => 'up', 'duplex' => 'full', 'poe_status' => 'on', 'switch_description' => 'VoIP Phone - Ext 203'],
            ['switchport_mode' => 'access', 'access_vlan' => 100, 'speed' => '1000', 'status' => 'up', 'admin_status' => 'up', 'duplex' => 'full', 'poe_status' => null, 'switch_description' => 'Office - Desk 6A'],

            // Ports 31-36: More down ports (unplugged)
            ['switchport_mode' => 'access', 'access_vlan' => 100, 'speed' => null, 'status' => 'down', 'admin_status' => 'up', 'duplex' => null, 'poe_status' => null, 'switch_description' => null],
            ['switchport_mode' => 'access', 'access_vlan' => 100, 'speed' => null, 'status' => 'down', 'admin_status' => 'up', 'duplex' => null, 'poe_status' => null, 'switch_description' => 'Meeting Room A'],
            ['switchport_mode' => 'access', 'access_vlan' => 100, 'speed' => null, 'status' => 'down', 'admin_status' => 'up', 'duplex' => null, 'poe_status' => null, 'switch_description' => null],
            ['switchport_mode' => 'access', 'access_vlan' => 200, 'speed' => null, 'status' => 'down', 'admin_status' => 'up', 'duplex' => null, 'poe_status' => null, 'switch_description' => null],
            ['switchport_mode' => 'access', 'access_vlan' => 100, 'speed' => null, 'status' => 'down', 'admin_status' => 'up', 'duplex' => null, 'poe_status' => null, 'switch_description' => 'Meeting Room B'],
            ['switchport_mode' => 'access', 'access_vlan' => 100, 'speed' => null, 'status' => 'down', 'admin_status' => 'up', 'duplex' => null, 'poe_status' => null, 'switch_description' => null],

            // Ports 37-42: Active ports with admin notes
            ['switchport_mode' => 'access', 'access_vlan' => 100, 'speed' => '1000', 'status' => 'up', 'admin_status' => 'up', 'duplex' => 'full', 'poe_status' => 'on', 'switch_description' => 'Office - Desk 7A', 'admin_notes' => 'New hire starting Monday'],
            ['switchport_mode' => 'access', 'access_vlan' => 100, 'speed' => '1000', 'status' => 'up', 'admin_status' => 'up', 'duplex' => 'full', 'poe_status' => null, 'switch_description' => 'Office - Desk 7B', 'admin_notes' => 'Intermittent connectivity reported'],
            ['switchport_mode' => 'access', 'access_vlan' => 200, 'speed' => '1000', 'status' => 'up', 'admin_status' => 'up', 'duplex' => 'full', 'poe_status' => null, 'switch_description' => 'Server Room - NAS', 'admin_notes' => 'Backup NAS - do not disconnect'],
            ['switchport_mode' => 'access', 'access_vlan' => 100, 'speed' => '100', 'status' => 'up', 'admin_status' => 'up', 'duplex' => 'half', 'poe_status' => null, 'switch_description' => 'Legacy Device', 'admin_notes' => 'Old device, auto-negotiate disabled'],
            ['switchport_mode' => 'access', 'access_vlan' => 400, 'speed' => '1000', 'status' => 'up', 'admin_status' => 'up', 'duplex' => 'full', 'poe_status' => 'on', 'switch_description' => 'CCTV - Car Park', 'admin_notes' => 'Replaced camera 2026-04-15'],
            ['switchport_mode' => 'access', 'access_vlan' => 300, 'speed' => '1000', 'status' => 'up', 'admin_status' => 'up', 'duplex' => 'full', 'poe_status' => 'on', 'switch_description' => 'VoIP Phone - Ext 204'],

            // Ports 43-46: More down/shutdown mix
            ['switchport_mode' => 'access', 'access_vlan' => 100, 'speed' => null, 'status' => 'down', 'admin_status' => 'up', 'duplex' => null, 'poe_status' => null, 'switch_description' => null],
            ['switchport_mode' => 'access', 'access_vlan' => 100, 'speed' => null, 'status' => 'down', 'admin_status' => 'down', 'duplex' => null, 'poe_status' => null, 'switch_description' => 'DISABLED - Cable fault'],
            ['switchport_mode' => 'access', 'access_vlan' => 100, 'speed' => null, 'status' => 'down', 'admin_status' => 'up', 'duplex' => null, 'poe_status' => null, 'switch_description' => 'Storage Room'],
            ['switchport_mode' => 'access', 'access_vlan' => 100, 'speed' => null, 'status' => 'down', 'admin_status' => 'down', 'duplex' => null, 'poe_status' => null, 'switch_description' => 'DISABLED - Reserved'],

            // Ports 47-48: 10G SFP+ uplinks
            ['switchport_mode' => 'trunk', 'access_vlan' => null, 'speed' => '10000', 'status' => 'up', 'admin_status' => 'up', 'duplex' => 'full', 'poe_status' => null, 'switch_description' => '10G Uplink - Distribution'],
            ['switchport_mode' => 'trunk', 'access_vlan' => null, 'speed' => '10000', 'status' => 'down', 'admin_status' => 'up', 'duplex' => null, 'poe_status' => null, 'switch_description' => '10G Uplink - Spare'],
        ];
    }

    /**
     * @param  array<string, mixed>  $def
     */
    private function generateConfigText(int $portNum, array $def): string
    {
        $lines = ["interface GigabitEthernet1/0/{$portNum}"];

        if (isset($def['switch_description'])) {
            $lines[] = " description {$def['switch_description']}";
        }

        if ($def['switchport_mode'] === 'trunk') {
            $lines[] = ' switchport mode trunk';
            $lines[] = ' switchport trunk allowed vlan all';
        } else {
            $lines[] = " switchport access vlan {$def['access_vlan']}";
            $lines[] = ' switchport mode access';
        }

        if ($def['admin_status'] === 'down') {
            $lines[] = ' shutdown';
        }

        if ($def['poe_status'] === 'on') {
            $lines[] = ' power inline auto';
        }

        $lines[] = ' spanning-tree portfast';

        return implode("\n", $lines);
    }
}
