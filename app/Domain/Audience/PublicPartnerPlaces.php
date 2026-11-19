<?php

namespace App\Domain\Audience;

use App\Domain\Accounts\BanglaPlaceNames;
use App\Models\AreaDealerAssignment;
use App\Models\PlaceCode;
use Illuminate\Validation\ValidationException;

/**
 * The place lists on the public application form: division, district, upazila and union picked from the
 * same list the office uses, so the name an applicant picks is the name staff approve.
 */
final class PublicPartnerPlaces
{
    /** @var list<string> */
    private const Levels = [PlaceCode::LevelDivision, PlaceCode::LevelDistrict, PlaceCode::LevelUpazila, PlaceCode::LevelUnion];

    /**
     * @return list<array{id: int, name: string, held: bool}>
     */
    public function children(string $level, ?int $parentId): array
    {
        abort_unless(in_array($level, self::Levels, true), 422);
        $places = PlaceCode::query()
            ->where('level', $level)
            ->where('is_active', true)
            ->whereNotNull('code')
            ->when($level !== PlaceCode::LevelDivision, fn ($query) => $query->where('parent_id', $parentId ?? 0))
            ->orderBy('sort_order')
            ->orderBy('name')
            ->limit(600)
            ->get(['id', 'code', 'name', 'level', 'parent_id', 'is_active'])
            ->filter(fn (PlaceCode $place): bool => $place->isReadyForAssignment());
        // A dealer holds a whole upazila, so the form can say so before the applicant submits.
        $held = $level === PlaceCode::LevelUpazila
            ? AreaDealerAssignment::query()->where('is_active', true)->whereIn('place_code_id', $places->pluck('id'))->pluck('place_code_id')->flip()
            : collect();

        // On Bangla pages the lists show Bangla names (B6); the saved application keeps the English name.
        $above = $level !== PlaceCode::LevelDivision && $parentId !== null ? $this->namesAbove($parentId) : [];
        $bangla = app()->getLocale() === 'bn';

        return $places->map(fn (PlaceCode $place): array => [
            'id' => (int) $place->id,
            'name' => $bangla ? BanglaPlaceNames::level($level, PlaceCode::displayLabel($place->name), $above) : PlaceCode::displayLabel($place->name),
            'held' => $held->has($place->id),
        ])->values()->all();
    }

    /**
     * The English names of a place and the places above it, by level.
     *
     * @return array<string, string>
     */
    private function namesAbove(int $placeId): array
    {
        $names = [];
        for ($place = PlaceCode::query()->find($placeId, ['id', 'name', 'level', 'parent_id']), $guard = 0; $place !== null && $guard < 6; $guard++) {
            if (in_array($place->level, self::Levels, true)) {
                $names[$place->level] = PlaceCode::displayLabel($place->name);
            }
            $place = $place->parent_id !== null ? PlaceCode::query()->find($place->parent_id, ['id', 'name', 'level', 'parent_id']) : null;
        }

        return $names;
    }

    /**
     * Checks the picked places belong to each other, and gives their names for the application.
     *
     * @param  array<string, mixed>  $ids  division_place_id, district_place_id, upazila_place_id, union_place_id
     * @return array{division: string, district: string, upazila: ?string, union: ?string, division_place_id: int, district_place_id: int, upazila_place_id: ?int, union_place_id: ?int}
     */
    public function resolve(array $ids): array
    {
        $chain = [];
        $parent = null;
        foreach ([PlaceCode::LevelDivision, PlaceCode::LevelDistrict, PlaceCode::LevelUpazila, PlaceCode::LevelUnion] as $level) {
            $id = $ids[$level.'_place_id'] ?? null;
            if (blank($id)) {
                if (in_array($level, [PlaceCode::LevelDivision, PlaceCode::LevelDistrict], true)) {
                    throw ValidationException::withMessages([$level.'_place_id' => __('validation.required', ['attribute' => __('site.'.$level)])]);
                }

                continue;
            }
            $place = PlaceCode::query()->whereKey((int) $id)->where('level', $level)->where('is_active', true)->first();
            if (! $place || ($parent !== null && (int) $place->parent_id !== (int) $parent->id)) {
                throw ValidationException::withMessages([$level.'_place_id' => __('joining.err_place_not_in_list')]);
            }
            $chain[$level] = $place;
            $parent = $place;
        }
        if (isset($chain[PlaceCode::LevelUnion]) && ! isset($chain[PlaceCode::LevelUpazila])) {
            throw ValidationException::withMessages(['upazila_place_id' => __('joining.err_place_not_in_list')]);
        }

        return [
            'division' => PlaceCode::displayLabel($chain[PlaceCode::LevelDivision]->name),
            'district' => PlaceCode::displayLabel($chain[PlaceCode::LevelDistrict]->name),
            'upazila' => isset($chain[PlaceCode::LevelUpazila]) ? PlaceCode::displayLabel($chain[PlaceCode::LevelUpazila]->name) : null,
            'union' => isset($chain[PlaceCode::LevelUnion]) ? PlaceCode::displayLabel($chain[PlaceCode::LevelUnion]->name) : null,
            'division_place_id' => (int) $chain[PlaceCode::LevelDivision]->id,
            'district_place_id' => (int) $chain[PlaceCode::LevelDistrict]->id,
            'upazila_place_id' => isset($chain[PlaceCode::LevelUpazila]) ? (int) $chain[PlaceCode::LevelUpazila]->id : null,
            'union_place_id' => isset($chain[PlaceCode::LevelUnion]) ? (int) $chain[PlaceCode::LevelUnion]->id : null,
        ];
    }
}
