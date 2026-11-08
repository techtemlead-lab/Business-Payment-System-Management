<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('courier_area_options', function (Blueprint $table): void {
            $table->id();
            $table->string('provider', 40);
            $table->string('level', 10);
            $table->string('external_id', 40);
            $table->string('parent_external_id', 40)->nullable();
            $table->string('name', 150);
            $table->timestamp('fetched_at');
            $table->unique(['provider', 'level', 'external_id']);
            $table->index(['provider', 'level', 'parent_external_id']);
        });

        Schema::create('courier_area_mappings', function (Blueprint $table): void {
            $table->id();
            $table->string('provider', 40);
            $table->foreignId('place_code_id')->constrained('place_codes')->cascadeOnDelete();
            $table->string('city_id', 40)->nullable();
            $table->string('city_name', 150)->nullable();
            $table->string('zone_id', 40)->nullable();
            $table->string('zone_name', 150)->nullable();
            $table->string('area_id', 40)->nullable();
            $table->string('area_name', 150)->nullable();
            $table->foreignId('updated_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
            $table->unique(['provider', 'place_code_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('courier_area_mappings');
        Schema::dropIfExists('courier_area_options');
    }
};
