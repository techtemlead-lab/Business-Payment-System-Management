<?php

namespace Database\Factories;

use App\Models\Product;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Product>
 */
class ProductFactory extends Factory
{
    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'sku' => 'SYN-'.fake()->unique()->bothify('##??##??'),
            'name' => 'Synthetic product',
            'category' => 'Synthetic category',
            'description' => 'Test-only product information.',
            'mrp' => '200.00',
            'dealer_price' => '140.00',
            'stock' => 20,
            'unit' => 'pack',
            'active' => true,
        ];
    }
}
