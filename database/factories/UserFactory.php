<?php

namespace Database\Factories;

use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;

/**
 * @extends Factory<User>
 */
class UserFactory extends Factory
{
    /**
     * The current password being used by the factory.
     */
    protected static ?string $password;

    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'name' => fake()->name(),
            'email' => fake()->unique()->safeEmail(),
            'email_verified_at' => now(),
            'password' => static::$password ??= Hash::make('password'),
            'remember_token' => Str::random(10),
            'role' => 'dealer',
            'status' => 'active',
        ];
    }

    /**
     * Indicate that the model's email address should be unverified.
     */
    public function unverified(): static
    {
        return $this->state(fn (array $attributes) => [
            'email_verified_at' => null,
        ]);
    }

    public function dealer(): static
    {
        return $this->state(fn () => ['role' => 'dealer', 'dealer_code' => 'SYN-D-'.Str::upper(Str::random(8))]);
    }

    public function subDealer(): static
    {
        return $this->state(fn () => ['role' => 'sub_dealer', 'dealer_code' => 'SYN-S-'.Str::upper(Str::random(8))]);
    }

    public function staff(string $role = 'super_admin'): static
    {
        return $this->state(fn () => ['role' => $role, 'dealer_code' => null]);
    }

    public function suspended(): static
    {
        return $this->state(fn () => ['status' => 'suspended']);
    }
}
