<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * payment_claims(order_id, status) was indexed twice: as payment_claims_order_status_index
 * (2026_10_19_133220) and again as payment_claims_order_id_status_index (2026_11_04_000001).
 * Keep the first and drop the copy. The copy is only dropped while the original exists, so
 * the table never loses its (order_id, status) index.
 */
return new class extends Migration
{
    private const Original = 'payment_claims_order_status_index';

    private const Duplicate = 'payment_claims_order_id_status_index';

    public function up(): void
    {
        if ($this->indexExists(self::Original)) {
            DB::statement('DROP INDEX IF EXISTS '.self::Duplicate);
        }
    }

    public function down(): void
    {
        // Put the copy back so 2026_11_04_000001's own rollback can drop it again.
        DB::statement('CREATE INDEX IF NOT EXISTS '.self::Duplicate.' ON payment_claims (order_id, status)');
    }

    private function indexExists(string $name): bool
    {
        return DB::table('pg_indexes')
            ->where('schemaname', DB::raw('current_schema()'))
            ->where('tablename', 'payment_claims')
            ->where('indexname', $name)
            ->exists();
    }
};
