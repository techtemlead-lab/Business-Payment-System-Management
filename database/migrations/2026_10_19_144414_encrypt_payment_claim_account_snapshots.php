<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        DB::table('payment_claims')->whereNotNull('account_snapshot')->orderBy('id')->chunkById(100, function ($claims): void {
            foreach ($claims as $claim) {
                $decoded = json_decode((string) $claim->account_snapshot, true);
                if (! is_array($decoded) || isset($decoded['encrypted'])) {
                    continue;
                }

                DB::table('payment_claims')->where('id', $claim->id)->update([
                    'account_snapshot' => json_encode(['encrypted' => encrypt($decoded)], JSON_THROW_ON_ERROR),
                ]);
            }
        });
    }

    public function down(): void
    {
        DB::table('payment_claims')->whereNotNull('account_snapshot')->orderBy('id')->chunkById(100, function ($claims): void {
            foreach ($claims as $claim) {
                $decoded = json_decode((string) $claim->account_snapshot, true);
                if (! is_array($decoded) || ! isset($decoded['encrypted'])) {
                    continue;
                }

                DB::table('payment_claims')->where('id', $claim->id)->update([
                    'account_snapshot' => json_encode(decrypt($decoded['encrypted']), JSON_THROW_ON_ERROR),
                ]);
            }
        });
    }
};
