<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::table('dealers', function (Blueprint $table): void {
            $table->string('dealer_type', 20)->default('dealer')->index();
        });
        Schema::table('dealership_requests', function (Blueprint $table): void {
            $table->string('dealer_type', 20)->default('dealer');
        });
        Schema::table('ledger_entries', function (Blueprint $table): void {
            $table->string('earning_category', 50)->nullable()->index();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('ledger_entries', fn (Blueprint $table) => $table->dropColumn('earning_category'));
        Schema::table('dealership_requests', fn (Blueprint $table) => $table->dropColumn('dealer_type'));
        Schema::table('dealers', fn (Blueprint $table) => $table->dropColumn('dealer_type'));
    }
};
