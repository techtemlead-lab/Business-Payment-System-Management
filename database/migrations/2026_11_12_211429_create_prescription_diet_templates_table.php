<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Named lists of foods to eat and to avoid that a prescriber keeps and adds to a
 * prescription. Private to the prescriber who saved them; the lists are stored encrypted.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('prescription_diet_templates', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('prescriber_id')->constrained('users');
            $table->string('name', 80);
            $table->text('foods_allowed')->nullable();
            $table->text('foods_avoided')->nullable();
            $table->timestamps();
            $table->unique(['prescriber_id', 'name']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('prescription_diet_templates');
    }
};
