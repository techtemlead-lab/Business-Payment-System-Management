<?php

namespace Database\Factories;

use App\Models\ConsultationRequest;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<ConsultationRequest>
 */
class ConsultationRequestFactory extends Factory
{
    protected $model = ConsultationRequest::class;

    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'prescriber_id' => User::factory()->dealer(),
            'name' => fake()->name(),
            'phone' => '017'.fake()->unique()->numerify('########'),
            'problem' => fake()->sentence(),
            'preferred_time' => null,
            'consent_statement' => 'I agree.',
            'consented_at' => now(),
        ];
    }
}
