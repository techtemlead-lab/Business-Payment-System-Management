<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('place_codes', function (Blueprint $table): void {
            $table->id();
            $table->string('level', 32);
            $table->string('code', 32);
            $table->string('name');
            $table->foreignId('parent_id')->nullable()->constrained('place_codes')->nullOnDelete();
            $table->foreignId('base_place_id')->nullable()->constrained('place_codes')->nullOnDelete();
            $table->unsignedInteger('sort_order')->default(0);
            $table->boolean('is_active')->default(true);
            $table->timestamps();

            $table->unique('code');
            $table->index(['level', 'is_active']);
            $table->index(['parent_id', 'level']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('place_codes');
    }
};
