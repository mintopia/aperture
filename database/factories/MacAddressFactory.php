<?php

namespace Database\Factories;

use App\Models\MacAddress;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<MacAddress>
 */
class MacAddressFactory extends Factory
{
    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'mac_address' => fake()->macAddress(),
            'source' => 'auth',
            'allowed' => false,
        ];
    }

    /**
     * Indicate that the MAC address is allowed.
     */
    public function allowed(): static
    {
        return $this->state(fn (array $attributes) => [
            'allowed' => true,
            'allowed_at' => now(),
        ]);
    }

    /**
     * Indicate that the MAC address is from an Xbox console.
     */
    public function xbox(): static
    {
        return $this->state(fn (array $attributes) => [
            'source' => 'xbox',
            'allowed' => true,
            'allowed_at' => now(),
            'description' => 'Xbox Console',
        ]);
    }
}
