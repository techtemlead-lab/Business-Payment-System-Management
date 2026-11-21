<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * How the partner gets the goods: "courier" (sent by courier, the way every order went so far)
 * or "office" (the partner collects them at the office, no courier and no tracking number).
 * Every existing order becomes a courier order.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('orders', function (Blueprint $table): void {
            $table->string('delivery_method', 16)->default('courier');
        });
        DB::statement("ALTER TABLE orders ADD CONSTRAINT orders_delivery_method_check CHECK (delivery_method IN ('courier', 'office'))");
    }

    public function down(): void
    {
        DB::statement('ALTER TABLE orders DROP CONSTRAINT IF EXISTS orders_delivery_method_check');
        Schema::table('orders', function (Blueprint $table): void {
            $table->dropColumn('delivery_method');
        });
    }
};
