<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('order_lines', function (Blueprint $table): void {
            $table->index(['order_id', 'product_id']);
        });

        Schema::table('payment_claims', function (Blueprint $table): void {
            $table->index(['order_id', 'status']);
        });

        Schema::table('shipments', function (Blueprint $table): void {
            $table->index(['order_id', 'status']);
        });

        Schema::table('commission_allocations', function (Blueprint $table): void {
            $table->index(['user_id', 'status', 'created_at']);
        });
    }

    public function down(): void
    {
        Schema::table('order_lines', function (Blueprint $table): void {
            $table->dropIndex(['order_id', 'product_id']);
        });

        Schema::table('payment_claims', function (Blueprint $table): void {
            $table->dropIndex(['order_id', 'status']);
        });

        Schema::table('shipments', function (Blueprint $table): void {
            $table->dropIndex(['order_id', 'status']);
        });

        Schema::table('commission_allocations', function (Blueprint $table): void {
            $table->dropIndex(['user_id', 'status', 'created_at']);
        });
    }
};
