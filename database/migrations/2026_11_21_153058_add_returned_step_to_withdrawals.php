<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * "Money came back" for a paid withdrawal: a transfer that bounced or went to a wrong number is
 * recorded by one finance person and confirmed by a second (the owner finishes alone). The
 * withdrawal then has the status "returned" and the money is back in the partner's wallet.
 *
 * Only adds nullable columns, so existing withdrawals are unchanged. Reversible.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('withdrawals', function (Blueprint $table): void {
            $table->foreignId('return_requested_by')->nullable()->constrained('users');
            $table->timestamp('return_requested_at')->nullable();
            $table->text('return_note')->nullable();
            $table->foreignId('returned_by')->nullable()->constrained('users');
            $table->timestamp('returned_at')->nullable();
            $table->index('return_requested_by');
            $table->index('returned_by');
        });
    }

    public function down(): void
    {
        Schema::table('withdrawals', function (Blueprint $table): void {
            $table->dropIndex(['return_requested_by']);
            $table->dropIndex(['returned_by']);
            $table->dropConstrainedForeignId('return_requested_by');
            $table->dropConstrainedForeignId('returned_by');
            $table->dropColumn(['return_requested_at', 'return_note', 'returned_at']);
        });
    }
};
