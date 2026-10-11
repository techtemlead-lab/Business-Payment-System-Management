<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('withdrawal_requests', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('dealer_id')->constrained()->restrictOnDelete();
            $table->bigInteger('amount_minor');
            $table->uuid('idempotency_key')->unique();
            $table->enum('status', ['pending', 'approved', 'declined', 'cancelled'])->default('pending');
            $table->string('note', 500)->nullable();
            $table->string('review_note', 500)->nullable();
            $table->foreignId('reviewed_by')->nullable()->constrained('users')->restrictOnDelete();
            $table->timestamp('reviewed_at')->nullable();
            $table->timestamps();
            $table->index(['dealer_id', 'id']);
            $table->index(['status', 'id']);
        });
        if (DB::getDriverName() === 'pgsql') {
            DB::statement('ALTER TABLE withdrawal_requests ADD CONSTRAINT positive_withdrawal CHECK (amount_minor > 0)');
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('withdrawal_requests');
    }
};
