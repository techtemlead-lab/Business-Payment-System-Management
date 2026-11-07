<?php

namespace App\Domain\Geography;

use App\Models\PlaceCode;
use App\Models\User;
use Illuminate\Contracts\Database\Query\Builder;
use Illuminate\Database\Eloquent\Builder as EloquentBuilder;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * Place-code-id questions about the place tree ("is this place inside that one?",
 * "which partners live inside these places?"), answered by place code ids only.
 * Names are never used to match: "Dhaka" can be a division, a district or an upazila.
 */
final class PlaceTree
{
    /**
     * SQL for a partner's place code id as saved on their profile, or NULL when missing.
     */
    public static function partnerPlaceIdSql(string $table = 'users'): string
    {
        return "(CASE WHEN ({$table}.profile->>'place_code_id') ~ '^[0-9]+$' THEN ({$table}.profile->>'place_code_id')::bigint END)";
    }

    /**
     * Keep only partners whose place code is one of these places or lies inside one of them.
     *
     * @param  list<int>  $rootIds
     */
    public static function whereInside(Builder $query, array $rootIds, string $table = 'users'): void
    {
        $rootIds = array_values(array_unique(array_map('intval', $rootIds)));
        if ($rootIds === []) {
            $query->whereRaw('1 = 0');

            return;
        }
        $placeholders = implode(', ', array_fill(0, count($rootIds), '?'));
        $query->whereRaw(self::partnerPlaceIdSql($table).' IN (
            WITH RECURSIVE inside AS (
                SELECT id FROM place_codes WHERE id IN ('.$placeholders.')
                UNION
                SELECT child.id FROM place_codes AS child JOIN inside ON child.parent_id = inside.id
            )
            SELECT id FROM inside
        )', $rootIds);
    }

    /**
     * The active, ready division/district/upazila/union place for a typed place code.
     * Every place-bearing record (branch, area seat, staff territory, partner) needs one.
     *
     * @throws ValidationException
     */
    public static function requirePlace(mixed $code, string $field = 'place_code'): PlaceCode
    {
        $value = trim((string) (is_scalar($code) ? $code : ''));
        if ($value === '') {
            throw ValidationException::withMessages([$field => __('portal.place_code_required')]);
        }
        $place = PlaceCode::findByCode($value);
        if (! $place) {
            throw ValidationException::withMessages([$field => __('portal.acct_val_place_unknown')]);
        }
        if (! in_array($place->level, PlaceCode::AssignmentLevels, true)) {
            throw ValidationException::withMessages([$field => __('portal.place_code_level_needed')]);
        }
        if (! $place->isReadyForAssignment()) {
            throw ValidationException::withMessages([$field => __('portal.err_pc_placeholder_assignment')]);
        }

        return $place;
    }

    /**
     * The divisions (and their districts) that the given partners' place codes lie in,
     * for filter pickers: values are place code ids, labels are the names of those codes.
     *
     * @param  EloquentBuilder<User>  $partners
     * @return list<array{division_id: int, division: string, districts: list<array{id: int, name: string}>}>
     */
    public static function divisionAndDistrictOptions(EloquentBuilder $partners): array
    {
        $starts = $partners->clone()->reorder()->select(DB::raw(self::partnerPlaceIdSql('users').' AS place_id'))->toBase();
        $rows = DB::select('WITH RECURSIVE starts AS ('.$starts->toSql().'), chain AS (
                SELECT place.id AS start_id, place.id, place.parent_id, place.level, place.name, 0 AS depth
                FROM place_codes AS place
                WHERE place.id IN (SELECT place_id FROM starts)
                UNION ALL
                SELECT chain.start_id, parent.id, parent.parent_id, parent.level, parent.name, chain.depth + 1
                FROM chain
                JOIN place_codes AS parent ON parent.id = chain.parent_id
                WHERE chain.depth < 8
            )
            SELECT DISTINCT division.id AS division_id, division.name AS division_name, district.id AS district_id, district.name AS district_name
            FROM chain AS division
            LEFT JOIN chain AS district ON district.start_id = division.start_id AND district.level = ?
            WHERE division.level = ?
            ORDER BY division_name, division_id, district_name', [...$starts->getBindings(), PlaceCode::LevelDistrict, PlaceCode::LevelDivision]);

        $options = [];
        foreach ($rows as $row) {
            $divisionId = (int) $row->division_id;
            $options[$divisionId] ??= ['division_id' => $divisionId, 'division' => (string) $row->division_name, 'districts' => []];
            if ($row->district_id !== null) {
                $options[$divisionId]['districts'][] = ['id' => (int) $row->district_id, 'name' => (string) $row->district_name];
            }
        }

        return array_values($options);
    }

    /**
     * A positive place code id from a filter value, or null (names and junk are ignored).
     */
    public static function idFrom(mixed $value): ?int
    {
        $id = filter_var($value, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]);

        return $id === false ? null : $id;
    }

    /**
     * The place itself and every place above it, nearest first.
     *
     * @return list<int>
     */
    public function selfAndAncestorIds(int $placeId): array
    {
        $ids = [];
        $cursor = $placeId;
        for ($guard = 0; $cursor !== null && $guard < 8; $guard++) {
            $ids[] = $cursor;
            $parent = DB::table('place_codes')->where('id', $cursor)->value('parent_id');
            $cursor = $parent !== null ? (int) $parent : null;
        }

        return $ids;
    }

    /**
     * The division a place lies in (the place itself when it is a division), by place code id.
     */
    public function divisionOf(?int $placeId): ?PlaceCode
    {
        if ($placeId === null) {
            return null;
        }

        return PlaceCode::query()
            ->whereIn('id', $this->selfAndAncestorIds($placeId))
            ->where('level', PlaceCode::LevelDivision)
            ->first();
    }

    /**
     * The id of the division a place lies in (itself when it is a division), in one query.
     */
    public static function divisionIdOf(?int $placeId): ?int
    {
        if ($placeId === null) {
            return null;
        }
        $row = DB::selectOne('WITH RECURSIVE chain AS (
                SELECT id, parent_id, level, 0 AS depth FROM place_codes WHERE id = ?
                UNION ALL
                SELECT parent.id, parent.parent_id, parent.level, chain.depth + 1
                FROM chain JOIN place_codes AS parent ON parent.id = chain.parent_id
                WHERE chain.depth < 8
            )
            SELECT id FROM chain WHERE level = ? ORDER BY depth LIMIT 1', [$placeId, PlaceCode::LevelDivision]);

        return $row !== null ? (int) $row->id : null;
    }

    /**
     * Whether the place is one of the roots or lies inside one of them.
     *
     * @param  list<int>  $rootIds
     */
    public function isInside(int $placeId, array $rootIds): bool
    {
        return array_intersect($this->selfAndAncestorIds($placeId), array_map('intval', $rootIds)) !== [];
    }

    /**
     * A place's names from the division down to its own level, for display only.
     *
     * @return array<string, string>
     */
    public function namesDownTo(PlaceCode $place): array
    {
        $names = [];
        foreach ($place->loadMissing('parent.parent.parent')->pathNames() as $level => $name) {
            if ($name !== null && trim($name) !== '') {
                $names[$level] = $name;
            }
            if ($level === $place->level) {
                break;
            }
        }

        return $names;
    }
}
