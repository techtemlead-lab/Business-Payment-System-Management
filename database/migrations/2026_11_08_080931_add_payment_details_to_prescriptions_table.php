<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * How a clinic sale was paid. Cash stays the default, so older dispensed records read as cash.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('prescriptions', function (Blueprint $table): void {
            $table->string('payment_method', 10)->default('cash');
            $table->string('payment_reference', 80)->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('prescriptions', function (Blueprint $table): void {
            $table->dropColumn(['payment_method', 'payment_reference']);
        });
    }
};
