<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Money sent back to a dealer for a paid order that was never delivered.
     * One refund per order: the unique order_id stops a second refund.
     */
    public function up(): void
    {
        Schema::create('order_refunds', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('order_id')->unique()->constrained();
            $table->foreignId('user_id')->index()->constrained();
            $table->decimal('amount', 14, 2);
            $table->decimal('paid_before', 14, 2);
            $table->text('reason');
            $table->foreignId('refunded_by')->index()->constrained('users');
            $table->timestamps();
            $table->index('created_at');
        });
        DB::statement('ALTER TABLE order_refunds ADD CONSTRAINT order_refunds_amount_check CHECK (amount > 0 AND amount <= paid_before)');
    }

    public function down(): void
    {
        Schema::dropIfExists('order_refunds');
    }
};
