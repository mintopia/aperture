<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Models\ConnectionTestLog;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<ConnectionTestLog>
 */
class ConnectionTestLogFactory extends Factory
{
    protected $model = ConnectionTestLog::class;

    public function definition(): array
    {
        return [
            'integration' => fake()->randomElement(['opnsense', 'librenms', 'ntopng', 'pihole']),
            'success' => fake()->boolean(80),
            'message' => fake()->sentence(),
            'response_time_ms' => fake()->numberBetween(50, 3000),
        ];
    }

    public function successful(): static
    {
        return $this->state(fn (): array => [
            'success' => true,
            'message' => 'Connection successful',
        ]);
    }

    public function failed(): static
    {
        return $this->state(fn (): array => [
            'success' => false,
            'message' => 'Connection failed: timeout',
        ]);
    }
}
