<?php

namespace Database\Factories;

use App\Models\SiteFeedback;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<SiteFeedback>
 */
class SiteFeedbackFactory extends Factory
{
    protected $model = SiteFeedback::class;

    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'name' => fake()->name(),
            'role' => fake()->randomElement(['Wellness Client', 'Authorized Dealer Partner', 'Verified Client']),
            'location' => fake()->randomElement(['Dhaka', 'Chittagong', 'Sylhet', 'Rajshahi', 'Khulna']),
            'rating' => fake()->numberBetween(4, 5),
            'message' => fake()->sentence(18),
            'source' => 'public',
            'user_id' => null,
            'status' => 'pending',
            'moderated_by' => null,
            'moderated_at' => null,
        ];
    }

    public function approved(?User $moderator = null): static
    {
        return $this->state(fn (): array => [
            'status' => 'approved',
            'moderated_by' => $moderator?->id,
            'moderated_at' => now(),
        ]);
    }

    public function rejected(?User $moderator = null): static
    {
        return $this->state(fn (): array => [
            'status' => 'rejected',
            'moderated_by' => $moderator?->id,
            'moderated_at' => now(),
        ]);
    }

    public function fromDealer(User $dealer): static
    {
        return $this->state(fn (): array => [
            'source' => $dealer->role === 'sub_dealer' ? 'sub_dealer' : 'dealer',
            'user_id' => $dealer->id,
            'name' => $dealer->name,
            'role' => $dealer->role === 'sub_dealer' ? 'Sub-Dealer Partner' : 'Authorized Dealer Partner',
        ]);
    }
}
