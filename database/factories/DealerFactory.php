<?php

namespace Database\Factories;

use App\Models\Dealer;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

class DealerFactory extends Factory
{
    protected $model = Dealer::class;

    public function definition(): array
    {
        return ['user_id' => User::factory(), 'code' => strtoupper(fake()->unique()->bothify('NX-####-??')), 'level' => 'District', 'division' => 'Dhaka', 'district' => 'Dhaka', 'status' => 'active'];
    }

    public function pending(): static
    {
        return $this->state(fn () => ['status' => 'pending']);
    }
}
