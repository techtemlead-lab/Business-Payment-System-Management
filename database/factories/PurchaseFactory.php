<?php

namespace Database\Factories;

use App\Models\Dealer;
use App\Models\User;
use App\Services\FinancialCalculator;
use Illuminate\Database\Eloquent\Factories\Factory;

class PurchaseFactory extends Factory
{
    public function definition(): array
    {
        return ['dealer_id' => Dealer::factory(), 'reference' => fake()->unique()->bothify('INV-########'), 'product_name' => 'Fixture product', 'quantity' => 2, 'unit_mrp_minor' => 37500, 'total_mrp_minor' => 75000, 'amount_paid_minor' => 65000, 'company_minor' => 22500, 'dealer_minor' => 37500, 'pool_minor' => 15000, 'allocated_pool_minor' => 6000, 'unallocated_pool_minor' => 9000, 'purchased_on' => now()->toDateString(), 'calculation' => (new FinancialCalculator)->preview(75000), 'actor_id' => User::factory()->state(['role' => 'admin'])];
    }
}
