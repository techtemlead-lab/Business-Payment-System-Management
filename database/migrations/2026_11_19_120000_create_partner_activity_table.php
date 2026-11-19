<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * CR-17: the latest sign-in details of each dealer and sub-dealer, for the owner's analysis page.
 * One row per partner, overwritten in place: only the latest IP and device are kept, never a history.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('partner_activity', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('user_id')->unique()->constrained()->cascadeOnDelete();
            $table->timestamp('last_seen_at')->index();
            $table->string('last_ip', 45)->nullable();
            $table->string('device_kind', 10)->nullable();
            $table->string('browser', 40)->nullable();
            $table->string('os', 40)->nullable();
            $table->timestamp('last_login_at')->nullable();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('partner_activity');
    }
};
