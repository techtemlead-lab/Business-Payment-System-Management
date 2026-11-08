<?php

namespace Database\Factories;

use App\Models\CompanyBranch;
use App\Models\Product;
use App\Models\StockLot;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * Only for read-side tests: real stock goes through StockLots, which also moves the branch total.
 *
 * @extends Factory<StockLot>
 */
class StockLotFactory extends Factory
{
    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'branch_id' => fn (): int => (int) CompanyBranch::query()->where('code', 'HO-PIRGANJ')->value('id'),
            'product_id' => Product::factory(),
            'batch_number' => 'BN-'.fake()->unique()->numerify('#####'),
            'expires_on' => now()->addYear()->toDateString(),
            'quantity' => 10,
            'received_quantity' => 10,
            'received_on' => now()->toDateString(),
            'unit_cost' => '50.00',
            'source' => 'stock_in',
        ];
    }

    public function expired(): static
    {
        return $this->state(['expires_on' => now()->subDays(10)->toDateString()]);
    }
}
