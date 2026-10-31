<?php

namespace Database\Factories;

use App\Models\Product;
use App\Models\ProductSubcategory;
use App\Models\ProductUnit;
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

    public function withCatalog(?ProductSubcategory $subcategory = null, ?ProductUnit $unit = null): static
    {
        return $this->state(function () use ($subcategory, $unit): array {
            $subcategory ??= ProductSubcategory::factory()->create();
            $unit ??= ProductUnit::factory()->create(['name' => 'Pack', 'code' => 'pack-'.fake()->unique()->numerify('###')]);

            return [
                'category' => $subcategory->category->name,
                'sub_category' => $subcategory->name,
                'unit' => $unit->name,
                'product_subcategory_id' => $subcategory->id,
                'product_unit_id' => $unit->id,
            ];
        });
    }
}
