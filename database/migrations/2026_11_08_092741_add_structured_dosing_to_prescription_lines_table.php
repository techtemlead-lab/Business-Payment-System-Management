<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Structured dosing next to the free-text dose, frequency and duration, which stay.
     */
    public function up(): void
    {
        Schema::table('prescription_lines', function (Blueprint $table): void {
            $table->string('dose_schedule', 20)->nullable();
            $table->string('food_timing', 10)->nullable();
            $table->unsignedSmallInteger('duration_count')->nullable();
            $table->string('duration_unit', 10)->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('prescription_lines', function (Blueprint $table): void {
            $table->dropColumn(['dose_schedule', 'food_timing', 'duration_count', 'duration_unit']);
        });
    }
};
