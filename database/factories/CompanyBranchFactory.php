<?php

namespace Database\Factories;

use App\Models\CompanyBranch;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<CompanyBranch>
 */
class CompanyBranchFactory extends Factory
{
    protected $model = CompanyBranch::class;

    public function definition(): array
    {
        $code = 'BR-'.fake()->unique()->bothify('??##');

        return [
            'code' => $code,
            'name' => 'Branch '.$code,
            'division' => 'Dhaka',
            'district' => 'Dhaka',
            'upazila' => 'Savar',
            'address' => 'Synthetic branch address',
            'phone' => '+8801700000'.fake()->numerify('###'),
            'is_active' => true,
            'is_delivery_point' => true,
            'is_stock_hub' => true,
        ];
    }

    public function inactive(): static
    {
        return $this->state(fn () => ['is_active' => false]);
    }
}
