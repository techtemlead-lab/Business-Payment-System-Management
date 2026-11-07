<?php

namespace Database\Factories;

use App\Models\PartnerEvent;
use App\Models\PlaceCode;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Carbon;

/**
 * @extends Factory<PartnerEvent>
 */
class PartnerEventFactory extends Factory
{
    protected $model = PartnerEvent::class;

    /**
     * A draft two-hour meeting for all partners, three days from now at 10:00 Dhaka time.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        $start = Carbon::now(PartnerEvent::Timezone)->addDays(3)->setTime(10, 0);

        return [
            'title_bn' => 'মাসিক সভা '.fake()->unique()->numberBetween(1, 99999),
            'title_en' => 'Monthly meeting '.fake()->unique()->numberBetween(1, 99999),
            'starts_at' => $start->copy()->utc(),
            'ends_at' => $start->copy()->addHours(2)->utc(),
            'place' => 'Pirganj office, Thana Road',
            'online_url' => null,
            'audience' => 'all',
            'divisions' => null,
            'notes' => null,
            'status' => 'draft',
            'send_sms' => false,
            'published_at' => null,
        ];
    }

    public function published(): static
    {
        return $this->state(fn (): array => ['status' => 'published', 'published_at' => now()]);
    }

    /** Starts at the given Dhaka wall-clock time, e.g. "2026-11-10 09:30". */
    public function startingAt(string $dhakaDateTime, int $hours = 2): static
    {
        $start = Carbon::createFromFormat('Y-m-d H:i', $dhakaDateTime, PartnerEvent::Timezone);

        return $this->state(fn (): array => [
            'starts_at' => $start->copy()->utc(),
            'ends_at' => $start->copy()->addHours($hours)->utc(),
        ]);
    }

    /**
     * An event for some divisions, targeted by their place code ids (names kept for display).
     *
     * @param  list<PlaceCode>  $divisions
     */
    public function forDivisions(array $divisions): static
    {
        return $this->state(fn (): array => [
            'audience' => 'divisions',
            'divisions' => array_map(fn (PlaceCode $place): string => (string) $place->name, $divisions),
            'division_place_ids' => array_map(fn (PlaceCode $place): int => (int) $place->id, $divisions),
        ]);
    }
}
