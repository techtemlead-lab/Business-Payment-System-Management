<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * An archived supplier leaves the pick lists and stays on its old purchase orders.
     */
    public function up(): void
    {
        Schema::table('suppliers', function (Blueprint $table): void {
            $table->timestamp('archived_at')->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('suppliers', function (Blueprint $table): void {
            $table->dropColumn('archived_at');
        });
    }
};
