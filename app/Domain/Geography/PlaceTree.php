<?php

namespace App\Domain\Geography;

use App\Models\PlaceCode;
use Illuminate\Contracts\Database\Query\Builder;
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
