<?php

namespace Database\Factories;

use App\Models\Dealer;
use Illuminate\Database\Eloquent\Factories\Factory;

class WithdrawalRequestFactory extends Factory
{
    public function definition(): array
    {
        return ['dealer_id' => Dealer::factory(), 'amount_minor' => 10000, 'idempotency_key' => fake()->uuid(), 'status' => 'pending'];
    }
}
