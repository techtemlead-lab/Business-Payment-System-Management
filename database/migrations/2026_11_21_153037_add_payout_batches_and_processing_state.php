<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * A bank or wallet file marks the withdrawals it lists as "in a bank file" (processing), so a
 * second download or a later month's file cannot list them again.
 *
 * - payout_batches: one row per file sent to the bank, keyed by the id the page sends, so pressing
 *   the button twice gives the same file instead of a second one.
 * - withdrawals.payout_batch_id / exported_at: set when a file lists the withdrawal. An approved
 *   withdrawal with exported_at set is in processing until it is recorded as paid or marked as
 *   not sent by the bank.
 *
 * Only adds a table and nullable columns: existing withdrawals stay outside any file (null), as
 * before. Reversible.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('payout_batches', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->string('month', 7);
            $table->string('channel')->nullable();
            $table->unsignedInteger('row_count');
            $table->decimal('total_net', 14, 2);
            $table->foreignId('created_by')->constrained('users');
            $table->timestamp('created_at')->useCurrent();
            $table->index('created_at');
            $table->index('created_by');
        });

        Schema::table('withdrawals', function (Blueprint $table): void {
            $table->foreignUuid('payout_batch_id')->nullable()->constrained('payout_batches');
            $table->timestamp('exported_at')->nullable();
            $table->index('payout_batch_id');
        });
    }

    public function down(): void
    {
        Schema::table('withdrawals', function (Blueprint $table): void {
            $table->dropIndex(['payout_batch_id']);
            $table->dropConstrainedForeignId('payout_batch_id');
            $table->dropColumn('exported_at');
        });
        Schema::dropIfExists('payout_batches');
    }
};
