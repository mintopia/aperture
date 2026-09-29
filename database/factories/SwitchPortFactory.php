<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Models\SwitchConfig;
use App\Models\SwitchPort;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<SwitchPort>
 */
class SwitchPortFactory extends Factory
{
    protected $model = SwitchPort::class;

    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        $portNum = fake()->unique()->numberBetween(1, 48);

        return [
            'switch_config_id' => SwitchConfig::factory(),
            'port_name' => 'GigabitEthernet1/0/'.$portNum,
            'port_number' => 'Gi1/0/'.$portNum,
            'switch_description' => fake()->optional()->sentence(),
            'admin_notes' => null,
            'access_vlan' => fake()->numberBetween(1, 4094),
            'switchport_mode' => 'access',
            'speed' => '1000',
            'status' => 'up',
            'admin_status' => 'up',
            'duplex' => 'full',
            'poe_status' => null,
            'last_synced_at' => now(),
        ];
    }

    public function down(): static
    {
        return $this->state(fn (): array => [
            'status' => 'down',
        ]);
    }

    public function adminDown(): static
    {
        return $this->state(fn (): array => [
            'admin_status' => 'down',
            'status' => 'down',
        ]);
    }

    public function trunk(): static
    {
        return $this->state(fn (): array => [
            'switchport_mode' => 'trunk',
            'access_vlan' => null,
        ]);
    }

    public function access(): static
    {
        return $this->state(fn (): array => [
            'switchport_mode' => 'access',
            'access_vlan' => fake()->numberBetween(1, 4094),
        ]);
    }
}
