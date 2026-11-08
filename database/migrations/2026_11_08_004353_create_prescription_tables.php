<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Prescription module, phase 1: who may prescribe, their patients, and prescriptions.
 * Dealer and sub-dealer prescriptions never touch company stock; only the owner-doctor's
 * clinic prescriptions are dispensed from a branch.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('users', function (Blueprint $table): void {
            // Fast check for the menu and page guards; the details live in prescriber_registrations.
            $table->boolean('can_prescribe')->default(false);
        });

        Schema::create('prescriber_registrations', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('user_id')->unique()->constrained('users');
            $table->boolean('enabled')->default(false);
            $table->string('registration_type', 20);
            $table->string('registration_number', 60);
            $table->text('note')->nullable();
            $table->foreignId('enabled_by')->nullable()->constrained('users');
            $table->timestamp('enabled_at')->nullable();
            $table->foreignId('disabled_by')->nullable()->constrained('users');
            $table->timestamp('disabled_at')->nullable();
            $table->text('disable_reason')->nullable();
            $table->timestamps();
        });

        Schema::create('prescription_patients', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('prescriber_id')->constrained('users');
            $table->string('name', 120);
            $table->unsignedSmallInteger('age')->nullable();
            $table->string('sex', 10)->nullable();
            $table->string('phone', 20)->nullable();
            $table->foreignId('place_code_id')->nullable()->constrained('place_codes')->nullOnDelete();
            $table->text('notes')->nullable();
            $table->timestamps();
            $table->index(['prescriber_id', 'name']);
        });

        Schema::create('prescriptions', function (Blueprint $table): void {
            $table->id();
            $table->string('number', 30)->unique();
            $table->string('kind', 10);
            $table->foreignId('prescriber_id')->constrained('users');
            $table->foreignId('patient_id')->constrained('prescription_patients');
            $table->date('prescribed_on');
            $table->text('complaints')->nullable();
            $table->text('diagnosis')->nullable();
            $table->text('advice')->nullable();
            $table->date('follow_up_on')->nullable();
            $table->decimal('subtotal', 14, 2)->default(0);
            $table->decimal('discount_total', 14, 2)->default(0);
            $table->decimal('total', 14, 2)->default(0);
            $table->string('status', 20)->default('issued');
            $table->foreignId('branch_id')->nullable()->constrained('company_branches');
            $table->decimal('mrp_total', 14, 2)->default(0);
            $table->decimal('manufacturer_share', 14, 2)->default(0);
            $table->decimal('clinic_fund', 14, 2)->default(0);
            $table->foreignId('dispensed_by')->nullable()->constrained('users');
            $table->timestamp('dispensed_at')->nullable();
            $table->timestamps();
            $table->index(['prescriber_id', 'prescribed_on']);
            $table->index(['patient_id']);
            $table->index(['kind', 'status', 'dispensed_at']);
        });

        Schema::create('prescription_lines', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('prescription_id')->constrained('prescriptions')->cascadeOnDelete();
            $table->foreignId('product_id')->nullable()->constrained('products');
            $table->string('medicine_name', 160);
            $table->string('dose', 80)->nullable();
            $table->string('frequency', 80)->nullable();
            $table->string('duration', 80)->nullable();
            $table->string('instructions', 300)->nullable();
            $table->decimal('unit_price', 14, 2)->default(0);
            $table->unsignedInteger('quantity')->default(1);
            $table->decimal('discount', 14, 2)->default(0);
            $table->decimal('line_total', 14, 2)->default(0);
            $table->decimal('mrp', 14, 2)->nullable();
            $table->unsignedSmallInteger('position')->default(0);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('prescription_lines');
        Schema::dropIfExists('prescriptions');
        Schema::dropIfExists('prescription_patients');
        Schema::dropIfExists('prescriber_registrations');
        Schema::table('users', function (Blueprint $table): void {
            $table->dropColumn('can_prescribe');
        });
    }
};
