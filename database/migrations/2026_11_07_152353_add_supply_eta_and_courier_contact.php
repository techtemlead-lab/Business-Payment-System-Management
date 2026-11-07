<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Contract D03 / A07.
 * - Back-order date: when some items of an order are not in stock yet, the store team records
 *   when they expect to send the rest, with a short note. The partner sees it on the order.
 * - Courier contact: the courier's phone or slip number for a sent parcel, shown with the
 *   courier name and tracking number.
 * All new columns are optional.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('orders', function (Blueprint $table): void {
            $table->date('supply_eta')->nullable();
            $table->string('supply_note', 300)->nullable();
        });
        Schema::table('shipments', function (Blueprint $table): void {
            $table->string('courier_contact', 120)->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('orders', function (Blueprint $table): void {
            $table->dropColumn(['supply_eta', 'supply_note']);
        });
        Schema::table('shipments', function (Blueprint $table): void {
            $table->dropColumn('courier_contact');
        });
    }
};
