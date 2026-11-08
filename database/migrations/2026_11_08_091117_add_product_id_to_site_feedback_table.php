<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Lets a feedback row be a review of one product. Existing rows stay general site
 * feedback (no product), so they keep their place on the homepage.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('site_feedback', function (Blueprint $table): void {
            $table->foreignId('product_id')->nullable()->constrained()->cascadeOnDelete();
            $table->index(['product_id', 'status']);
        });
    }

    public function down(): void
    {
        Schema::table('site_feedback', function (Blueprint $table): void {
            $table->dropIndex(['product_id', 'status']);
            $table->dropConstrainedForeignId('product_id');
        });
    }
};
