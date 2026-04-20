<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Models\SwitchPort;
use App\Models\SwitchPortConfig;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<SwitchPortConfig>
 */
class SwitchPortConfigFactory extends Factory
{
    protected $model = SwitchPortConfig::class;

    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        $configText = "interface GigabitEthernet1/0/1\n switchport access vlan 100\n switchport mode access\n spanning-tree portfast";

        return [
            'switch_port_id' => SwitchPort::factory(),
            'config_text' => $configText,
            'config_hash' => md5($configText),
            'interface_output' => null,
            'last_fetched_at' => now(),
        ];
    }
}
