<?php

namespace Database\Factories;

use App\Models\Supplier;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Supplier>
 */
class SupplierFactory extends Factory
{
    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'name' => 'Supplier '.fake()->unique()->bothify('??##'),
            'contact' => '+8801700000'.fake()->numerify('###'),
            'notes' => null,
            'created_by' => User::factory()->staff('inventory'),
        ];
    }

    public function archived(): static
    {
        return $this->state(fn (): array => ['archived_at' => now()]);
    }
}
