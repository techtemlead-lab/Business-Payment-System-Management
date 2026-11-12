<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Goods can be received without an expiry date; the owner fills it in only when wanted.
     */
    public function up(): void
    {
        Schema::table('goods_receipt_lines', function (Blueprint $table): void {
            $table->date('expires_on')->nullable()->change();
        });
    }

    /**
     * The date becomes compulsory again only while every receipt has one.
     * A receipt saved without a date keeps its blank: a date is never made up.
     */
    public function down(): void
    {
        if (DB::table('goods_receipt_lines')->whereNull('expires_on')->exists()) {
            return;
        }

        Schema::table('goods_receipt_lines', function (Blueprint $table): void {
            $table->date('expires_on')->nullable(false)->change();
        });
    }
};
