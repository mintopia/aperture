<?php

namespace Database\Factories;

use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<User>
 */
class UserFactory extends Factory
{
    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'nickname' => fake()->name(),
            'email' => fake()->unique()->safeEmail(),
            'blocked' => false,
        ];
    }

    /**
     * Indicate that the user is blocked.
     */
    public function blocked(): static
    {
        return $this->state(fn (array $attributes) => [
            'blocked' => true,
        ]);
    }
}
