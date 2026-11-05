<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Commission rate changes wait for the owner's approval (owner decision, 2026-11-05).
 * A change made in Commission setup is stored as a pending schedule with origin "rule_edit",
 * the old and new values, and the live revision it was made against.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('commission_policy_schedules', function (Blueprint $table): void {
            $table->string('origin', 20)->default('schedule');
            $table->json('changes')->nullable();
            $table->foreignId('base_revision_id')->nullable()->constrained('commission_policy_revisions')->nullOnDelete();
            $table->foreignId('reviewed_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('reviewed_at')->nullable();
            $table->text('review_reason')->nullable();
            $table->index(['status', 'origin']);
        });
    }

    public function down(): void
    {
        Schema::table('commission_policy_schedules', function (Blueprint $table): void {
            $table->dropIndex(['status', 'origin']);
            $table->dropConstrainedForeignId('base_revision_id');
            $table->dropConstrainedForeignId('reviewed_by');
            $table->dropColumn(['origin', 'changes', 'reviewed_at', 'review_reason']);
        });
    }
};
