<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('purchases', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('dealer_id')->constrained()->restrictOnDelete();
            $table->string('reference', 80)->unique();
            $table->string('product_name', 160);
            $table->unsignedInteger('quantity');
            $table->bigInteger('unit_mrp_minor');
            $table->bigInteger('total_mrp_minor');
            $table->bigInteger('amount_paid_minor');
            $table->date('purchased_on');
            $table->foreignId('rule_version_id')->nullable()->constrained()->restrictOnDelete();
            $table->foreignId('ledger_entry_id')->nullable()->constrained('ledger_entries')->restrictOnDelete();
            $table->enum('status', ['recorded', 'cancelled'])->default('recorded');
            $table->json('calculation');
            foreach (['company_minor', 'dealer_minor', 'pool_minor', 'allocated_pool_minor', 'unallocated_pool_minor'] as $field) {
                $table->bigInteger($field);
            }
            $table->string('note', 500)->nullable();
            $table->foreignId('actor_id')->constrained('users')->restrictOnDelete();
            $table->foreignId('cancelled_by')->nullable()->constrained('users')->restrictOnDelete();
            $table->string('cancellation_reason', 500)->nullable();
            $table->timestamps();
            $table->index(['dealer_id', 'id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('purchases');
    }
};
