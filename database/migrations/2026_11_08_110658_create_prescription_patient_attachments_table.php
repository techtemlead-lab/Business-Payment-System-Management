<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Lab reports and old prescriptions kept on a patient. The files live on the private disk and
 * only the patient's own prescriber can download them.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('prescription_patient_attachments', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('patient_id')->constrained('prescription_patients');
            $table->foreignId('prescriber_id')->constrained('users');
            $table->string('kind', 20);
            $table->string('disk_path', 255);
            // Encrypted: a file name can carry the patient's name or illness.
            $table->text('original_name');
            $table->string('mime', 100);
            $table->unsignedBigInteger('size_bytes');
            $table->timestamps();
            $table->index(['patient_id', 'id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('prescription_patient_attachments');
    }
};
