<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Models\IntegrationConfig;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<IntegrationConfig>
 */
class IntegrationConfigFactory extends Factory
{
    protected $model = IntegrationConfig::class;

    public function definition(): array
    {
        return [
            'integration' => fake()->randomElement(['opnsense', 'librenms', 'pihole', 'ntopng']),
            'key' => fake()->word(),
            'value' => json_encode(['v' => fake()->word()]),
            'encrypted' => false,
        ];
    }

    public function encrypted(): static
    {
        return $this->state(fn (): array => [
            'encrypted' => true,
            'value' => json_encode(['v' => encrypt(fake()->word())]),
        ]);
    }
}
