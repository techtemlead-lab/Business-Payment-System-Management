<?php

use App\Models\PlaceCode;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Events "for some divisions" are targeted by division place code ids instead of division
 * names. The old name list stays as display text only.
 *
 * Existing events are converted once: a saved name becomes the id of the single active,
 * coded division with exactly that name. A name that matches no division or more than one
 * is dropped from the targeting (the event then reaches fewer partners, never the wrong ones).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('partner_events', function (Blueprint $table): void {
            $table->json('division_place_ids')->nullable()->after('divisions');
        });

        foreach (DB::table('partner_events')->where('audience', 'divisions')->select(['id', 'divisions'])->lazyById(200) as $event) {
            $names = json_decode((string) $event->divisions, true);
            $ids = [];
            foreach (is_array($names) ? $names : [] as $name) {
                $matches = DB::table('place_codes')
                    ->where('level', PlaceCode::LevelDivision)
                    ->where('is_active', true)
                    ->whereNotNull('code')
                    ->whereRaw('LOWER(TRIM(name)) = ?', [mb_strtolower(trim((string) $name))])
                    ->pluck('id');
                if ($matches->count() === 1) {
                    $ids[] = (int) $matches->first();
                }
            }
            DB::table('partner_events')->where('id', $event->id)->update(['division_place_ids' => json_encode(array_values(array_unique($ids)))]);
        }
    }

    public function down(): void
    {
        Schema::table('partner_events', function (Blueprint $table): void {
            $table->dropColumn('division_place_ids');
        });
    }
};
