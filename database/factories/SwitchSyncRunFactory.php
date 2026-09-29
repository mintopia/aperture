<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Models\SwitchConfig;
use App\Models\SwitchSyncRun;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<SwitchSyncRun>
 */
class SwitchSyncRunFactory extends Factory
{
    protected $model = SwitchSyncRun::class;

    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'switch_config_id' => SwitchConfig::factory(),
            'status' => 'pending',
            'started_at' => null,
            'finished_at' => null,
            'error' => null,
            'ports_created' => 0,
            'ports_updated' => 0,
            'macs_created' => 0,
            'macs_updated' => 0,
        ];
    }

    public function running(): static
    {
        return $this->state(fn (): array => [
            'status' => 'running',
            'started_at' => now(),
        ]);
    }

    public function completed(): static
    {
        return $this->state(fn (): array => [
            'status' => 'completed',
            'started_at' => now()->subMinutes(2),
            'finished_at' => now(),
            'ports_created' => fake()->numberBetween(0, 48),
            'ports_updated' => fake()->numberBetween(0, 48),
            'macs_created' => fake()->numberBetween(0, 100),
            'macs_updated' => fake()->numberBetween(0, 100),
        ]);
    }

    public function failed(): static
    {
        return $this->state(fn (): array => [
            'status' => 'failed',
            'started_at' => now()->subMinutes(1),
            'finished_at' => now(),
            'error' => 'SSH connection timeout after 30 seconds',
        ]);
    }
}
