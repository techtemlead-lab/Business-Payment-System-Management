<?php

namespace Database\Factories;

use App\Models\ProductCategory;
use App\Models\ProductSubcategory;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;

/**
 * @extends Factory<ProductSubcategory>
 */
class ProductSubcategoryFactory extends Factory
{
    protected $model = ProductSubcategory::class;

    public function definition(): array
    {
        $name = fake()->unique()->words(2, true);

        return [
            'product_category_id' => ProductCategory::factory(),
            'name' => Str::title($name),
            'slug' => Str::slug($name).'-'.fake()->unique()->numerify('###'),
            'sort_order' => 0,
            'is_active' => true,
        ];
    }
}
