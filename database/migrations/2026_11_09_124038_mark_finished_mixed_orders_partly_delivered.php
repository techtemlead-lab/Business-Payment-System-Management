<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Orders that already finished with one parcel delivered and one received back were left showing
 * as sent. This gives them their final status. Only the status changes: no commission, wallet or
 * journal row is touched, and the order's updated_at stays as it was.
 */
return new class extends Migration
{
    public function up(): void
    {
        DB::statement(<<<'SQL'
            UPDATE orders
            SET status = 'partly_delivered'
            WHERE status IN ('dispatched', 'partially_dispatched')
              AND EXISTS (SELECT 1 FROM shipments WHERE shipments.order_id = orders.id AND shipments.status = 'delivered')
              AND EXISTS (SELECT 1 FROM shipments WHERE shipments.order_id = orders.id AND shipments.status = 'returned')
              AND NOT EXISTS (SELECT 1 FROM shipments WHERE shipments.order_id = orders.id AND shipments.status = 'dispatched')
              AND NOT EXISTS (SELECT 1 FROM order_lines WHERE order_lines.order_id = orders.id AND order_lines.dispatched < order_lines.quantity)
        SQL);
    }

    public function down(): void
    {
        DB::table('orders')->where('status', 'partly_delivered')->update(['status' => 'dispatched']);
    }
};
