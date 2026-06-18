<?php

namespace Database\Factories;

use App\Models\User;
use App\Models\UserParameter;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<UserParameter>
 */
class UserParameterFactory extends Factory
{
    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'user_id' => User::factory(),
            'key' => fake()->unique()->word(),
            'value' => fake()->word(),
        ];
    }
}
