<?php

namespace Database\Factories;

use App\Models\PrivacyRequest;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<PrivacyRequest>
 */
class PrivacyRequestFactory extends Factory
{
    protected $model = PrivacyRequest::class;

    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'user_id' => User::factory()->dealer(),
            'kind' => PrivacyRequest::KindAccess,
        ];
    }
}
