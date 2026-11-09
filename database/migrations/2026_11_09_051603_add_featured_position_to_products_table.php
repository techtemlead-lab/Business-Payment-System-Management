<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    private const FirstProductsToKeepShowing = 20;

    /**
     * Lets the owner set the order of the home page products, and numbers today's products.
     *
     * The home page used to fill up with any active product. It now shows only featured ones,
     * so when nothing is featured the first 20 by name are marked, the same ones shown today.
     */
    public function up(): void
    {
        Schema::table('products', function (Blueprint $table) {
            $table->integer('featured_position')->nullable()->after('featured');
        });

        $activeProducts = DB::table('products')->where('active', true)->orderBy('name')->orderBy('id');
        $productIds = (clone $activeProducts)->where('featured', true)->pluck('id');
        if ($productIds->isEmpty()) {
            $productIds = (clone $activeProducts)->limit(self::FirstProductsToKeepShowing)->pluck('id');
        }

        foreach ($productIds->values() as $index => $productId) {
            DB::table('products')->where('id', $productId)->update(['featured' => true, 'featured_position' => $index + 1]);
        }
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('products', function (Blueprint $table) {
            $table->dropColumn('featured_position');
        });
    }
};
