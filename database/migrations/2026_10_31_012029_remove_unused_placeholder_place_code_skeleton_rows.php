<?php

use App\Models\PlaceCode;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Removes unused blank skeleton place rows (null code + placeholder name)
 * left by the earlier Management + 8 Division slot seeder. Live geography
 * with real codes, children, or assignments is left untouched.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('place_codes')) {
            return;
        }

        $candidates = PlaceCode::query()
            ->whereNull('code')
            ->where(function ($query): void {
                $query->where('name', '—')
                    ->orWhere('name', '-')
                    ->orWhere('name', '–')
                    ->orWhere('name', '');
            })
            ->orderByDesc('id')
            ->get();

        foreach ($candidates as $place) {
            if ($this->isReferenced($place)) {
                continue;
            }

            DB::table('place_codes')->where('id', $place->id)->delete();
        }
    }

    public function down(): void
    {
        // Irreversible cleanup — admins recreate places in the panel.
    }

    private function isReferenced(PlaceCode $place): bool
    {
        if (PlaceCode::query()->where('parent_id', $place->id)->exists()) {
            return true;
        }

        if (PlaceCode::query()->where('base_place_id', $place->id)->exists()) {
            return true;
        }

        if (Schema::hasTable('area_dealer_assignments')
            && DB::table('area_dealer_assignments')->where('place_code_id', $place->id)->exists()) {
            return true;
        }

        if (Schema::hasTable('company_branches')
            && DB::table('company_branches')->where('place_code_id', $place->id)->exists()) {
            return true;
        }

        if (Schema::hasTable('users') && Schema::hasColumn('users', 'profile')) {
            $linked = DB::table('users')
                ->whereNotNull('profile')
                ->where(function ($query) use ($place): void {
                    $query->where('profile->place_code_id', $place->id)
                        ->orWhere('profile->place_code', (string) $place->id);
                })
                ->exists();
            if ($linked) {
                return true;
            }
        }

        return false;
    }
};
