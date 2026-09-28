<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Enums\Capability;
use App\Models\CapabilityAssignment;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<CapabilityAssignment>
 */
class CapabilityAssignmentFactory extends Factory
{
    protected $model = CapabilityAssignment::class;

    public function definition(): array
    {
        return [
            'capability' => fake()->unique()->randomElement(Capability::cases()),
            'integration' => fake()->randomElement(['opnsense', 'librenms', 'ntopng', 'pihole']),
        ];
    }
}
