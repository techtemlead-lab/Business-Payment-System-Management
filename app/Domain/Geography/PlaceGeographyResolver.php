<?php

namespace App\Domain\Geography;

use App\Models\AreaDealerAssignment;
use App\Models\PlaceCode;
use App\Models\User;

/**
 * Resolves purchase geography from place codes and the designated base-place chain
 * ("last serial") so area commissions can be matched by code rather than free-text names.
 */
final class PlaceGeographyResolver
{
    /**
     * @return array{
     *   division:?string,
     *   district:?string,
     *   upazila:?string,
     *   union:?string,
     *   codes: array{management:?string,division:?string,district:?string,upazila:?string,union:?string},
     *   place_ids: array{management:?int,division:?int,district:?int,upazila:?int,union:?int},
     *   origin_place_code:?string,
     *   origin_level:?string,
     *   resolution: string
     * }
     */
    public function forBuyer(User $buyer): array
    {
        $empty = $this->emptyGeography();
        $anchor = $this->anchorPlaceForBuyer($buyer);
        if ($anchor) {
            return $this->expandFromAnchor($anchor, 'place_code');
        }

        $byName = $this->matchByBuyerNames($buyer);
        if ($byName) {
            return $this->expandFromAnchor($byName, 'name_match');
        }

        return [
            ...$empty,
            'division' => $buyer->division,
            'district' => $buyer->district,
            'upazila' => $buyer->profile['upazila'] ?? null,
            'union' => $buyer->profile['union'] ?? null,
            'resolution' => 'legacy_names',
        ];
    }

    /**
     * Walk parents upward and base_place_id downward to fill the full serial path.
     *
     * @return array<string, mixed>
     */
    public function expandFromAnchor(PlaceCode $anchor, string $resolution = 'place_code'): array
    {
        $geography = $this->emptyGeography();
        $cursor = $anchor->relationLoaded('parent') ? $anchor : $anchor->load('parent');
        $guard = 0;
        while ($cursor && $guard++ < 8) {
            $this->writeLevel($geography, $cursor);
            $cursor = $cursor->parent;
        }

        $cursor = $anchor;
        $guard = 0;
        while ($cursor && $guard++ < 8) {
            $this->writeLevel($geography, $cursor);
            if (! $cursor->base_place_id) {
                break;
            }
            $next = $cursor->relationLoaded('basePlace')
                ? $cursor->basePlace
                : PlaceCode::query()->whereKey($cursor->base_place_id)->where('is_active', true)->first();
            if (! $next || (int) $next->parent_id !== (int) $cursor->id) {
                break;
            }
            $cursor = $next;
        }

        $geography['origin_place_code'] = $anchor->code;
        $geography['origin_level'] = $anchor->level;
        $geography['resolution'] = $resolution;

        return $geography;
    }

    private function anchorPlaceForBuyer(User $buyer): ?PlaceCode
    {
        $assignment = AreaDealerAssignment::query()
            ->where('user_id', $buyer->id)
            ->where('is_active', true)
            ->whereNotNull('place_code_id')
            ->orderByRaw("CASE level WHEN 'union' THEN 1 WHEN 'upazila' THEN 2 WHEN 'district' THEN 3 WHEN 'division' THEN 4 ELSE 5 END")
            ->first();
        if ($assignment) {
            $place = PlaceCode::query()->whereKey($assignment->place_code_id)->where('is_active', true)->first();
            if ($place) {
                return $place;
            }
        }

        $profileCode = trim((string) ($buyer->profile['place_code'] ?? $buyer->profile['area_code'] ?? ''));
        if ($profileCode !== '') {
            $place = PlaceCode::findByCode($profileCode);
            if ($place && $place->level !== PlaceCode::LevelManagement) {
                return $place;
            }
        }

        return null;
    }

    private function matchByBuyerNames(User $buyer): ?PlaceCode
    {
        $union = trim((string) ($buyer->profile['union'] ?? ''));
        $upazila = trim((string) ($buyer->profile['upazila'] ?? ''));
        $district = trim((string) ($buyer->district ?? ''));
        $division = trim((string) ($buyer->division ?? ''));

        foreach ([
            [PlaceCode::LevelUnion, $union],
            [PlaceCode::LevelUpazila, $upazila],
            [PlaceCode::LevelDistrict, $district],
            [PlaceCode::LevelDivision, $division],
        ] as [$level, $name]) {
            if ($name === '') {
                continue;
            }
            $matches = PlaceCode::query()
                ->where('level', $level)
                ->where('is_active', true)
                ->whereRaw('LOWER(TRIM(name)) = ?', [mb_strtolower($name)])
                ->limit(2)
                ->get();
            if ($matches->count() === 1) {
                return $matches->first();
            }
        }

        return null;
    }

    /** @param  array<string, mixed>  $geography */
    private function writeLevel(array &$geography, PlaceCode $place): void
    {
        if ($place->level === PlaceCode::LevelManagement) {
            $geography['management'] = $place->name;
            $geography['codes']['management'] = $place->code;
            $geography['place_ids']['management'] = $place->id;

            return;
        }
        if (! array_key_exists($place->level, $geography)) {
            return;
        }
        $geography[$place->level] = $place->name;
        $geography['codes'][$place->level] = $place->code;
        $geography['place_ids'][$place->level] = $place->id;
    }

    /** @return array<string, mixed> */
    private function emptyGeography(): array
    {
        return [
            'management' => null,
            'division' => null,
            'district' => null,
            'upazila' => null,
            'union' => null,
            'codes' => [
                'management' => null,
                'division' => null,
                'district' => null,
                'upazila' => null,
                'union' => null,
            ],
            'place_ids' => [
                'management' => null,
                'division' => null,
                'district' => null,
                'upazila' => null,
                'union' => null,
            ],
            'origin_place_code' => null,
            'origin_level' => null,
            'resolution' => 'empty',
        ];
    }
}
