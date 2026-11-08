<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Facts the owner enters for each product. All nullable: existing products stay
 * blank until the owner fills them in, and the website hides whatever is blank.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('products', function (Blueprint $table): void {
            $table->string('manufacturer_bn', 200)->nullable();
            $table->string('manufacturer_en', 200)->nullable();
            $table->string('product_system_bn', 120)->nullable();
            $table->string('product_system_en', 120)->nullable();
            $table->string('registration_number', 120)->nullable();
            $table->string('pack_size_bn', 120)->nullable();
            $table->string('pack_size_en', 120)->nullable();
            $table->text('ingredients_bn')->nullable();
            $table->text('ingredients_en')->nullable();
            $table->text('dose_bn')->nullable();
            $table->text('dose_en')->nullable();
            $table->text('warnings_bn')->nullable();
            $table->text('warnings_en')->nullable();
            $table->text('storage_bn')->nullable();
            $table->text('storage_en')->nullable();
            $table->text('quality_points_bn')->nullable();
            $table->text('quality_points_en')->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('products', function (Blueprint $table): void {
            $table->dropColumn([
                'manufacturer_bn', 'manufacturer_en', 'product_system_bn', 'product_system_en', 'registration_number',
                'pack_size_bn', 'pack_size_en', 'ingredients_bn', 'ingredients_en', 'dose_bn', 'dose_en',
                'warnings_bn', 'warnings_en', 'storage_bn', 'storage_en', 'quality_points_bn', 'quality_points_en',
            ]);
        });
    }
};
