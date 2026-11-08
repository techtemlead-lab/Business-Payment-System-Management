<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Who opened which patient record. Kept out of the audit log on purpose: the owner reads the
 * audit log, and this list belongs only to the patient's own prescriber.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('prescription_patient_access_logs', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('patient_id')->constrained('prescription_patients')->cascadeOnDelete();
            $table->foreignId('prescriber_id')->constrained('users');
            $table->foreignId('viewer_id')->nullable()->constrained('users')->nullOnDelete();
            $table->string('action', 20);
            $table->timestamp('created_at')->useCurrent();
            $table->index(['prescriber_id', 'id']);
            $table->index(['patient_id', 'id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('prescription_patient_access_logs');
    }
};
