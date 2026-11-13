<?php

namespace Database\Factories;

use App\Models\PrescriptionDietTemplate;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<PrescriptionDietTemplate>
 */
class PrescriptionDietTemplateFactory extends Factory
{
    protected $model = PrescriptionDietTemplate::class;

    /**
     * A partner's food template with one food to eat and one to avoid.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'prescriber_id' => User::factory()->dealer()->state(['status' => 'active', 'can_prescribe' => true]),
            'name' => 'Diet '.fake()->unique()->numerify('#####'),
            'foods_allowed' => ['Vegetables'],
            'foods_avoided' => ['Fried food'],
        ];
    }
}
