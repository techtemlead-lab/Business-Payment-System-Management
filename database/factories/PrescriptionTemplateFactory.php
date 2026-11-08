<?php

namespace Database\Factories;

use App\Models\PrescriptionTemplate;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<PrescriptionTemplate>
 */
class PrescriptionTemplateFactory extends Factory
{
    protected $model = PrescriptionTemplate::class;

    /**
     * A partner's template with one typed medicine.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'prescriber_id' => User::factory()->dealer()->state(['status' => 'active', 'can_prescribe' => true]),
            'name' => 'Template '.fake()->unique()->numerify('#####'),
            'advice' => 'Drink warm water',
            'lines' => [[
                'product_id' => null,
                'medicine_name' => 'Napa 500',
                'dose' => '1 tab',
                'frequency' => '3 times',
                'duration' => '5 days',
                'instructions' => null,
                'unit_price' => '2.50',
                'quantity' => 15,
                'discount' => '0.00',
            ]],
        ];
    }
}
