<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Courier bookings now happen after the dispatch is saved, so each shipment
     * remembers which courier to book and how far the booking got.
     */
    public function up(): void
    {
        Schema::table('shipments', function (Blueprint $table): void {
            $table->string('courier_provider', 20)->nullable();
            $table->string('courier_booking_status', 20)->nullable();
            $table->string('courier_consignment_id', 100)->nullable();
            $table->string('courier_booking_error', 255)->nullable();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('shipments', function (Blueprint $table): void {
            $table->dropColumn(['courier_provider', 'courier_booking_status', 'courier_consignment_id', 'courier_booking_error']);
        });
    }
};
