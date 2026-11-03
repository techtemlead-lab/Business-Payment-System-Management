<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Removes the preloaded place-code catalog so the company owner enters
 * every MNHC- code. Rows already linked to a branch, dealer area, or
 * partner profile are kept.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('place_codes')) {
            return;
        }

        $protected = $this->protectedIds();

        $parentQuery = DB::table('place_codes')->whereNotNull('parent_id');
        $baseQuery = DB::table('place_codes')->whereNotNull('base_place_id');
        if ($protected !== []) {
            $parentQuery->whereNotIn('parent_id', $protected);
            $baseQuery->whereNotIn('base_place_id', $protected);
        }
        $parentQuery->update(['parent_id' => null]);
        $baseQuery->update(['base_place_id' => null]);

        $delete = DB::table('place_codes');
        if ($protected !== []) {
            $delete->whereNotIn('id', $protected);
        }
        $delete->delete();

        $this->clearManagementReferral($protected);
    }

    public function down(): void
    {
        // Owners recreate place codes in the Place codes panel.
    }

    /**
     * @return list<int>
     */
    private function protectedIds(): array
    {
        $ids = [];

        if (Schema::hasTable('company_branches') && Schema::hasColumn('company_branches', 'place_code_id')) {
            $ids = array_merge($ids, DB::table('company_branches')->whereNotNull('place_code_id')->pluck('place_code_id')->all());
        }

        if (Schema::hasTable('area_dealer_assignments') && Schema::hasColumn('area_dealer_assignments', 'place_code_id')) {
            $ids = array_merge($ids, DB::table('area_dealer_assignments')->whereNotNull('place_code_id')->pluck('place_code_id')->all());
        }

        if (Schema::hasTable('users') && Schema::hasColumn('users', 'profile')) {
            $profiles = DB::table('users')->whereNotNull('profile')->pluck('profile');
            foreach ($profiles as $profile) {
                $decoded = is_string($profile) ? json_decode($profile, true) : null;
                if (! is_array($decoded)) {
                    continue;
                }
                $placeId = $decoded['place_code_id'] ?? null;
                if (is_numeric($placeId) && (int) $placeId > 0) {
                    $ids[] = (int) $placeId;
                }
            }
        }

        return array_values(array_unique(array_map(intval(...), $ids)));
    }

    /**
     * @param  list<int>  $protected
     */
    private function clearManagementReferral(array $protected): void
    {
        if (! Schema::hasTable('business_settings')) {
            return;
        }

        $row = DB::table('business_settings')->where('key', 'management_referral')->first();
        if (! $row || ! is_string($row->value) || $row->value === '') {
            return;
        }

        $stored = json_decode($row->value, true);
        if (! is_array($stored)) {
            return;
        }

        $placeId = isset($stored['place_code_id']) ? (int) $stored['place_code_id'] : 0;
        if ($placeId === 0 || in_array($placeId, $protected, true)) {
            return;
        }

        $stored['place_code_id'] = null;
        DB::table('business_settings')->where('key', 'management_referral')->update([
            'value' => json_encode($stored, JSON_THROW_ON_ERROR),
            'updated_at' => now(),
        ]);
    }
};
