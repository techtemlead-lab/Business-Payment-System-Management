<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Dated schemes the owner defines and partners read at order time.
     * Information only: nothing here changes an order's price, lines or commission.
     */
    public function up(): void
    {
        Schema::create('dated_schemes', function (Blueprint $table): void {
            $table->id();
            $table->string('title', 150);
            $table->date('starts_on');
            $table->date('ends_on');
            $table->json('product_ids');
            $table->unsignedInteger('min_quantity');
            $table->string('benefit_type', 20);
            $table->string('benefit_text', 500);
            $table->foreignId('created_by')->constrained('users');
            $table->timestamps();
            $table->index(['starts_on', 'ends_on']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('dated_schemes');
    }
};
