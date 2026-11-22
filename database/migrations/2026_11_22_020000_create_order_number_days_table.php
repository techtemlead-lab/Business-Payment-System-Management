<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * The invoice number of every new order is MNHC-{YYYYMMDD}-{sequence} (owner request, 2026-11-22). The date is the order
     * date in Bangladesh time and the sequence starts again at 100001 each day. This table holds the last sequence given
     * for each day; the row is updated inside the order's own transaction, so a rolled-back order gives its number back
     * and two orders never share one. Orders that already exist keep their number; orders.number is not changed.
     */
    public function up(): void
    {
        Schema::create('order_number_days', function (Blueprint $table): void {
            $table->date('day')->primary();
            $table->unsignedBigInteger('last_value');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('order_number_days');
    }
};
