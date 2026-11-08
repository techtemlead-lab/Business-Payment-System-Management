<?php

namespace Database\Factories;

use App\Models\QualityDocument;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<QualityDocument>
 */
class QualityDocumentFactory extends Factory
{
    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'title' => fake()->sentence(3),
            'file_path' => 'quality-documents/'.fake()->uuid().'.pdf',
            'original_name' => 'document.pdf',
            'mime_type' => 'application/pdf',
            'size_bytes' => 1024,
            'is_published' => true,
        ];
    }

    public function hidden(): static
    {
        return $this->state(['is_published' => false]);
    }
}
