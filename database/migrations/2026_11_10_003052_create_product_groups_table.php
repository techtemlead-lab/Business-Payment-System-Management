<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Ties sizes of one product together for display and choosing only. Every size stays its own
     * product. The link starts empty, so existing products are not grouped and behave as before.
     */
    public function up(): void
    {
        Schema::create('product_groups', function (Blueprint $table): void {
            $table->id();
            $table->timestamps();
        });

        Schema::table('products', function (Blueprint $table): void {
            $table->foreignId('product_group_id')->nullable()->after('active')->constrained('product_groups')->nullOnDelete();
            $table->index('product_group_id');
        });
    }

    public function down(): void
    {
        Schema::table('products', function (Blueprint $table): void {
            $table->dropConstrainedForeignId('product_group_id');
        });

        Schema::dropIfExists('product_groups');
    }
};
