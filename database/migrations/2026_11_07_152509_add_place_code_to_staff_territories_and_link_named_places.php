<?php

use App\Models\PlaceCode;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Staff territories get a real place code link, like branches and area seats already have.
 *
 * Existing rows saved with names only are linked once, here, when their whole name path
 * (division, then district, then upazila, then union, each inside the one above) points to
 * exactly one active place code. Anything ambiguous or unknown stays empty and shows up in
 * the go-live readiness list so the office can pick the right code. After this migration
 * the app never matches places by name again.
 *
 * New or edited active seats and territories must carry a place code: a CHECK constraint
 * added NOT VALID enforces that for every new write without failing on old rows.
 */
return new class extends Migration
{
    private const Levels = ['division', 'district', 'upazila', 'union'];

    public function up(): void
    {
        Schema::table('staff_territories', function (Blueprint $table): void {
            $table->foreignId('place_code_id')->nullable()->after('union')->constrained('place_codes')->restrictOnDelete();
            $table->index(['place_code_id', 'is_active']);
        });

        // The head office is a flag, not a hard-coded branch code: the first branch ever
        // created is the seeded head office hub.
        Schema::table('company_branches', function (Blueprint $table): void {
            $table->boolean('is_head_office')->default(false)->after('is_stock_hub');
        });
        $headOfficeId = DB::table('company_branches')->min('id');
        if ($headOfficeId !== null) {
            DB::table('company_branches')->where('id', $headOfficeId)->update(['is_head_office' => true]);
        }

        foreach (['staff_territories', 'area_dealer_assignments', 'company_branches'] as $table) {
            $this->linkByUniquePath($table);
        }
        $this->copyPartnerPlaceCodeIds();

        if (DB::getDriverName() === 'pgsql') {
            DB::statement('ALTER TABLE staff_territories ADD CONSTRAINT staff_territories_active_needs_place_code CHECK (is_active = false OR place_code_id IS NOT NULL) NOT VALID');
            DB::statement('ALTER TABLE area_dealer_assignments ADD CONSTRAINT area_dealer_assignments_active_needs_place_code CHECK (is_active = false OR place_code_id IS NOT NULL) NOT VALID');
        }
    }

    public function down(): void
    {
        if (DB::getDriverName() === 'pgsql') {
            DB::statement('ALTER TABLE area_dealer_assignments DROP CONSTRAINT IF EXISTS area_dealer_assignments_active_needs_place_code');
            DB::statement('ALTER TABLE staff_territories DROP CONSTRAINT IF EXISTS staff_territories_active_needs_place_code');
        }
        Schema::table('company_branches', function (Blueprint $table): void {
            $table->dropColumn('is_head_office');
        });
        Schema::table('staff_territories', function (Blueprint $table): void {
            $table->dropConstrainedForeignId('place_code_id');
        });
    }

    /**
     * Link rows with no place code to the single active place their full name path names.
     */
    private function linkByUniquePath(string $table): void
    {
        $columns = array_values(array_filter(self::Levels, fn (string $level): bool => Schema::hasColumn($table, $level)));
        $hasLevel = Schema::hasColumn($table, 'level');
        foreach (DB::table($table)->whereNull('place_code_id')->lazyById(200) as $row) {
            $path = [];
            foreach ($columns as $level) {
                $name = trim((string) ($row->{$level} ?? ''));
                if ($name === '') {
                    break;
                }
                $path[$level] = $name;
                if ($hasLevel && $row->level === $level) {
                    break;
                }
            }
            if ($path === [] || ($hasLevel && array_key_last($path) !== $row->level)) {
                continue;
            }
            $placeId = $this->uniquePlaceFor($path);
            if ($placeId !== null) {
                DB::table($table)->where('id', $row->id)->update(['place_code_id' => $placeId]);
            }
        }
    }

    /**
     * Partners whose profile has a place code but no place code id get the id of that
     * exact code (a code match, never a name match), so lists and SR access can key by id.
     */
    private function copyPartnerPlaceCodeIds(): void
    {
        $partners = DB::table('users')
            ->whereIn('role', ['dealer', 'sub_dealer'])
            ->whereNotNull('profile')
            ->whereRaw("COALESCE(profile->>'place_code', '') <> ''")
            ->whereRaw("COALESCE(profile->>'place_code_id', '') = ''")
            ->select(['id', 'profile'])
            ->lazyById(200);
        foreach ($partners as $user) {
            $profile = json_decode((string) $user->profile, true);
            if (! is_array($profile)) {
                continue;
            }
            $normalized = PlaceCode::normalizeCode((string) $profile['place_code']);
            if ($normalized === null) {
                continue;
            }
            $legacy = substr($normalized, strlen(PlaceCode::BrandPrefix) + 1);
            $placeId = DB::table('place_codes')
                ->where('is_active', true)
                ->where('level', '<>', PlaceCode::LevelManagement)
                ->whereIn('code', [$normalized, $legacy])
                ->orderByRaw('CASE WHEN code = ? THEN 0 ELSE 1 END', [$normalized])
                ->value('id');
            if ($placeId !== null) {
                $profile['place_code_id'] = (int) $placeId;
                DB::table('users')->where('id', $user->id)->update(['profile' => json_encode($profile, JSON_THROW_ON_ERROR)]);
            }
        }
    }

    /**
     * @param  array<string, string>  $path  level => name, from division downward
     */
    private function uniquePlaceFor(array $path): ?int
    {
        $parentIds = null;
        foreach ($path as $level => $name) {
            $ids = DB::table('place_codes')
                ->where('level', $level)
                ->where('is_active', true)
                ->whereNotNull('code')
                ->whereRaw('LOWER(TRIM(name)) = ?', [mb_strtolower($name)])
                ->when($parentIds !== null, fn ($query) => $query->whereIn('parent_id', $parentIds))
                ->pluck('id')
                ->all();
            if (count($ids) !== 1) {
                return null;
            }
            $parentIds = $ids;
        }

        return $parentIds[0] ?? null;
    }
};
