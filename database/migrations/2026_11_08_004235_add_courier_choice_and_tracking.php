<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Stream F: courier auto-booking and live tracking.
 * - Orders remember the courier the dealer picked (null means "the company decides").
 * - Shipments remember where the parcel is with the courier (waiting for pickup, picked up,
 *   in transit, delivered or returned), the courier's own wording, when it was last checked,
 *   and a short timeline of every change.
 * All new columns are optional, so older orders and shipments keep working.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('orders', function (Blueprint $table): void {
            $table->string('courier_choice', 20)->nullable();
        });
        Schema::table('shipments', function (Blueprint $table): void {
            $table->string('courier_status', 20)->nullable();
            $table->string('courier_status_raw', 120)->nullable();
            $table->timestamp('courier_status_at')->nullable();
            $table->timestamp('courier_tracked_at')->nullable();
            $table->json('courier_timeline')->nullable();
            $table->index(['courier_booking_status', 'courier_status']);
        });
    }

    public function down(): void
    {
        Schema::table('shipments', function (Blueprint $table): void {
            $table->dropIndex(['courier_booking_status', 'courier_status']);
            $table->dropColumn(['courier_status', 'courier_status_raw', 'courier_status_at', 'courier_tracked_at', 'courier_timeline']);
        });
        Schema::table('orders', function (Blueprint $table): void {
            $table->dropColumn('courier_choice');
        });
    }
};
