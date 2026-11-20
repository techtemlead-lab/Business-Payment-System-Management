<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * A partner can be listed on a company-paid spend (a tour for many members) with no amount of their own.
 * The share becomes optional; every existing row keeps its amount.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('fund_spend_partners', function (Blueprint $table): void {
            $table->decimal('share_amount', 14, 2)->nullable()->change();
        });
    }

    public function down(): void
    {
        // Rows with no amount would have nothing to fall back to, so the column stays optional.
    }
};
