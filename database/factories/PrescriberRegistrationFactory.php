<?php

namespace Database\Factories;

use App\Models\PrescriberRegistration;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<PrescriberRegistration>
 */
class PrescriberRegistrationFactory extends Factory
{
    protected $model = PrescriberRegistration::class;

    /**
     * An enabled BMDC registration for a new dealer.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'user_id' => User::factory()->state(['role' => 'dealer']),
            'enabled' => true,
            'registration_type' => 'bmdc',
            'registration_number' => 'A-'.fake()->unique()->numerify('#####'),
            'note' => 'Licence papers checked at the office.',
            'enabled_at' => now(),
        ];
    }

    /** A registration that was turned off again. */
    public function disabled(): static
    {
        return $this->state(fn (): array => ['enabled' => false, 'disabled_at' => now()]);
    }
}
