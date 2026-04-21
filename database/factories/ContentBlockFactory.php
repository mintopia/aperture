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
            'type' => fake()->randomElement(['bandwidth', 'custom_markdown']),
            'title' => fake()->sentence(3),
            'content' => fake()->optional()->paragraph(),
            'grid_col' => 1,
            'grid_row' => 1,
            'col_span' => 1,
            'row_span' => 1,
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

    public function customMarkdown(): static
    {
        return $this->state(fn (array $attributes) => [
            'type' => 'custom_markdown',
            'title' => fake()->sentence(3),
            'content' => fake()->paragraphs(2, true),
        ]);
    }

    public function connectionStrip(): static
    {
        return $this->state(fn (array $attributes) => [
            'type' => 'connection_strip',
            'title' => 'Connection Status',
        ]);
    }

    public function dnsFilter(): static
    {
        return $this->state(fn (array $attributes) => [
            'type' => 'dns_filter',
            'title' => 'DNS Ad Blocking',
            'content' => 'Toggle DNS filtering for your connection.',
        ]);
    }

    public function bandwidth(): static
    {
        return $this->state(fn (array $attributes) => [
            'type' => 'bandwidth',
            'title' => 'Bandwidth',
        ]);
    }

    public function atPosition(int $col, int $row, int $colSpan = 1, int $rowSpan = 1): static
    {
        return $this->state(fn (array $attributes) => [
            'grid_col' => $col,
            'grid_row' => $row,
            'col_span' => $colSpan,
            'row_span' => $rowSpan,
        ]);
    }
}
