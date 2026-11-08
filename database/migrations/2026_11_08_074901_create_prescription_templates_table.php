<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Prescription module, phase 2: named sets of medicines and advice a prescriber saves to
 * start new prescriptions from. Private to the prescriber who saved them.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('prescription_templates', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('prescriber_id')->constrained('users');
            $table->string('name', 80);
            $table->text('advice')->nullable();
            $table->json('lines');
            $table->timestamps();
            $table->unique(['prescriber_id', 'name']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('prescription_templates');
    }
};
