<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Marks the batch of a partly delivered order where staff chose no commission. Its money still
 * has to add up, but the order counts for no monthly, bike, car or rank volume.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('commission_batches', function (Blueprint $table): void {
            $table->boolean('no_commission')->default(false);
        });
    }

    public function down(): void
    {
        Schema::table('commission_batches', function (Blueprint $table): void {
            $table->dropColumn('no_commission');
        });
    }
};
