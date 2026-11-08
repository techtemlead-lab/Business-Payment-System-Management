<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Allergies are stored encrypted (see the PrescriptionPatient casts), so the column is text.
     */
    public function up(): void
    {
        Schema::table('prescription_patients', function (Blueprint $table): void {
            $table->text('allergies')->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('prescription_patients', function (Blueprint $table): void {
            $table->dropColumn('allergies');
        });
    }
};
