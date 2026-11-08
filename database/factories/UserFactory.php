<?php

namespace Database\Factories;

use App\Domain\Staff\StaffPermissions;
use App\Models\PlaceCode;
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
            // Tests compare English wording unless they ask for Bangla; real accounts default to Bangla.
            'locale' => 'en',
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

    /**
     * Finance staff made here are given approval, as a trusted person would be in the
     * permission matrix. Use {@see self::enterOnly()} for the default a new account gets.
     */
    public function configure(): static
    {
        return $this->afterMaking(function (User $user): void {
            if ($user->role === 'finance' && $user->profile === null) {
                $user->profile = ['action_permissions' => array_fill_keys(config('madina.roles.finance'), array_fill_keys(StaffPermissions::Actions, true))];
            }
        });
    }

    public function staff(string $role = 'super_admin'): static
    {
        return $this->state(fn () => ['role' => $role, 'dealer_code' => null]);
    }

    /** Finance staff as a new account starts: can enter money records but not approve or pay. */
    public function enterOnly(): static
    {
        return $this->state(fn () => ['role' => 'finance', 'dealer_code' => null, 'profile' => []]);
    }

    public function suspended(): static
    {
        return $this->state(fn () => ['status' => 'suspended']);
    }

    /**
     * A partner keyed to a place code, the way account onboarding saves it:
     * the place code id on the profile and the place names for display.
     */
    public function inPlace(PlaceCode $place): static
    {
        return $this->state(function (array $attributes) use ($place): array {
            $path = $place->loadMissing('parent.parent.parent')->pathNames();
            $profile = is_array($attributes['profile'] ?? null) ? $attributes['profile'] : [];

            return [
                'division' => $path['division'],
                'district' => $path['district'],
                'profile' => [
                    ...$profile,
                    'place_code' => $place->code,
                    'place_code_id' => $place->id,
                    'upazila' => $path['upazila'],
                    'union' => $path['union'],
                ],
            ];
        });
    }
}
