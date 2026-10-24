<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        $rules = DB::table('commission_rule_parameters')->where('rule_key', 'rank_royalty')->first();
        if (! $rules) {
            return;
        }

        $parameters = json_decode((string) $rules->parameters, true) ?: [];
        if (in_array((string) ($parameters['mo_target'] ?? ''), ['25000.00', '25000', '100000.00', '100000', '200000.00', '200000'], true)) {
            $parameters['mo_target'] = '250000.00';
            DB::table('commission_rule_parameters')->where('id', $rules->id)->update([
                'parameters' => json_encode($parameters, JSON_THROW_ON_ERROR),
                'updated_at' => now(),
            ]);
        }
    }

    public function down(): void
    {
        // Intentionally irreversible: prior environments mixed 25k / 1L / 2L defaults.
    }
};
