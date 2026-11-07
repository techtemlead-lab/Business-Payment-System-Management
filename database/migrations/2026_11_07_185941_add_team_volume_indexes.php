<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Bike and car progress adds up the delivered sales of a leader's whole team on every
 * delivery. These indexes let PostgreSQL read just the user id and totals of delivered
 * orders (index-only), and find the few orders that have a refund without reading all
 * commission batches.
 */
return new class extends Migration
{
    public function up(): void
    {
        DB::statement("CREATE INDEX IF NOT EXISTS orders_delivered_user_total_index ON orders (user_id) INCLUDE (total, paid) WHERE status = 'delivered' AND is_opening_balance = false");
        DB::statement('CREATE INDEX IF NOT EXISTS commission_batches_refunded_order_index ON commission_batches (order_id) INCLUDE (refunded_amount) WHERE refunded_amount > 0');
    }

    public function down(): void
    {
        DB::statement('DROP INDEX IF EXISTS orders_delivered_user_total_index');
        DB::statement('DROP INDEX IF EXISTS commission_batches_refunded_order_index');
    }
};
