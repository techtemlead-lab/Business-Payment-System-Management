<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Payout controls after the 2026-11-18 company test:
 * - on_hold_at: open withdrawals of a suspended partner are put on hold (owner rule Q28 / D4);
 * - approved_at and payer_id: every approved payout has an age and a named person who pays it;
 * - receipt_due: a payout recorded as paid with the transfer proof still to be attached;
 * - users.tin: the partner's tax number for the payout tax register (encrypted by the model).
 *
 * Only adds nullable columns and back-fills approved_at from the existing review history, so it is
 * safe on live data.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('withdrawals', function (Blueprint $table): void {
            $table->timestamp('on_hold_at')->nullable();
            $table->timestamp('approved_at')->nullable();
            $table->foreignId('payer_id')->nullable()->constrained('users');
            $table->boolean('receipt_due')->default(false);
            $table->index('payer_id');
            $table->index(['status', 'approved_at']);
        });

        Schema::table('users', function (Blueprint $table): void {
            $table->text('tin')->nullable();
        });

        // A partner's payment picture that looks blank or tiny is flagged for the checker, not refused.
        Schema::table('payment_claims', function (Blueprint $table): void {
            $table->boolean('proof_looks_blank')->default(false);
        });

        DB::statement("UPDATE withdrawals SET approved_at = (SELECT MAX(e.created_at) FROM withdrawal_events e WHERE e.withdrawal_id = withdrawals.id AND e.event = 'approved') WHERE status IN ('approved', 'paid') AND approved_at IS NULL");
        DB::statement("UPDATE withdrawals SET approved_at = updated_at WHERE status IN ('approved', 'paid') AND approved_at IS NULL");
    }

    public function down(): void
    {
        Schema::table('payment_claims', fn (Blueprint $table) => $table->dropColumn('proof_looks_blank'));
        Schema::table('users', fn (Blueprint $table) => $table->dropColumn('tin'));
        Schema::table('withdrawals', function (Blueprint $table): void {
            $table->dropIndex(['status', 'approved_at']);
            $table->dropIndex(['payer_id']);
            $table->dropConstrainedForeignId('payer_id');
            $table->dropColumn(['on_hold_at', 'approved_at', 'receipt_due']);
        });
    }
};
