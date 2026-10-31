<?php

namespace Database\Factories;

use App\Models\ProductUnit;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;

/**
 * @extends Factory<ProductUnit>
 */
class ProductUnitFactory extends Factory
{
    protected $model = ProductUnit::class;

    public function definition(): array
    {
        $name = fake()->unique()->randomElement(['Pack', 'Box', 'Bottle', 'Carton', 'Strip', 'Piece']);

        return [
            'name' => $name,
            'code' => Str::slug($name).'-'.fake()->unique()->numerify('###'),
            'sort_order' => 0,
            'is_active' => true,
        ];
    }
}
