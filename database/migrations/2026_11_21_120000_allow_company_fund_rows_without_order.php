<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Money a closed partner account still held can be moved to the company profit fund by the owner. That money
 * comes from no single order, so its company fund row has no order. Only the NOT NULL rule is dropped (a
 * catalog change, no table rewrite); every existing row keeps its order and every writer still sets one.
 */
return new class extends Migration
{
    public function up(): void
    {
        DB::statement('ALTER TABLE commission_allocations ALTER COLUMN order_id DROP NOT NULL');
    }

    public function down(): void
    {
        // Rows written without an order would block the rule, so it comes back only while there are none.
        if (DB::table('commission_allocations')->whereNull('order_id')->doesntExist()) {
            DB::statement('ALTER TABLE commission_allocations ALTER COLUMN order_id SET NOT NULL');
        }
    }
};
