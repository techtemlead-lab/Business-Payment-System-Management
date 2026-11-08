<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * A prescriber's appointments and token queue for the day. The unique index guarantees a
 * token is never given twice on the same day.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('prescription_appointments', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('prescriber_id')->constrained('users');
            $table->foreignId('patient_id')->constrained('prescription_patients');
            $table->date('appointment_on');
            $table->time('appointment_time')->nullable();
            $table->unsignedSmallInteger('token');
            $table->string('status', 12)->default('waiting');
            // Encrypted (see the model): the reason for the visit is health information.
            $table->text('note')->nullable();
            $table->timestamps();
            $table->unique(['prescriber_id', 'appointment_on', 'token']);
            $table->index(['patient_id', 'appointment_on']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('prescription_appointments');
    }
};
