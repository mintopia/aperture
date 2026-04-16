<?php

namespace Database\Factories;

use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Facades\Hash;

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
            'external_id' => null,
            'access_token' => null,
            'refresh_token' => null,
            'token_expires_at' => null,
            'avatar_url' => null,
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

    public function withAuth(): static
    {
        return $this->state(fn (array $attributes) => [
            'external_id' => fake()->uuid(),
            'access_token' => fake()->sha256(),
            'refresh_token' => fake()->sha256(),
            'token_expires_at' => now()->addHour(),
            'avatar_url' => fake()->imageUrl(),
        ]);
    }

    public function withPassword(string $password = 'password'): static
    {
        return $this->state(fn (): array => [
            'password' => Hash::make($password),
        ]);
    }
}
