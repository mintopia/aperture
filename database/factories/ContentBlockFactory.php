<?php

namespace Database\Factories;

use App\Models\ContentBlock;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<ContentBlock>
 */
class ContentBlockFactory extends Factory
{
    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'type' => fake()->randomElement(['event_info', 'connection_status', 'bandwidth', 'network_stats', 'custom_markdown']),
            'title' => fake()->sentence(3),
            'content' => fake()->optional()->paragraph(),
            'sort_order' => fake()->numberBetween(0, 100),
            'is_active' => true,
            'settings' => null,
        ];
    }

    public function inactive(): static
    {
        return $this->state(fn (array $attributes) => [
            'is_active' => false,
        ]);
    }

    public function eventInfo(): static
    {
        return $this->state(fn (array $attributes) => [
            'type' => 'event_info',
            'title' => 'Event Information',
            'content' => fake()->paragraph(),
        ]);
    }

    public function customMarkdown(): static
    {
        return $this->state(fn (array $attributes) => [
            'type' => 'custom_markdown',
            'title' => fake()->sentence(3),
            'content' => fake()->paragraphs(2, true),
        ]);
    }
}
