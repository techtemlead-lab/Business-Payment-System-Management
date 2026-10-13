<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::table('orders', function (Blueprint $table) {
            $table->foreignId('credit_authorized_by')->nullable()->constrained('users');
            $table->timestamp('credit_authorized_at')->nullable();
            $table->index(['user_id', 'created_at']);
        });
        Schema::table('shipments', function (Blueprint $table) {
            $table->uuid('idempotency_key')->nullable();
            $table->string('payload_hash', 64)->nullable();
            $table->timestamp('delivered_at')->nullable();
            $table->foreignId('delivered_by')->nullable()->constrained('users');
            $table->unique(['order_id', 'idempotency_key']);
        });
        Schema::create('order_events', function (Blueprint $table) {
            $table->id();
            $table->foreignUuid('order_id')->constrained();
            $table->foreignId('actor_id')->nullable()->constrained('users');
            $table->string('action');
            $table->json('metadata')->nullable();
            $table->timestamp('created_at')->useCurrent();
            $table->index(['order_id', 'created_at']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('order_events');
        Schema::table('shipments', function (Blueprint $table) {
            $table->dropUnique(['order_id', 'idempotency_key']);
            $table->dropConstrainedForeignId('delivered_by');
            $table->dropColumn(['idempotency_key', 'payload_hash', 'delivered_at']);
        });
        Schema::table('orders', function (Blueprint $table) {
            $table->dropIndex(['user_id', 'created_at']);
            $table->dropConstrainedForeignId('credit_authorized_by');
            $table->dropColumn('credit_authorized_at');
        });
    }
};
