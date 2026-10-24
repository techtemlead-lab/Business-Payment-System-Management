<?php

use App\Models\PlaceCode;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        $prefix = PlaceCode::BrandPrefix.'-';

        DB::table('place_codes')
            ->where('code', 'not like', $prefix.'%')
            ->orderBy('id')
            ->chunkById(200, function ($rows): void {
                foreach ($rows as $row) {
                    $normalized = PlaceCode::normalizeCode((string) $row->code);
                    if ($normalized === null || $normalized === $row->code) {
                        continue;
                    }
                    DB::table('place_codes')->where('id', $row->id)->update([
                        'code' => $normalized,
                        'updated_at' => now(),
                    ]);
                }
            });

        DB::table('users')
            ->whereNotNull('dealer_code')
            ->where('dealer_code', 'not like', $prefix.'%')
            ->orderBy('id')
            ->chunkById(200, function ($rows): void {
                foreach ($rows as $row) {
                    $branded = PlaceCode::brandSerial((string) $row->dealer_code);
                    if ($branded === null || $branded === $row->dealer_code) {
                        continue;
                    }
                    DB::table('users')->where('id', $row->id)->update([
                        'dealer_code' => $branded,
                        'updated_at' => now(),
                    ]);
                }
            });

        if (DB::getDriverName() === 'pgsql') {
            DB::statement("
                UPDATE users
                SET profile = jsonb_set(
                    COALESCE(profile::jsonb, '{}'::jsonb),
                    '{place_code}',
                    to_jsonb(('MNHC-' || (profile->>'place_code'))),
                    true
                ),
                updated_at = NOW()
                WHERE profile IS NOT NULL
                  AND COALESCE(profile->>'place_code', '') ~ '^[0-9]{2,8}$'
            ");
            DB::statement("
                UPDATE users
                SET profile = jsonb_set(
                    COALESCE(profile::jsonb, '{}'::jsonb),
                    '{area_code}',
                    to_jsonb(
                        CASE
                            WHEN profile->>'area_code' ~ '^[0-9]{2,8}$' THEN 'MNHC-' || (profile->>'area_code')
                            WHEN profile->>'area_code' ~ '^[0-9]{2,8}-[A-Z0-9]+$' THEN 'MNHC-' || (profile->>'area_code')
                            ELSE profile->>'area_code'
                        END
                    ),
                    true
                ),
                updated_at = NOW()
                WHERE profile IS NOT NULL
                  AND (
                    COALESCE(profile->>'area_code', '') ~ '^[0-9]{2,8}$'
                    OR COALESCE(profile->>'area_code', '') ~ '^[0-9]{2,8}-[A-Z0-9]+$'
                  )
            ");
        }

        $rules = DB::table('commission_rule_parameters')->where('rule_key', 'rank_royalty')->first();
        if ($rules) {
            $parameters = json_decode((string) $rules->parameters, true) ?: [];
            if (in_array((string) ($parameters['mo_target'] ?? ''), ['25000.00', '25000', '100000.00', '100000', '200000.00', '200000'], true)) {
                $parameters['mo_target'] = '250000.00';
                DB::table('commission_rule_parameters')->where('id', $rules->id)->update([
                    'parameters' => json_encode($parameters, JSON_THROW_ON_ERROR),
                    'updated_at' => now(),
                ]);
            }
        }
    }

    public function down(): void
    {
        $prefix = PlaceCode::BrandPrefix.'-';

        DB::table('place_codes')
            ->where('code', 'like', $prefix.'%')
            ->orderBy('id')
            ->chunkById(200, function ($rows) use ($prefix): void {
                foreach ($rows as $row) {
                    $legacy = substr((string) $row->code, strlen($prefix));
                    if ($legacy === '' || ! preg_match('/^\d{2,8}$/', $legacy)) {
                        continue;
                    }
                    DB::table('place_codes')->where('id', $row->id)->update([
                        'code' => $legacy,
                        'updated_at' => now(),
                    ]);
                }
            });
    }
};
