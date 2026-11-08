<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Health concern tags the owner puts on products so visitors can browse by them.
 * Starts empty: no tag is created until the owner adds one.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('product_concerns', function (Blueprint $table): void {
            $table->id();
            $table->string('name_bn', 80)->unique();
            $table->string('name_en', 80)->unique();
            $table->timestamps();
        });

        Schema::create('product_concern_product', function (Blueprint $table): void {
            $table->foreignId('product_concern_id')->constrained()->cascadeOnDelete();
            $table->foreignId('product_id')->constrained()->cascadeOnDelete();
            $table->primary(['product_concern_id', 'product_id']);
            $table->index('product_id');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('product_concern_product');
        Schema::dropIfExists('product_concerns');
    }
};
