<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Goods can be received without a batch number; the lot is listed so staff can add it later.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('goods_receipt_lines', function (Blueprint $table): void {
            $table->string('batch_number', 60)->nullable()->change();
        });
    }

    public function down(): void
    {
        DB::table('goods_receipt_lines')->whereNull('batch_number')->update(['batch_number' => '']);
        Schema::table('goods_receipt_lines', function (Blueprint $table): void {
            $table->string('batch_number', 60)->nullable(false)->change();
        });
    }
};
