<?php

use App\Models\PlaceCode;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Safely converts the old PlaceCodeSeeder geography (MNHC-100..108 /
 * legacy 100..108 with fixed names) into blank admin-fill placeholders when
 * those rows are unused. Leaves live trees (districts/upazilas/assignments)
 * untouched.
 */
return new class extends Migration
{
    /** @var array<string, array{level:string,name:string,sort_order:int}> */
    private const LegacySeed = [
        '100' => ['level' => PlaceCode::LevelManagement, 'name' => 'Management', 'sort_order' => 0],
        '101' => ['level' => PlaceCode::LevelDivision, 'name' => 'Dhaka', 'sort_order' => 1],
        '102' => ['level' => PlaceCode::LevelDivision, 'name' => 'Rangpur', 'sort_order' => 2],
        '103' => ['level' => PlaceCode::LevelDivision, 'name' => 'Chattogram', 'sort_order' => 3],
        '104' => ['level' => PlaceCode::LevelDivision, 'name' => 'Rajshahi', 'sort_order' => 4],
        '105' => ['level' => PlaceCode::LevelDivision, 'name' => 'Khulna', 'sort_order' => 5],
        '106' => ['level' => PlaceCode::LevelDivision, 'name' => 'Barishal', 'sort_order' => 6],
        '107' => ['level' => PlaceCode::LevelDivision, 'name' => 'Sylhet', 'sort_order' => 7],
        '108' => ['level' => PlaceCode::LevelDivision, 'name' => 'Mymensingh', 'sort_order' => 8],
    ];

    public function up(): void
    {
        if (! Schema::hasTable('place_codes')) {
            return;
        }

        $prefix = PlaceCode::BrandPrefix.'-';
        $knownDivisionCodes = [];
        foreach (array_keys(self::LegacySeed) as $legacy) {
            if ((int) $legacy >= 101) {
                $knownDivisionCodes[] = $prefix.$legacy;
                $knownDivisionCodes[] = $legacy;
            }
        }

        DB::transaction(function () use ($prefix, $knownDivisionCodes): void {
            // Blank unused seed divisions first so management can see a safe skeleton.
            foreach (self::LegacySeed as $legacy => $meta) {
                if ($meta['level'] !== PlaceCode::LevelDivision) {
                    continue;
                }

                $place = $this->findLegacyRow($prefix.$legacy, $legacy, $meta['level'], $meta['name']);
                if ($place === null) {
                    continue;
                }
                if ($this->isReferenced((int) $place->id, (string) $place->code)) {
                    continue;
                }
                if (DB::table('place_codes')->where('parent_id', $place->id)->exists()) {
                    continue;
                }

                $this->blankRow((int) $place->id, $meta['sort_order']);
            }

            $managementMeta = self::LegacySeed['100'];
            $management = $this->findLegacyRow($prefix.'100', '100', $managementMeta['level'], $managementMeta['name']);
            if ($management === null) {
                return;
            }
            if ($this->isReferenced((int) $management->id, (string) $management->code)) {
                return;
            }

            $children = DB::table('place_codes')->where('parent_id', $management->id)->get(['id', 'code', 'name', 'level']);
            foreach ($children as $child) {
                if ($child->level !== PlaceCode::LevelDivision) {
                    return;
                }
                $isKnownSeedCode = $child->code !== null && in_array((string) $child->code, $knownDivisionCodes, true);
                $isPlaceholder = $child->code === null && PlaceCode::isPlaceholderLabel((string) $child->name);
                if (! $isKnownSeedCode && ! $isPlaceholder) {
                    return;
                }
                if (DB::table('place_codes')->where('parent_id', $child->id)->exists()) {
                    return;
                }
                if ($this->isReferenced((int) $child->id, (string) ($child->code ?? ''))) {
                    return;
                }
            }

            $this->blankRow((int) $management->id, $managementMeta['sort_order']);
        });
    }

    public function down(): void
    {
        // Irreversible data cleanup — placeholders stay blank.
    }

    private function findLegacyRow(string $branded, string $legacy, string $level, string $name): ?object
    {
        return DB::table('place_codes')
            ->where('level', $level)
            ->where('name', $name)
            ->whereIn('code', [$branded, $legacy])
            ->orderByRaw('CASE WHEN code = ? THEN 0 ELSE 1 END', [$branded])
            ->first();
    }

    private function blankRow(int $id, int $sortOrder): void
    {
        DB::table('place_codes')->where('id', $id)->update([
            'code' => null,
            'name' => '—',
            'base_place_id' => null,
            'sort_order' => $sortOrder,
            'updated_at' => now(),
        ]);
    }

    private function isReferenced(int $placeId, string $code): bool
    {
        if (Schema::hasTable('area_dealer_assignments')
            && DB::table('area_dealer_assignments')->where('place_code_id', $placeId)->exists()) {
            return true;
        }

        if (Schema::hasTable('company_branches')
            && DB::table('company_branches')->where('place_code_id', $placeId)->exists()) {
            return true;
        }

        if (DB::table('place_codes')->where('base_place_id', $placeId)->exists()) {
            return true;
        }

        if ($code === '' || ! Schema::hasTable('users')) {
            return false;
        }

        $normalized = PlaceCode::normalizeCode($code) ?? $code;
        $legacy = str_starts_with($normalized, PlaceCode::BrandPrefix.'-')
            ? substr($normalized, strlen(PlaceCode::BrandPrefix) + 1)
            : $code;

        return DB::table('users')
            ->where(function ($query) use ($placeId, $normalized, $legacy): void {
                $query->whereRaw("COALESCE(profile->>'place_code_id', '') = ?", [(string) $placeId])
                    ->orWhereRaw("COALESCE(profile->>'place_code', '') IN (?, ?)", [$normalized, $legacy]);
            })
            ->exists();
    }
};
