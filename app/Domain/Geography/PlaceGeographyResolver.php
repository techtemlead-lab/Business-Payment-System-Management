<?php

namespace App\Domain\Geography;

use App\Models\AreaDealerAssignment;
use App\Models\PlaceCode;
use App\Models\User;
use Illuminate\Support\Collection;

/**
 * Resolves purchase geography from place codes and the designated base-place chain
 * ("last serial") so area commissions are matched by code, never by free-text names.
 * A buyer with no usable place code gets an empty "unplaced" geography: their area
 * shares go to the company reserve instead of guessing a place from a name such as
 * "Dhaka", which can be a division, a district or an upazila.
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
        $anchor = $this->anchorPlaceForBuyer($buyer);

        return $anchor ? $this->expandFromAnchor($anchor, 'place_code') : $this->unplacedGeography();
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
        $this->primeChains(array_values($anchors));

        $geographies = [];
        foreach ($buyers as $buyer) {
            $id = (int) $buyer->id;
            $geographies[$id] = isset($anchors[$id])
                ? $this->expandFromAnchor($anchors[$id], 'place_code')
                : $this->unplacedGeography();
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

        $profilePlaceId = self::profilePlaceId($buyer);
        if ($profilePlaceId !== null) {
            $place = PlaceCode::query()->whereKey($profilePlaceId)->where('is_active', true)->first();
            if ($place && $place->level !== PlaceCode::LevelManagement) {
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

    /** The place code id saved on a partner's profile, when it is a usable number. */
    public static function profilePlaceId(User $user): ?int
    {
        $value = is_array($user->profile) ? ($user->profile['place_code_id'] ?? null) : null;
        if (is_int($value) || (is_string($value) && ctype_digit($value))) {
            return (int) $value > 0 ? (int) $value : null;
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

        $profileIds = [];
        foreach ($buyers as $buyer) {
            $placeId = isset($anchors[(int) $buyer->id]) ? null : self::profilePlaceId($buyer);
            if ($placeId !== null) {
                $profileIds[(int) $buyer->id] = $placeId;
            }
        }
        $placesById = $profileIds === [] ? collect() : PlaceCode::query()
            ->whereIn('id', array_values(array_unique($profileIds)))
            ->where('is_active', true)
            ->get()
            ->keyBy('id');
        foreach ($profileIds as $userId => $placeId) {
            $place = $placesById->get($placeId);
            if ($place && $place->level !== PlaceCode::LevelManagement) {
                $anchors[$userId] = $place;
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

    /**
     * The geography of a buyer with no usable place code: no names and no ids,
     * so nothing downstream can match it by name.
     *
     * @return array<string, mixed>
     */
    private function unplacedGeography(): array
    {
        return [...$this->emptyGeography(), 'resolution' => 'unplaced'];
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
