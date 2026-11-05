<?php

namespace Database\Factories;

use App\Models\TrainingItem;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<TrainingItem>
 */
class TrainingItemFactory extends Factory
{
    protected $model = TrainingItem::class;

    /**
     * A draft plain-text article for everyone.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'title_bn' => 'প্রশিক্ষণ '.fake()->unique()->numberBetween(1, 99999),
            'title_en' => fake()->sentence(4),
            'summary' => fake()->sentence(10),
            'type' => 'article',
            'topic' => fake()->randomElement(TrainingItem::Topics),
            'audience' => 'all',
            'video_url' => null,
            'body' => fake()->paragraphs(2, true),
            'pdf_path' => null,
            'pdf_name' => null,
            'pdf_size' => null,
            'sort_order' => fake()->numberBetween(1, 100),
            'published' => false,
            'published_at' => null,
        ];
    }

    public function published(): static
    {
        return $this->state(fn (): array => ['published' => true, 'published_at' => now()]);
    }

    public function forAudience(string $audience): static
    {
        return $this->state(fn (): array => ['audience' => $audience]);
    }

    public function video(string $url = 'https://www.youtube.com/watch?v=dQw4w9WgXcQ'): static
    {
        return $this->state(fn (): array => ['type' => 'video', 'video_url' => $url, 'body' => null]);
    }

    public function pdf(string $path = 'training/guide.pdf'): static
    {
        return $this->state(fn (): array => ['type' => 'pdf', 'pdf_path' => $path, 'pdf_name' => 'guide.pdf', 'pdf_size' => 1024, 'body' => null]);
    }
}
