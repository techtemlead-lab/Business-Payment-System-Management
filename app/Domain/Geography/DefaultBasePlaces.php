<?php

namespace App\Domain\Geography;

use App\Models\PlaceCode;

/**
 * Works out the default base place of each division, district and upazila from the place list.
 *
 * A base place is the one place directly below another whose last code is used when a buyer at
 * the higher level is placed in the code chain (see PlaceGeographyResolver::expandFromAnchor).
 * The default is the place's headquarters, found from names in the list, never from guesses:
 *
 * - division: the district with the division's name (Dhaka division, Dhaka district).
 * - district: its Sadar upazila ("Comilla Sadar" for Cumilla); where the district has no Sadar
 *   upazila but one metropolitan area (Dhaka, Chattogram, Khulna, Rajshahi), that area.
 * - upazila: the union-level place with its name; when several carry it, the city corporation,
 *   then the pourashava, then the union ("Zajira Pourashava" before "Zajira Sadar"). An upazila
 *   with only one child takes that child.
 *
 * Anything else is left empty and reported with a reason, for the office to choose.
 */
final class DefaultBasePlaces
{
    public const ReasonNoMatch = 'no_match';

    public const ReasonSeveral = 'several_matches';

    public const ReasonNoChildren = 'no_children';

    /** Every union under the upazila is listed twice or under the wrong upazila (PlaceListChecks). */
    public const ReasonUnionsNeedReview = 'unions_need_review';

    /** Chosen, but worth a look: the only child, or one of several places carrying the upazila's name. */
    public const NoteOnlyChild = 'only_child';

    public const NoteOnlyPourashava = 'only_pourashava';

    public const NoteHeadquarters = 'headquarters_union';

    public const NoteSpellingVariant = 'named_like_upazila_other_spelling';

    public const ReasonSeveralPourashavas = 'several_pourashavas';

    public const ReasonHeadquartersNotMatched = 'headquarters_not_matched';

    public const NotePreferred = 'preferred_over_same_name';

    /** @param  array<string, string>  $headquarters Upazila code => the headquarters union as a source writes it */
    public function __construct(private readonly array $headquarters = [], private readonly UnionNameMatcher $matcher = new UnionNameMatcher) {}

    private const Suffix = '/(citycorporation|pourashava|paurashava|municipality|union|sadar|city|metro)$/';

    /** Lower rank is picked first when several union-level places carry the upazila's name. */
    private const KindRank = ['city_corporation' => 0, 'pourashava' => 1, 'union' => 2];

    /**
     * @param  list<array{code: string, level: string, name: string, parent_code: ?string, kind?: string}>  $places
     * @return array{links: list<array{code: string, base_code: string}>, empty: list<array{code: string, level: string, name: string, reason: string, candidates: list<string>, parent: string}>, rules: array<string, string>}
     */
    public function derive(array $places): array
    {
        $children = [];
        $names = [];
        foreach ($places as $place) {
            $names[$place['code']] = $place['name'];
            if ($place['parent_code'] !== null) {
                $children[$place['parent_code']][] = $place;
            }
        }

        $checks = new PlaceListChecks;
        $excluded = $checks->excludedUnionCodes($checks->review($places));
        $links = [];
        $empty = [];
        $rules = [];
        foreach ($places as $place) {
            if (! in_array($place['level'], [PlaceCode::LevelDivision, PlaceCode::LevelDistrict, PlaceCode::LevelUpazila], true)) {
                continue;
            }
            // Metropolitan areas are typed upazila in the list but are not upazilas: no base place for them.
            if ($place['level'] === PlaceCode::LevelUpazila && ($place['kind'] ?? 'upazila') !== 'upazila') {
                continue;
            }
            $kids = $children[$place['code']] ?? [];
            $allKids = $kids;
            if ($place['level'] === PlaceCode::LevelUpazila) {
                // Unions that are listed twice or under the wrong upazila are never chosen (see PlaceListChecks).
                $kids = array_values(array_filter($kids, fn (array $k): bool => ! isset($excluded[$k['code']])));
            }
            [$base, $reason] = match ($place['level']) {
                PlaceCode::LevelDivision => $this->divisionBase($place, $kids),
                PlaceCode::LevelDistrict => $this->districtBase($place, $kids),
                default => $this->upazilaBase($place, $kids),
            };
            $someLeftOut = count($kids) !== count($allKids);
            if ($reason === self::ReasonNoChildren && $allKids !== []) {
                $reason = self::ReasonUnionsNeedReview;
            } elseif ($someLeftOut && $base !== null && in_array($reason, [self::NoteOnlyChild, self::NoteOnlyPourashava], true)) {
                // Only child / only municipality is not certain when listed-twice unions were left out.
                [$base, $reason] = [null, self::ReasonUnionsNeedReview];
            }
            if ($base !== null) {
                $links[] = ['code' => $place['code'], 'base_code' => $base['code']];
                $rules[$place['code']] = $this->ruleLabel($place, $base, $reason);
            } else {
                $empty[] = ['code' => $place['code'], 'level' => $place['level'], 'name' => $place['name'], 'reason' => $reason ?? self::ReasonNoMatch, 'candidates' => array_map(fn (array $k): string => (string) $k['name'], $kids), 'parent' => (string) ($names[(string) $place['parent_code']] ?? '')];
            }
        }

        return ['links' => $links, 'empty' => $empty, 'rules' => $rules];
    }

    /**
     * Which rule picked the base, for the review lists.
     *
     * @param  array<string, mixed>  $place
     * @param  array<string, mixed>  $base
     */
    private function ruleLabel(array $place, array $base, ?string $note): string
    {
        return match ($place['level']) {
            PlaceCode::LevelDivision => 'division_same_name_district',
            PlaceCode::LevelDistrict => ($base['kind'] ?? '') === 'metro_upazila' ? 'district_metro_area' : 'district_sadar_upazila',
            default => $note === self::NoteOnlyChild
                ? 'upazila_only_child'
                : ($note === self::NoteOnlyPourashava ? 'upazila_only_pourashava'
                : ($note === self::NoteHeadquarters ? 'upazila_headquarters_union'
                : ($note === self::NoteSpellingVariant ? 'upazila_union_named_like_upazila_other_spelling'
                : 'upazila_'.$this->kindOf($base).'_named_like_upazila'.($note === self::NotePreferred ? '_preferred_over_same_name' : '')))),
        };
    }

    /** @param  array<string, mixed>  $place */
    private function kindOf(array $place): string
    {
        return match ($this->rank($place)) {
            0 => 'city_corporation',
            1 => 'pourashava',
            default => 'union',
        };
    }

    /**
     * @param  array<string, mixed>  $division
     * @param  list<array<string, mixed>>  $districts
     * @return array{0: ?array<string, mixed>, 1: ?string}
     */
    private function divisionBase(array $division, array $districts): array
    {
        $stem = $this->stem((string) $division['name']);

        return $this->only($districts, fn (array $d): bool => $this->stem((string) $d['name']) === $stem);
    }

    /**
     * @param  array<string, mixed>  $district
     * @param  list<array<string, mixed>>  $upazilas
     * @return array{0: ?array<string, mixed>, 1: ?string}
     */
    private function districtBase(array $district, array $upazilas): array
    {
        $stem = $this->stem((string) $district['name']);
        $sadar = array_values(array_filter($upazilas, fn (array $u): bool => ($u['kind'] ?? 'upazila') === 'upazila'
            && str_ends_with($this->key((string) $u['name']), 'sadar')
            && $this->stem((string) $u['name']) === $stem));
        if ($sadar !== []) {
            return $this->only($sadar, fn (): bool => true);
        }

        return $this->only($upazilas, fn (array $u): bool => ($u['kind'] ?? '') === 'metro_upazila');
    }

    /**
     * @param  array<string, mixed>  $upazila
     * @param  list<array<string, mixed>>  $unions
     * @return array{0: ?array<string, mixed>, 1: ?string}
     */
    private function upazilaBase(array $upazila, array $unions): array
    {
        if ($unions === []) {
            return [null, self::ReasonNoChildren];
        }
        $stem = $this->stem((string) $upazila['name']);
        $named = array_values(array_filter($unions, fn (array $u): bool => $this->stem((string) $u['name']) === $stem));
        if ($named !== []) {
            $best = min(array_map(fn (array $u): int => $this->rank($u), $named));

            $top = array_values(array_filter($named, fn (array $u): bool => $this->rank($u) === $best));
            [$base, $reason] = $this->only($top, fn (): bool => true);

            return [$base, $base !== null && count($named) > 1 ? self::NotePreferred : $reason];
        }

        if (count($unions) === 1) {
            return [$unions[0], self::NoteOnlyChild];
        }

        // Exactly one municipality among the children is the upazila's main one.
        $pourashavas = array_values(array_filter($unions, fn (array $u): bool => $this->isPourashava($u)));
        if (count($pourashavas) === 1) {
            return [$pourashavas[0], self::NoteOnlyPourashava];
        }
        // The union or municipality that hosts the upazila headquarters, when the headquarters file names it
        // (also for two municipalities) and it matches exactly one of ours.
        $headquarters = $this->headquarters[(string) $upazila['code']] ?? null;
        if ($headquarters !== null) {
            $names = array_map(fn (array $u): string => (string) $u['name'], $unions);
            $found = $this->matcher->find($headquarters, $names)['match'];
            if ($found === null) {
                return [null, self::ReasonHeadquartersNotMatched];
            }
            foreach ($unions as $union) {
                if ($union['name'] === $found) {
                    return [$union, self::NoteHeadquarters];
                }
            }
        }

        if (count($pourashavas) > 1) {
            return [null, self::ReasonSeveralPourashavas];
        }

        // Last resort, after the only child, the only municipality and a source-named headquarters: the union that
        // carries the upazila's name in another spelling (Gangachara / Gongachora), found with UnionNameMatcher;
        // two hits choose nothing.
        $variant = $this->matcher->find((string) $upazila['name'], array_map(fn (array $u): string => (string) $u['name'], $unions));
        if ($variant['match'] !== null) {
            foreach ($unions as $union) {
                if ($union['name'] === $variant['match']) {
                    return [$union, self::NoteSpellingVariant];
                }
            }
        }

        return [null, self::ReasonNoMatch];
    }

    /**
     * @param  list<array<string, mixed>>  $candidates
     * @param  callable(array<string, mixed>): bool  $matches
     * @return array{0: ?array<string, mixed>, 1: ?string}
     */
    private function only(array $candidates, callable $matches): array
    {
        $found = array_values(array_filter($candidates, $matches));

        return match (count($found)) {
            0 => [null, self::ReasonNoMatch],
            1 => [$found[0], null],
            default => [null, self::ReasonSeveral],
        };
    }

    /** @param  array<string, mixed>  $place */
    private function isPourashava(array $place): bool
    {
        return ($place['kind'] ?? '') === 'pourashava' || (bool) preg_match('/(pourashava|paurashava|municipality)$/', $this->key((string) $place['name']));
    }

    /** @param  array<string, mixed>  $place */
    private function rank(array $place): int
    {
        $kind = (string) ($place['kind'] ?? '');
        if (! isset(self::KindRank[$kind])) {
            $kind = str_contains($this->key((string) $place['name']), 'pourashava') ? 'pourashava' : 'union';
        }

        return self::KindRank[$kind];
    }

    private function key(string $name): string
    {
        return (string) preg_replace('/[^\p{L}\p{N}]+/u', '', mb_strtolower($name));
    }

    /** The name without a trailing "Sadar", "Pourashava", "City Corporation"… and with older spellings read as today's. */
    private function stem(string $name): string
    {
        $key = (string) preg_replace(self::Suffix, '', $this->key($name));

        return DefaultPlaceCatalog::NameAliases[$key] ?? $key;
    }
}
