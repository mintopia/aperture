<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Models\SwitchPort;
use App\Models\SwitchPortMac;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<SwitchPortMac>
 */
class SwitchPortMacFactory extends Factory
{
    protected $model = SwitchPortMac::class;

    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'switch_port_id' => SwitchPort::factory(),
            'mac_address' => fake()->macAddress(),
            'vlan' => fake()->numberBetween(1, 4094),
            'last_seen_at' => now(),
        ];
    }
}
