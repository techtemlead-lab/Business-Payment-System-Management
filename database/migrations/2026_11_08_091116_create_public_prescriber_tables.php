<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * The public prescriber directory, private consultation requests, the record that a patient
 * agreed to be kept on file, and partners' data-access and delete requests.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('prescriber_public_profiles', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('user_id')->unique()->constrained('users');
            // Off until the prescriber turns it on from their own page.
            $table->boolean('listed')->default(false);
            $table->string('chamber_hours', 200)->nullable();
            $table->timestamps();
        });

        Schema::create('consultation_requests', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('prescriber_id')->constrained('users');
            $table->string('name', 120);
            $table->string('phone', 20);
            $table->text('problem');
            $table->string('preferred_time', 120)->nullable();
            // The exact consent line the visitor agreed to, and when.
            $table->text('consent_statement');
            $table->timestamp('consented_at');
            $table->string('status', 12)->default('new');
            $table->foreignId('patient_id')->nullable()->constrained('prescription_patients')->nullOnDelete();
            $table->timestamp('handled_at')->nullable();
            $table->timestamps();
            $table->index(['prescriber_id', 'status', 'id']);
        });

        Schema::create('prescription_patient_consents', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('patient_id')->unique()->constrained('prescription_patients')->cascadeOnDelete();
            $table->foreignId('prescriber_id')->constrained('users');
            $table->string('source', 24);
            $table->text('statement');
            $table->foreignId('consultation_request_id')->nullable()->constrained('consultation_requests')->nullOnDelete();
            $table->timestamp('confirmed_at');
            $table->timestamps();
        });

        Schema::create('privacy_requests', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('user_id')->constrained('users');
            $table->string('kind', 12);
            $table->string('status', 12)->default('open');
            $table->foreignId('resolved_by')->nullable()->constrained('users');
            $table->timestamp('resolved_at')->nullable();
            $table->text('resolution_note')->nullable();
            $table->timestamps();
            $table->index(['status', 'id']);
            $table->index(['user_id', 'id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('privacy_requests');
        Schema::dropIfExists('prescription_patient_consents');
        Schema::dropIfExists('consultation_requests');
        Schema::dropIfExists('prescriber_public_profiles');
    }
};
