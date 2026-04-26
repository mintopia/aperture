<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Models\IpAddress;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<IpAddress>
 */
class IpAddressFactory extends Factory
{
    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'address' => fake()->ipv4(),
            'last_seen_at' => now(),
            'internet_enabled' => false,
            'rate_limit_enabled' => false,
            'dns_filtering_enabled' => false,
        ];
    }

    /**
     * Indicate that the IP is internet enabled.
     */
    public function internetEnabled(): static
    {
        return $this->state(fn (array $attributes): array => [
            'internet_enabled' => true,
        ]);
    }

    /**
     * Indicate that the IP session has expired.
     */
    public function expired(): static
    {
        return $this->state(fn (array $attributes): array => [
            'internet_enabled' => true,
            'expires_at' => now()->subHour(),
        ]);
    }
}
