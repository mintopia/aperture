<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Models\MacAddress;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<MacAddress>
 */
class MacAddressFactory extends Factory
{
    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'mac_address' => fake()->macAddress(),
            'source' => 'auth',
        ];
    }

    public function xbox(): static
    {
        return $this->state(fn (array $attributes): array => [
            'source' => 'xbox',
            'description' => 'Xbox Console',
        ]);
    }
}
