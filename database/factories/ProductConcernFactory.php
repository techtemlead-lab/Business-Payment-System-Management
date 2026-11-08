<?php

namespace Database\Factories;

use App\Models\ProductConcern;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<ProductConcern>
 */
class ProductConcernFactory extends Factory
{
    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        $suffix = fake()->unique()->numerify('####');

        return [
            'name_bn' => 'বিষয় '.$suffix,
            'name_en' => 'Concern '.$suffix,
        ];
    }
}
