<?php

namespace App\Domain\Accounts;

use App\Models\AreaDealerAssignment;
use App\Models\PlaceCode;
use App\Models\User;

/**
 * What a dealer or sub-dealer is appointed as, and where: "Rangpur District — district dealer".
 *
 * The appointment comes from the partner's place code (profile.place_code_id) or, for a dealer, the active
 * area seat the office gave them. It is separate from the personal address, which is only where the person
 * lives. Place names are Bangla on a Bangla screen; a name with no Bangla match stays as it was saved.
 */
final class PartnerAppointment
{
    /** Order used when a dealer holds more than one seat: the widest area speaks for them. */
    private const LevelOrder = [
        PlaceCode::LevelDivision => 0,
        PlaceCode::LevelDistrict => 1,
        PlaceCode::LevelUpazila => 2,
        PlaceCode::LevelUnion => 3,
    ];

    /**
     * @return array{kind: string|null, area: string|null, line: string|null, short: string|null, title: string|null, place: string|null}
     *                                                                                                                                    title is line 1 (the kind of dealer), place is line 2 (the place and its parents), line is both on one row for old readers.
     */
    public function for(User $user, ?string $locale = null): array
    {
        return $this->forPlace($user, $this->placeOf($user), $locale);
    }

    /**
     * The same answer for many partners with a fixed number of queries, for a list page.
     *
     * @param  iterable<User>  $users
     * @return array<int, array{kind: string|null, area: string|null, line: string|null, short: string|null, title: string|null, place: string|null}> keyed by user id
     */
    public function forMany(iterable $users, ?string $locale = null): array
    {
        $users = collect($users);
        $seatPlace = AreaDealerAssignment::query()
            ->whereIn('user_id', $users->where('role', 'dealer')->pluck('id'))
            ->where('is_active', true)
            ->whereNotNull('place_code_id')
            ->with('placeCode.parent.parent.parent')
            ->get()
            ->filter(fn (AreaDealerAssignment $seat): bool => $seat->placeCode !== null)
            ->sortBy(fn (AreaDealerAssignment $seat): int => self::LevelOrder[$seat->placeCode->level] ?? 9)
            ->groupBy('user_id')
            ->map(fn ($seats): PlaceCode => $seats->first()->placeCode);
        $ids = $users->map(fn (User $user): mixed => PartnerProfileLock::profileOf($user)['place_code_id'] ?? null)->filter(fn (mixed $id): bool => is_numeric($id))->map(fn (mixed $id): int => (int) $id)->unique();
        $homes = PlaceCode::query()->whereIn('id', $ids)->with('parent.parent.parent')->get()->keyBy('id');

        $out = [];
        foreach ($users as $user) {
            $home = $homes->get((int) (PartnerProfileLock::profileOf($user)['place_code_id'] ?? 0));
            $place = $seatPlace->get($user->id) ?? $home;
            $out[$user->id] = $this->forPlace($user, $place !== null && in_array($place->level, PlaceCode::AssignmentLevels, true) ? $place : null, $locale);
        }

        return $out;
    }

    /**
     * @return array{kind: string|null, area: string|null, line: string|null, short: string|null, title: string|null, place: string|null}
     *                                                                                                                                    title is line 1 (the kind of dealer), place is line 2 (the place and its parents), line is both on one row for old readers.
     */
    private function forPlace(User $user, ?PlaceCode $place, ?string $locale = null): array
    {
        $locale ??= app()->getLocale();
        if ($place === null) {
            return ['kind' => null, 'area' => null, 'line' => null, 'short' => null, 'title' => null, 'place' => null];
        }

        $isSub = $user->role === 'sub_dealer';
        $kind = $isSub ? 'sub_dealer' : $this->kindOf($place);
        $name = $this->nameOf($place, $locale);
        // A union or pourashava is told apart from a bare name so "Sadar" reads "Sadar Union" or "Sadar Pourashava".
        $placeKind = $this->kindOf($place);

        $short = __('cr21_identity.short_'.$kind, [], $locale);
        if ($name === null) {
            return ['kind' => $kind, 'area' => null, 'line' => $short, 'short' => $short, 'title' => $short, 'place' => null];
        }

        $area = $isSub ? $name.' '.__('cr21_identity.word_'.$placeKind, [], $locale) : $name;
        $placeLine = $this->placeLine($place, $placeKind, $locale);
        $line = $placeLine === null ? $short : __('appointment_lines.line', ['title' => $short, 'place' => $placeLine], $locale);

        return ['kind' => $kind, 'area' => $area, 'line' => $line, 'short' => $short, 'title' => $short, 'place' => $placeLine];
    }

    /**
     * Line 2: the place and the places above it, each with its level word ("Balia Union, Nababganj Upazila,
     * Dinajpur District, Rangpur Division"). A division or district stands alone. A parent without a usable name is skipped.
     */
    private function placeLine(PlaceCode $place, string $placeKind, string $locale): ?string
    {
        $own = $this->nameOf($place, $locale);
        if ($own === null) {
            return null;
        }
        if ($placeKind === PlaceCode::LevelDivision || $placeKind === PlaceCode::LevelDistrict) {
            return $own;
        }

        $raw = ['division' => null, 'district' => null, 'upazila' => null, 'union' => null];
        $cursor = $place;
        for ($guard = 0; $cursor !== null && $guard < 6; $guard++) {
            if (array_key_exists($cursor->level, $raw)) {
                $raw[$cursor->level] = $cursor->name;
            }
            $cursor = $cursor->parent;
        }
        $names = $locale === 'bn' ? BanglaPlaceNames::translate($raw) : $raw;

        $parts = [];
        foreach ([PlaceCode::LevelUnion => $placeKind, PlaceCode::LevelUpazila => 'upazila', PlaceCode::LevelDistrict => 'district', PlaceCode::LevelDivision => 'division'] as $level => $word) {
            if ($level === PlaceCode::LevelUnion && $place->level !== PlaceCode::LevelUnion) {
                continue;
            }
            $name = $level === $place->level ? $own : $this->bare($names[$level] ?? null);
            if ($name === null || PlaceCode::isPlaceholderLabel($name)) {
                continue;
            }
            $parts[] = $name.' '.__('cr21_identity.word_'.$word, [], $locale);
        }

        return $parts === [] ? null : implode(__('appointment_lines.separator', [], $locale), $parts);
    }

    /** A place name without its trailing level word, or null when nothing is left. */
    private function bare(?string $name): ?string
    {
        $name = trim((string) preg_replace('/\s*(pourashava|paurashava|municipality|union|upazila|thana|district|division|ইউনিয়ন|পৌরসভা|উপজেলা|থানা|জেলা|বিভাগ)\s*$/iu', '', trim((string) $name)));

        return $name === '' ? null : $name;
    }

    /** The place a partner is appointed to: the active seat first, then the place code on the profile. */
    private function placeOf(User $user): ?PlaceCode
    {
        if ($user->role === 'dealer') {
            $seats = AreaDealerAssignment::query()
                ->where('user_id', $user->id)
                ->where('is_active', true)
                ->whereNotNull('place_code_id')
                ->with('placeCode.parent.parent.parent')
                ->get()
                ->map(fn (AreaDealerAssignment $seat): ?PlaceCode => $seat->placeCode)
                ->filter()
                ->sortBy(fn (PlaceCode $place): int => self::LevelOrder[$place->level] ?? 9);
            if ($seats->isNotEmpty()) {
                return $seats->first();
            }
        }

        $profile = PartnerProfileLock::profileOf($user);
        $id = $profile['place_code_id'] ?? null;
        $place = is_numeric($id) ? PlaceCode::query()->with('parent.parent.parent')->find((int) $id) : null;
        if ($place === null && is_string($profile['place_code'] ?? null) && trim($profile['place_code']) !== '') {
            $place = PlaceCode::findByCode($profile['place_code'], false);
        }

        return $place !== null && in_array($place->level, PlaceCode::AssignmentLevels, true) ? $place : null;
    }

    /** division, district, upazila, union or pourashava (a municipality is a "union" place in the code list). */
    private function kindOf(PlaceCode $place): string
    {
        if ($place->level === PlaceCode::LevelUnion && preg_match('/pourashava|paurashava|poura|municipal|পৌর/iu', (string) $place->name) === 1) {
            return 'pourashava';
        }

        return $place->level;
    }

    /** The place's own name in the screen language, with a trailing "Pourashava"/"Union" word removed. */
    private function nameOf(PlaceCode $place, string $locale): ?string
    {
        $name = trim((string) $place->name);
        if (PlaceCode::isPlaceholderLabel($name)) {
            return null;
        }
        $name = trim((string) preg_replace('/\s*(pourashava|paurashava|municipality|union|upazila|thana|district|division|ইউনিয়ন|পৌরসভা|উপজেলা|থানা|জেলা|বিভাগ)\s*$/iu', '', $name));
        if ($name === '') {
            return null;
        }
        if ($locale !== 'bn') {
            return $name;
        }
        $level = in_array($place->level, [PlaceCode::LevelUnion, PlaceCode::LevelUpazila, PlaceCode::LevelDistrict, PlaceCode::LevelDivision], true) ? $place->level : null;
        if ($level === null) {
            return $name;
        }
        $names = ['division' => null, 'district' => null, 'upazila' => null, 'union' => null];
        $cursor = $place;
        $guard = 0;
        while ($cursor !== null && $guard++ < 6) {
            if (array_key_exists($cursor->level, $names)) {
                // The full name ("Saidpur Pourashava") lets the lookup find pourashavas; the level word is dropped after.
                $names[$cursor->level] = $cursor->name;
            }
            $cursor = $cursor->parent;
        }

        // The Bangla list may carry the level word itself ("সৈয়দপুর পৌরসভা"); the line adds it, so drop it here.
        $bangla = (string) (BanglaPlaceNames::translate($names)[$level] ?? $name);
        $bangla = trim((string) preg_replace('/\s*(pourashava|paurashava|municipality|union|upazila|thana|district|division|ইউনিয়ন|পৌরসভা|উপজেলা|থানা|জেলা|বিভাগ)\s*$/iu', '', $bangla));

        return $bangla !== '' ? $bangla : $name;
    }
}
