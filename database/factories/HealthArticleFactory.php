<?php

namespace Database\Factories;

use App\Models\HealthArticle;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<HealthArticle>
 */
class HealthArticleFactory extends Factory
{
    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'title' => fake()->sentence(4),
            'body' => fake()->paragraphs(2, true),
            'image_url' => null,
            'published_at' => now(),
        ];
    }

    public function draft(): static
    {
        return $this->state(['published_at' => null]);
    }
}
