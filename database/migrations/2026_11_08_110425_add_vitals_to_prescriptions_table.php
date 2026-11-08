<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Blood pressure, weight, pulse and temperature taken at the visit, stored encrypted
     * (see the Prescription casts).
     */
    public function up(): void
    {
        Schema::table('prescriptions', function (Blueprint $table): void {
            $table->text('vitals')->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('prescriptions', function (Blueprint $table): void {
            $table->dropColumn('vitals');
        });
    }
};
