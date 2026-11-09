<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * When the courier last gave a real answer about a parcel. The existing courier_tracked_at
 * only says when we last asked, which also changes when the courier is down, so it cannot
 * tell a dealer how fresh the status is. Optional, so older shipments keep working.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('shipments', function (Blueprint $table): void {
            $table->timestamp('courier_answered_at')->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('shipments', function (Blueprint $table): void {
            $table->dropColumn('courier_answered_at');
        });
    }
};
