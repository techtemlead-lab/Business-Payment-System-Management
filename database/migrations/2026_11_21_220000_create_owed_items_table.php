<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Goods a partner paid for in full that the company could not send (stock short at dispatch).
     * The pieces are owed: they go out free with the partner's next order, or their value is
     * refunded. The source order line is closed for those pieces so nothing is counted twice.
     */
    public function up(): void
    {
        Schema::create('owed_items', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('partner_id')->constrained('users');
            $table->foreignId('product_id')->constrained('products');
            $table->unsignedInteger('quantity');
            $table->decimal('unit_price', 14, 2);
            $table->foreignUuid('source_order_id')->constrained('orders');
            $table->foreignId('source_line_id')->unique()->constrained('order_lines');
            $table->string('status', 20)->default('open');
            $table->string('note', 500)->nullable();
            $table->foreignId('created_by')->constrained('users');
            $table->foreignUuid('shipped_in_order_id')->nullable()->constrained('orders');
            $table->foreignId('shipped_line_id')->nullable()->constrained('order_lines')->nullOnDelete();
            $table->foreignId('closed_by')->nullable()->constrained('users');
            $table->foreignId('refund_request_id')->nullable()->constrained('order_return_requests')->nullOnDelete();
            $table->timestamps();
            $table->index(['partner_id', 'status']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('owed_items');
    }
};
