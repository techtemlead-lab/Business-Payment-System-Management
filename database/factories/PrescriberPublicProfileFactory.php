<?php

namespace Database\Factories;

use App\Models\PrescriberPublicProfile;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<PrescriberPublicProfile>
 */
class PrescriberPublicProfileFactory extends Factory
{
    protected $model = PrescriberPublicProfile::class;

    /**
     * A prescriber who has chosen to be listed.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'user_id' => User::factory()->dealer(),
            'listed' => true,
            'chamber_hours' => null,
        ];
    }
}
