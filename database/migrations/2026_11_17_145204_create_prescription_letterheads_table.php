<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * The header and footer of a prescriber's printed prescription: one row per prescriber,
 * Bangla block on the left and English block on the right of the paper. A blank field falls
 * back to the default (the company's for the owner-doctor), so no row is needed to print.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('prescription_letterheads', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('user_id')->unique()->constrained()->cascadeOnDelete();
            foreach (['bn', 'en'] as $lang) {
                $table->string('doctor_name_'.$lang, 120)->nullable();
                $table->string('degrees_'.$lang, 255)->nullable();
                $table->string('specialities_'.$lang, 255)->nullable();
                $table->string('registration_'.$lang, 160)->nullable();
                $table->string('chamber_'.$lang, 500)->nullable();
                $table->string('footer_address_'.$lang, 500)->nullable();
                $table->string('footer_phone_'.$lang, 255)->nullable();
                $table->string('visiting_hours_'.$lang, 255)->nullable();
                $table->string('footer_note_'.$lang, 500)->nullable();
            }
            $table->string('logo_path')->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('prescription_letterheads');
    }
};
