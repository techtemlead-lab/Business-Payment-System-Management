<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * "The bank did not send it" takes two people: the first person's step waits here until a
 * second finance person confirms it (the owner finishes alone). Nullable columns only. Reversible.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('withdrawals', function (Blueprint $table): void {
            $table->foreignId('not_sent_requested_by')->nullable()->constrained('users');
            $table->timestamp('not_sent_requested_at')->nullable();
            $table->text('not_sent_note')->nullable();
            $table->index('not_sent_requested_by');
        });
    }

    public function down(): void
    {
        Schema::table('withdrawals', function (Blueprint $table): void {
            $table->dropIndex(['not_sent_requested_by']);
            $table->dropConstrainedForeignId('not_sent_requested_by');
            $table->dropColumn(['not_sent_requested_at', 'not_sent_note']);
        });
    }
};
