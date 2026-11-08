<?php

namespace App\Domain\Integrations;

use App\Models\PlaceCode;
use App\Models\User;
use App\Support\Audit;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * Which of a courier's own areas each of our place codes belongs to. Pathao books by city,
 * zone and (optionally) area numbers, RedX by an area number and name. The numbers are entered
 * by the owner or picked from the list the courier gave us. A place with no entry of its own
 * uses the nearest place above it that has one.
 */
final class CourierAreaMapping
{
    /** @var array<string, list<string>> The courier's own levels, largest first. */
    public const Levels = ['pathao' => ['city', 'zone', 'area'], 'redx' => ['area']];

    /** @var array<string, list<string>> The levels a booking cannot go without. */
    public const Required = ['pathao' => ['city', 'zone'], 'redx' => ['area']];

    /** Parent steps to follow from a union up to its division, with room to spare. */
    private const MaxDepth = 8;

    public static function needsMapping(?string $provider): bool
    {
        return $provider !== null && array_key_exists($provider, self::Levels);
    }

    /** @return list<string> */
    public static function providers(): array
    {
        return array_keys(self::Levels);
    }

    /**
     * The courier area for a place code: its own, or the nearest place above it that has one.
     *
     * @return array{city_id: ?string, city_name: ?string, zone_id: ?string, zone_name: ?string, area_id: ?string, area_name: ?string, place_code: string}|null
     */
    public function forPlaceCode(string $provider, string $placeCode): ?array
    {
        if (! self::needsMapping($provider) || trim($placeCode) === '') {
            return null;
        }
        $place = PlaceCode::findByCode($placeCode, false);
        if ($place === null) {
            return null;
        }
        $chain = [];
        $cursor = $place;
        for ($depth = 0; $cursor !== null && $depth < self::MaxDepth; $depth++) {
            $chain[(int) $cursor->id] = (string) $cursor->code;
            $cursor = $cursor->parent_id ? PlaceCode::query()->find($cursor->parent_id) : null;
        }
        $rows = DB::table('courier_area_mappings')->where('provider', $provider)->whereIn('place_code_id', array_keys($chain))->get()->keyBy('place_code_id');
        foreach ($chain as $placeId => $code) {
            $row = $rows->get($placeId);
            if ($row !== null) {
                return [
                    'city_id' => $row->city_id, 'city_name' => $row->city_name,
                    'zone_id' => $row->zone_id, 'zone_name' => $row->zone_name,
                    'area_id' => $row->area_id, 'area_name' => $row->area_name,
                    'place_code' => $code,
                ];
            }
        }

        return null;
    }

    /**
     * @param  array<string, mixed>  $input
     */
    public function save(User $actor, array $input): void
    {
        abort_unless($actor->owner(), 403);
        $provider = (string) $input['provider'];
        $place = PlaceCode::query()->findOrFail((int) $input['place_code_id']);
        $row = [];
        foreach (['city', 'zone', 'area'] as $level) {
            $used = in_array($level, self::Levels[$provider], true);
            $externalId = $used ? trim((string) ($input[$level.'_id'] ?? '')) : '';
            $name = $used ? trim((string) ($input[$level.'_name'] ?? '')) : '';
            if ($externalId !== '' && $name === '') {
                $name = (string) DB::table('courier_area_options')->where('provider', $provider)->where('level', $level)->where('external_id', $externalId)->value('name');
            }
            $row[$level.'_id'] = $externalId !== '' ? $externalId : null;
            $row[$level.'_name'] = $name !== '' ? $name : null;
        }
        foreach (self::Required[$provider] as $level) {
            if ($row[$level.'_id'] === null) {
                throw ValidationException::withMessages([$level.'_id' => __('courier_areas.err_'.$level.'_required')]);
            }
        }
        if ($provider === 'redx' && $row['area_name'] === null) {
            throw ValidationException::withMessages(['area_name' => __('courier_areas.err_redx_name')]);
        }
        DB::table('courier_area_mappings')->upsert(
            [['provider' => $provider, 'place_code_id' => $place->id, ...$row, 'updated_by' => $actor->id, 'created_at' => now(), 'updated_at' => now()]],
            ['provider', 'place_code_id'],
            [...array_keys($row), 'updated_by', 'updated_at'],
        );
        Audit::record($actor->id, 'courier_area.mapped', $provider.':'.$place->code, $row);
    }

    public function remove(User $actor, string $provider, int $placeCodeId): void
    {
        abort_unless($actor->owner(), 403);
        $place = PlaceCode::query()->findOrFail($placeCodeId);
        $removed = DB::table('courier_area_mappings')->where('provider', $provider)->where('place_code_id', $placeCodeId)->delete();
        if ($removed > 0) {
            Audit::record($actor->id, 'courier_area.removed', $provider.':'.$place->code);
        }
    }
}
