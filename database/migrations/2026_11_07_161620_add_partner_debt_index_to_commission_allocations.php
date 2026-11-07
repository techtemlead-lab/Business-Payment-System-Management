<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Every wallet credit looks up the partner's commission rows that still carry an
 * unrecovered refund deficit. Without this index the lookup reads all of the partner's
 * commission rows (tens of thousands for a busy leader), and it runs under the money lock.
 * The partial index holds only the few rows that actually carry a deficit.
 */
return new class extends Migration
{
    public function up(): void
    {
        DB::statement("CREATE INDEX IF NOT EXISTS commission_allocations_user_unrecovered_deficit_index ON commission_allocations (user_id, id) WHERE (metadata ->> 'unrecovered_deficit') IS NOT NULL");
    }

    public function down(): void
    {
        DB::statement('DROP INDEX IF EXISTS commission_allocations_user_unrecovered_deficit_index');
    }
};
