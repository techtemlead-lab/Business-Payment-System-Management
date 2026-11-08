<?php

namespace Database\Factories;

use App\Models\Prescription;
use App\Models\PrescriptionPatient;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Prescription>
 */
class PrescriptionFactory extends Factory
{
    protected $model = Prescription::class;

    /**
     * A partner prescription with no lines, written by the patient's own prescriber.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'number' => 'RX-'.fake()->unique()->numerify('######'),
            'kind' => Prescription::KindPartner,
            'patient_id' => PrescriptionPatient::factory(),
            'prescriber_id' => fn (array $attributes): int => (int) PrescriptionPatient::query()->whereKey($attributes['patient_id'])->value('prescriber_id'),
            'prescribed_on' => now('Asia/Dhaka')->toDateString(),
            'complaints' => 'Cough for three days',
            'diagnosis' => 'Common cold',
            'advice' => 'Drink warm water',
            'status' => Prescription::StatusIssued,
        ];
    }
}
