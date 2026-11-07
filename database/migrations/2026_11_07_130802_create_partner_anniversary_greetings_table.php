<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * One row per join-anniversary greeting (an in-app notice, never an SMS). The unique
 * (user, year) pair makes sure a partner is greeted at most once a year, even if the job runs again.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('partner_anniversary_greetings', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->unsignedSmallInteger('year');
            $table->timestamp('sent_at');
            $table->unique(['user_id', 'year']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('partner_anniversary_greetings');
    }
};
