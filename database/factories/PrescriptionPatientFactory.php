<?php

namespace Database\Factories;

use App\Models\PrescriptionPatient;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<PrescriptionPatient>
 */
class PrescriptionPatientFactory extends Factory
{
    protected $model = PrescriptionPatient::class;

    /** @return array<string, mixed> */
    public function definition(): array
    {
        return [
            'prescriber_id' => User::factory()->state(['role' => 'dealer']),
            'name' => 'Synthetic patient '.fake()->unique()->numberBetween(1, 99999),
            'age' => fake()->numberBetween(5, 80),
            'sex' => fake()->randomElement(PrescriptionPatient::Sexes),
            'phone' => '01700'.fake()->numerify('######'),
            'notes' => null,
        ];
    }
}
