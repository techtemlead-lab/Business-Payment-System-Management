<?php

namespace Database\Factories;

use App\Models\PartnerAgreementVersion;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<PartnerAgreementVersion>
 */
class PartnerAgreementVersionFactory extends Factory
{
    protected $model = PartnerAgreementVersion::class;

    /**
     * The next version number after the newest one already published.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'version' => fn (): int => ((int) PartnerAgreementVersion::query()->max('version')) + 1,
            'body_bn' => 'চুক্তির নতুন সংস্করণ। '.fake()->sentence(),
            'body_en' => 'New agreement version. '.fake()->sentence(),
            'published_by' => null,
            'published_at' => now(),
        ];
    }
}
