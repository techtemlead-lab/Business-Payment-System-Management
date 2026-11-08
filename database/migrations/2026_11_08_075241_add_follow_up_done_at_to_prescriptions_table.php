<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Prescription module, phase 2: a prescriber can close a follow-up that needs no new
 * prescription, and the due and overdue lists are read per prescriber by date.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('prescriptions', function (Blueprint $table): void {
            $table->timestamp('follow_up_done_at')->nullable();
            $table->index(['prescriber_id', 'follow_up_on']);
        });
    }

    public function down(): void
    {
        Schema::table('prescriptions', function (Blueprint $table): void {
            $table->dropIndex(['prescriber_id', 'follow_up_on']);
            $table->dropColumn('follow_up_done_at');
        });
    }
};
