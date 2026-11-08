<?php

namespace App\Jobs;

use App\Domain\Integrations\CourierGatewayClient;
use App\Domain\Orders\CourierOptions;
use App\Support\Audit;
use App\Support\RedactedError;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Facades\DB;
use Throwable;

/**
 * Copies a courier's list of cities, zones and areas into the app, for the owner to pick from.
 *
 * Pathao gives its list one city at a time, so each city is its own short run that starts the
 * next: no single run comes near the queue's time limit. Bookings never depend on this list.
 * When a run fails, what was saved stays and the owner is told.
 */
class FetchCourierAreas implements ShouldQueue
{
    use Queueable;

    public int $tries = 1;

    public int $timeout = 80;

    public function __construct(public string $provider, public int $actorId, public string $startedAt, public ?string $cityId = null) {}

    public function handle(CourierGatewayClient $couriers): void
    {
        try {
            if ($this->provider === 'redx') {
                $this->store('area', null, $couriers->areas('redx', 'area'));
                $this->finish();

                return;
            }
            $cityId = $this->cityId;
            if ($cityId === null) {
                $this->store('city', null, $couriers->areas('pathao', 'city'));
                $this->continueFrom(null);

                return;
            }
            $zones = $couriers->areas('pathao', 'zone', $cityId);
            $this->store('zone', $cityId, $zones);
            foreach ($zones as $zone) {
                $this->store('area', $zone['id'], $couriers->areas('pathao', 'area', $zone['id']));
            }
            $this->continueFrom($cityId);
        } catch (Throwable $exception) {
            $this->tell('notice_fetch_failed_title', 'notice_fetch_failed_body', ['error' => RedactedError::message($exception)]);
        }
    }

    /**
     * Starts the next city, or finishes when none are left.
     */
    private function continueFrom(?string $cityId): void
    {
        $cities = DB::table('courier_area_options')->where('provider', $this->provider)->where('level', 'city')->orderBy('id')->pluck('external_id')->all();
        $next = $cityId === null ? ($cities[0] ?? null) : ($cities[array_search($cityId, $cities, true) + 1] ?? null);
        if ($next === null) {
            $this->finish();

            return;
        }
        self::dispatch($this->provider, $this->actorId, $this->startedAt, (string) $next);
    }

    /**
     * @param  list<array{id: string, name: string}>  $rows
     */
    private function store(string $level, ?string $parentId, array $rows): void
    {
        foreach (array_chunk($rows, 500) as $chunk) {
            DB::table('courier_area_options')->upsert(array_map(fn (array $row): array => [
                'provider' => $this->provider, 'level' => $level, 'external_id' => $row['id'],
                'parent_external_id' => $parentId, 'name' => $row['name'], 'fetched_at' => now(),
            ], $chunk), ['provider', 'level', 'external_id'], ['parent_external_id', 'name', 'fetched_at']);
        }
    }

    /**
     * Forgets what the courier no longer lists, and tells the owner how much is now saved.
     */
    private function finish(): void
    {
        DB::table('courier_area_options')->where('provider', $this->provider)->where('fetched_at', '<', $this->startedAt)->delete();
        $this->tell('notice_fetch_done_title', 'notice_fetch_done_body', [
            'count' => DB::table('courier_area_options')->where('provider', $this->provider)->count(),
        ]);
    }

    /**
     * @param  array<string, string|int>  $replace
     */
    private function tell(string $titleKey, string $bodyKey, array $replace): void
    {
        $replace = ['courier' => CourierOptions::label($this->provider), ...$replace];
        Audit::notice($this->actorId, __('courier_areas.'.$titleKey, $replace), __('courier_areas.'.$bodyKey, $replace));
    }
}
