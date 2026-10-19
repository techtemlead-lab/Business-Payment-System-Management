<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('area_dealer_assignments', function (Blueprint $table): void {
            $table->foreignId('place_code_id')->nullable()->after('union')->constrained('place_codes')->nullOnDelete();
            $table->index(['place_code_id', 'is_active']);
        });
    }

    public function down(): void
    {
        Schema::table('area_dealer_assignments', function (Blueprint $table): void {
            $table->dropConstrainedForeignId('place_code_id');
        });
    }
};
