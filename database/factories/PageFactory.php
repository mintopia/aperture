<?php

namespace Database\Factories;

use App\Models\Page;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;

/**
 * @extends Factory<Page>
 */
class PageFactory extends Factory
{
    protected $model = Page::class;

    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        $title = fake()->unique()->sentence(3, false);
        $title = rtrim($title, '.');

        return [
            'title' => $title,
            'slug' => Str::slug($title),
            'content' => fake()->paragraphs(3, true),
        ];
    }

    public function terms(): static
    {
        return $this->state(fn (array $attributes): array => [
            'title' => 'Terms and Conditions',
            'slug' => 'terms',
            'content' => '# Terms and Conditions',
        ]);
    }

    public function privacy(): static
    {
        return $this->state(fn (array $attributes): array => [
            'title' => 'Privacy Policy',
            'slug' => 'privacy',
            'content' => '# Privacy Policy',
        ]);
    }
}
