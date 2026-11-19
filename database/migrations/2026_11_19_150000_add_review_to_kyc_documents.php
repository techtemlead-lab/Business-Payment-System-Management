<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * R5: a partner can send in his own National ID. That paper waits for staff (identity permission) to verify it,
 * and counts for the first withdrawal only once verified. Every paper that exists today was put there by the
 * office, so it keeps counting as verified.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('kyc_documents', function (Blueprint $table): void {
            $table->string('review_status', 12)->default('approved');
            // The number the partner typed, kept encrypted until staff compare it with the photo; then moved to the account.
            $table->text('claimed_nid')->nullable();
            $table->foreignId('reviewed_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('reviewed_at')->nullable();
            $table->string('review_note', 300)->nullable();
            $table->index(['user_id', 'review_status']);
        });
    }

    public function down(): void
    {
        Schema::table('kyc_documents', function (Blueprint $table): void {
            $table->dropIndex(['user_id', 'review_status']);
            $table->dropConstrainedForeignId('reviewed_by');
            $table->dropColumn(['review_status', 'claimed_nid', 'reviewed_at', 'review_note']);
        });
    }
};
