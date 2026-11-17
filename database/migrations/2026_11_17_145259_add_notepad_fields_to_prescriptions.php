<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * The notepad-style prescription: a free-text address and an automatic patient number on the
 * patient, and the examination notes plus the new/old patient mark on the prescription. The
 * address and the examination are clinical text, so both are stored encrypted like the rest.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('prescription_patients', function (Blueprint $table): void {
            $table->text('address_text')->nullable();
            $table->string('patient_code', 20)->nullable();
            $table->index(['prescriber_id', 'patient_code']);
        });
        Schema::table('prescriptions', function (Blueprint $table): void {
            $table->string('visit_type', 3)->nullable();
            $table->text('examination')->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('prescriptions', function (Blueprint $table): void {
            $table->dropColumn(['visit_type', 'examination']);
        });
        Schema::table('prescription_patients', function (Blueprint $table): void {
            $table->dropIndex(['prescriber_id', 'patient_code']);
            $table->dropColumn(['address_text', 'patient_code']);
        });
    }
};
