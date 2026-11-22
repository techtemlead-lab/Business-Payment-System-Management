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
 *
 * A partner added with the four area places picked (client, 2026-11-22) has them on the profile
 * under "area_chain": the area commission of his own purchases then follows that chain, not the
 * base places below his seat. Older accounts without it keep the base-place chain. The chain is
 * read when an order is posted, so orders already posted keep the geography they were posted with.
 */
final class PlaceGeographyResolver
{
    /** The profile key holding the picked area places: level => {id, code, name}. */
    public const AreaChainKey = 'area_chain';

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
        if (! $anchor) {
            return $this->unplacedGeography();
        }
        $chainIds = self::areaChainIds($buyer);
        $chainPlaces = $chainIds === [] ? collect() : PlaceCode::query()->whereIn('id', array_values($chainIds))->where('is_active', true)->get()->keyBy('id');
        $chosen = $this->chosenAreaPlace($anchor, $chainIds, $chainPlaces);

        return $chosen ? $this->expandFromAnchor($chosen, 'area_chain') : $this->expandFromAnchor($anchor, 'place_code');
    }

    /**
     * The ids of the four places picked on the add-partner form (or in the account editor) for
     * the area commission of the partner's own purchases, deepest first. Empty for older accounts.
     *
     * @return array<string, int> level => place id, union first
     */
    public static function areaChainIds(User $user): array
    {
        $chain = data_get($user->profile, self::AreaChainKey);
        if (! is_array($chain)) {
            return [];
        }
        $ids = [];
        foreach ([PlaceCode::LevelUnion, PlaceCode::LevelUpazila, PlaceCode::LevelDistrict, PlaceCode::LevelDivision] as $level) {
            $id = filter_var(data_get($chain, $level.'.id'), FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]);
            if ($id !== false) {
                $ids[$level] = $id;
            }
        }

        return $ids;
    }

    /**
     * The deepest picked place that is still active, coded and of its own level, when it lies on
     * or below the partner's seat (anchor). Null sends the buyer back to the default base-place
     * chain: no pick (older accounts), every pick switched off, or a pick left behind by a later
     * place-code change, so a stale pick can never pay another area.
     *
     * @param  array<string, int>  $chainIds  level => id, deepest first
     * @param  Collection<int, PlaceCode>  $chainPlaces  active places by id
     */
    private function chosenAreaPlace(PlaceCode $anchor, array $chainIds, Collection $chainPlaces): ?PlaceCode
    {
        foreach ($chainIds as $level => $id) {
            $place = $chainPlaces->get($id);
            if (! $place || $place->level !== $level || ! $place->isReadyForAssignment()) {
                continue;
            }
            $cursor = $place;
            $guard = 0;
            while ($cursor && $guard++ < 8) {
                if ((int) $cursor->id === (int) $anchor->id) {
                    return $place;
                }
                $cursor = $cursor->parent;
            }

            return null;
        }

        return null;
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
        /** @var array<int, array<string, int>> $chainIds */
        $chainIds = [];
        foreach ($buyers as $buyer) {
            $ids = isset($anchors[(int) $buyer->id]) ? self::areaChainIds($buyer) : [];
            if ($ids !== []) {
                $chainIds[(int) $buyer->id] = $ids;
            }
        }
        $chainPlaces = $chainIds === [] ? collect() : PlaceCode::query()
            ->whereIn('id', array_values(array_unique(array_merge(...array_values(array_map('array_values', $chainIds))))))
            ->where('is_active', true)
            ->get()
            ->keyBy('id');
        $this->primeChains([...array_values($anchors), ...$chainPlaces->values()->all()]);

        $geographies = [];
        foreach ($buyers as $buyer) {
            $id = (int) $buyer->id;
            if (! isset($anchors[$id])) {
                $geographies[$id] = $this->unplacedGeography();

                continue;
            }
            $chosen = isset($chainIds[$id]) ? $this->chosenAreaPlace($anchors[$id], $chainIds[$id], $chainPlaces) : null;
            $geographies[$id] = $chosen
                ? $this->expandFromAnchor($chosen, 'area_chain')
                : $this->expandFromAnchor($anchors[$id], 'place_code');
        }

        return $geographies;
    }

    /**
     * Walk parents upward and base_place_id downward to fill the full serial path.
     * A base place with no code yet is skipped: its level stays empty, so nothing is
     * paid or saved against it, and the walk carries on to any coded place below it.
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
            if ($cursor === $anchor || $cursor->isReadyForAssignment()) {
                $this->writeLevel($geography, $cursor);
            }
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
        $value = filter_var(data_get($user->profile, 'place_code_id'), FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]);

        return $value === false ? null : $value;
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

        /** @var array<int, list<string>> $profileCodes Each buyer's code in every stored spelling, current form first */
        $profileCodes = [];
        foreach ($buyers as $buyer) {
            if (isset($anchors[(int) $buyer->id])) {
                continue;
            }
            $profileCode = trim((string) ($buyer->profile['place_code'] ?? $buyer->profile['area_code'] ?? ''));
            $forms = $profileCode !== '' ? PlaceCode::storedCodeForms($profileCode) : [];
            if ($forms !== []) {
                $profileCodes[(int) $buyer->id] = $forms;
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
        foreach ($profileCodes as $userId => $forms) {
            $place = null;
            foreach ($forms as $form) {
                $place ??= $placesByCode->get($form)?->first();
            }
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
