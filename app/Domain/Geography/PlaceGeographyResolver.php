<?php

namespace App\Domain\Geography;

use App\Models\AreaDealerAssignment;
use App\Models\PlaceCode;
use App\Models\User;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

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
     * forBuyer() for many buyers at once, with a fixed number of queries however many
     * buyers there are. Each buyer gets exactly what forBuyer() would return.
     *
     * @param  Collection<int, User>  $buyers
     * @return array<int, array<string, mixed>> user id => geography
     */
    public function forBuyers(Collection $buyers): array
    {
        if ($buyers->isEmpty()) {
            return [];
        }
        $anchors = $this->anchorPlacesForBuyers($buyers);
        $byName = $this->matchByBuyersNames($buyers->reject(fn (User $buyer): bool => isset($anchors[(int) $buyer->id])));
        $this->primeChains([...array_values($anchors), ...array_values($byName)]);

        $geographies = [];
        foreach ($buyers as $buyer) {
            $id = (int) $buyer->id;
            $geographies[$id] = match (true) {
                isset($anchors[$id]) => $this->expandFromAnchor($anchors[$id], 'place_code'),
                isset($byName[$id]) => $this->expandFromAnchor($byName[$id], 'name_match'),
                default => [
                    ...$this->emptyGeography(),
                    'division' => $buyer->division,
                    'district' => $buyer->district,
                    'upazila' => $buyer->profile['upazila'] ?? null,
                    'union' => $buyer->profile['union'] ?? null,
                    'resolution' => 'legacy_names',
                ],
            };
        }

        return $geographies;
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

    /**
     * anchorPlaceForBuyer() for many buyers: their best active area assignment, else their profile place code.
     *
     * @param  Collection<int, User>  $buyers
     * @return array<int, PlaceCode> user id => anchor place
     */
    private function anchorPlacesForBuyers(Collection $buyers): array
    {
        $firstAssignments = AreaDealerAssignment::query()
            ->whereIn('user_id', $buyers->pluck('id'))
            ->where('is_active', true)
            ->whereNotNull('place_code_id')
            ->orderBy('user_id')
            ->orderByRaw("CASE level WHEN 'union' THEN 1 WHEN 'upazila' THEN 2 WHEN 'district' THEN 3 WHEN 'division' THEN 4 ELSE 5 END")
            ->orderBy('id')
            ->get()
            ->unique('user_id');
        $assignedPlaces = PlaceCode::query()
            ->whereIn('id', $firstAssignments->pluck('place_code_id')->unique()->values())
            ->where('is_active', true)
            ->get()
            ->keyBy('id');

        $anchors = [];
        foreach ($firstAssignments as $assignment) {
            $place = $assignedPlaces->get($assignment->place_code_id);
            if ($place) {
                $anchors[(int) $assignment->user_id] = $place;
            }
        }

        /** @var array<int, array{0: string, 1: string}> $profileCodes */
        $profileCodes = [];
        foreach ($buyers as $buyer) {
            if (isset($anchors[(int) $buyer->id])) {
                continue;
            }
            $profileCode = trim((string) ($buyer->profile['place_code'] ?? $buyer->profile['area_code'] ?? ''));
            $normalized = $profileCode !== '' ? PlaceCode::normalizeCode($profileCode) : null;
            if ($normalized !== null) {
                $profileCodes[(int) $buyer->id] = [$normalized, substr($normalized, strlen(PlaceCode::BrandPrefix) + 1)];
            }
        }
        if ($profileCodes === []) {
            return $anchors;
        }
        $placesByCode = PlaceCode::query()
            ->where('is_active', true)
            ->whereIn('code', array_values(array_unique(array_merge(...array_values($profileCodes)))))
            ->orderBy('id')
            ->get()
            ->groupBy('code');
        foreach ($profileCodes as $userId => [$normalized, $legacy]) {
            $place = $placesByCode->get($normalized)?->first() ?? $placesByCode->get($legacy)?->first();
            if ($place && $place->level !== PlaceCode::LevelManagement) {
                $anchors[$userId] = $place;
            }
        }

        return $anchors;
    }

    /**
     * matchByBuyerNames() for many buyers: one query per level instead of per buyer.
     *
     * @param  Collection<int, User>  $buyers
     * @return array<int, PlaceCode> user id => the single place matching their most specific name
     */
    private function matchByBuyersNames(Collection $buyers): array
    {
        $levels = [PlaceCode::LevelUnion, PlaceCode::LevelUpazila, PlaceCode::LevelDistrict, PlaceCode::LevelDivision];
        $namesByBuyer = [];
        $keysByLevel = array_fill_keys($levels, []);
        foreach ($buyers as $buyer) {
            $names = [
                PlaceCode::LevelUnion => trim((string) ($buyer->profile['union'] ?? '')),
                PlaceCode::LevelUpazila => trim((string) ($buyer->profile['upazila'] ?? '')),
                PlaceCode::LevelDistrict => trim((string) ($buyer->district ?? '')),
                PlaceCode::LevelDivision => trim((string) ($buyer->division ?? '')),
            ];
            $namesByBuyer[(int) $buyer->id] = $names;
            foreach ($names as $level => $name) {
                if ($name !== '') {
                    $keysByLevel[$level][mb_strtolower($name)] = true;
                }
            }
        }

        /** @var array<string, array<string, Collection<int, PlaceCode>>> $placesByLevel */
        $placesByLevel = [];
        foreach ($keysByLevel as $level => $keys) {
            $placesByLevel[$level] = $keys === [] ? [] : PlaceCode::query()
                ->select('place_codes.*')
                ->selectRaw('LOWER(TRIM(name)) AS name_match_key')
                ->where('level', $level)
                ->where('is_active', true)
                ->whereIn(DB::raw('LOWER(TRIM(name))'), array_map('strval', array_keys($keys)))
                ->get()
                ->groupBy('name_match_key')
                ->all();
        }

        $matches = [];
        foreach ($namesByBuyer as $userId => $names) {
            foreach ($levels as $level) {
                if ($names[$level] === '') {
                    continue;
                }
                $found = $placesByLevel[$level][mb_strtolower($names[$level])] ?? null;
                if ($found !== null && $found->count() === 1) {
                    $matches[$userId] = $found->first();
                    break;
                }
            }
        }

        return $matches;
    }

    /**
     * Load every anchor's parent chain and active base-place chain up front so
     * expandFromAnchor() walks them without a query per step.
     *
     * @param  list<PlaceCode>  $anchors
     */
    private function primeChains(array $anchors): void
    {
        /** @var array<int, PlaceCode> $known */
        $known = [];
        $pending = $anchors;
        for ($depth = 0; $pending !== [] && $depth < 8; $depth++) {
            $missing = [];
            foreach ($pending as $place) {
                $known[(int) $place->id] ??= $place;
                if ($place->parent_id && ! isset($known[(int) $place->parent_id])) {
                    $missing[(int) $place->parent_id] = true;
                }
            }
            foreach ($missing === [] ? [] : PlaceCode::query()->whereIn('id', array_keys($missing))->get() as $parent) {
                $known[(int) $parent->id] = $parent;
            }
            $next = [];
            foreach ($pending as $place) {
                if (! $place->relationLoaded('parent')) {
                    $parent = $place->parent_id ? ($known[(int) $place->parent_id] ?? null) : null;
                    $place->setRelation('parent', $parent);
                    if ($parent && ! $parent->relationLoaded('parent')) {
                        $next[(int) $parent->id] = $parent;
                    }
                }
            }
            $pending = array_values($next);
        }

        /** @var array<int, PlaceCode|null> $activeBase */
        $activeBase = [];
        $pending = $anchors;
        for ($depth = 0; $pending !== [] && $depth < 8; $depth++) {
            $missing = [];
            foreach ($pending as $place) {
                if ($place->base_place_id && ! array_key_exists((int) $place->base_place_id, $activeBase)) {
                    $missing[(int) $place->base_place_id] = true;
                }
            }
            if ($missing !== []) {
                $found = PlaceCode::query()->whereIn('id', array_keys($missing))->where('is_active', true)->get()->keyBy('id');
                foreach (array_keys($missing) as $id) {
                    $activeBase[$id] = $found->get($id);
                }
            }
            $next = [];
            foreach ($pending as $place) {
                if (! $place->base_place_id || $place->relationLoaded('basePlace')) {
                    continue;
                }
                $base = $activeBase[(int) $place->base_place_id];
                $place->setRelation('basePlace', $base);
                if ($base && (int) $base->parent_id === (int) $place->id && ! $base->relationLoaded('basePlace')) {
                    $next[(int) $base->id] = $base;
                }
            }
            $pending = array_values($next);
        }
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
