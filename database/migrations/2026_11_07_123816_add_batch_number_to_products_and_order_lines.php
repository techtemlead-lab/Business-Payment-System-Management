<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Batch number (ব্যাচ নম্বর) of the stock on hand for each product, and a copy of the
 * batch and expiry on every order line so an invoice always shows what was sold.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('products', function (Blueprint $table): void {
            $table->string('batch_number', 60)->nullable()->after('expires_on');
        });
        Schema::table('order_lines', function (Blueprint $table): void {
            $table->string('batch_number', 60)->nullable();
            $table->date('expires_on')->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('order_lines', function (Blueprint $table): void {
            $table->dropColumn(['batch_number', 'expires_on']);
        });
        Schema::table('products', function (Blueprint $table): void {
            $table->dropColumn('batch_number');
        });
    }
};
