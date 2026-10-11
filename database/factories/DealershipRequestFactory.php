<?php

namespace Database\Factories;

use Illuminate\Database\Eloquent\Factories\Factory;

class DealershipRequestFactory extends Factory
{
    public function definition(): array
    {
        return [
            'name' => fake()->name(),
            'email' => fake()->safeEmail(),
            'mobile' => '+8801712345678',
            'business_name' => fake()->company(),
            'business_address' => fake()->address(),
            'level' => 'District',
            'division' => 'Dhaka',
            'district' => 'Dhaka',
            'status' => 'pending',
        ];
    }
}
