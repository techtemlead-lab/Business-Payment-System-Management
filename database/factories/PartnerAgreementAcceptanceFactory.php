<?php

namespace Database\Factories;

use App\Models\PartnerAgreementAcceptance;
use App\Models\PartnerAgreementVersion;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<PartnerAgreementAcceptance>
 */
class PartnerAgreementAcceptanceFactory extends Factory
{
    protected $model = PartnerAgreementAcceptance::class;

    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'user_id' => User::factory()->dealer(),
            'partner_agreement_version_id' => fn (): int => (int) (PartnerAgreementVersion::query()->orderByDesc('version')->value('id')
                ?? PartnerAgreementVersion::factory()->create()->id),
            'accepted_at' => now(),
            'ip_address' => fake()->ipv4(),
            'user_agent' => fake()->userAgent(),
        ];
    }
}
