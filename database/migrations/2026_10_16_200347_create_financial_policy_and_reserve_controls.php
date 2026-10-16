<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('commission_policy_schedules', function (Blueprint $table): void {
            $table->id();
            $table->string('scope', 20)->default('global');
            $table->foreignId('user_id')->nullable()->constrained('users');
            $table->json('rules');
            $table->timestamp('effective_at');
            $table->timestamp('expires_at')->nullable();
            $table->string('status', 20)->default('draft');
            $table->foreignId('created_by')->constrained('users');
            $table->foreignId('approved_by')->nullable()->constrained('users');
            $table->timestamp('approved_at')->nullable();
            $table->text('reason');
            $table->timestamps();
            $table->index(['scope', 'user_id', 'status', 'effective_at'], 'policy_schedule_lookup');
        });
        Schema::table('commission_policy_revisions', fn (Blueprint $table) => $table->foreignId('schedule_id')->nullable()->constrained('commission_policy_schedules'));
        Schema::table('commission_settlement_payouts', fn (Blueprint $table) => $table->decimal('refunded_basis', 14, 2)->default(0));
        Schema::create('reserve_disbursements', function (Blueprint $table): void {
            $table->id();
            $table->string('program', 32);
            $table->foreignId('user_id')->nullable()->constrained('users');
            $table->string('payee_name', 150)->nullable();
            $table->decimal('amount', 14, 2);
            $table->string('status', 20)->default('requested');
            $table->string('request_key', 80)->unique();
            $table->string('award_key', 80)->nullable()->unique();
            $table->text('reason');
            $table->foreignId('created_by')->constrained('users');
            $table->foreignId('approved_by')->nullable()->constrained('users');
            $table->foreignId('paid_by')->nullable()->constrained('users');
            $table->foreignId('payment_account_id')->nullable()->constrained('payment_accounts');
            $table->string('payment_reference', 100)->nullable()->unique();
            $table->timestamp('paid_at')->nullable();
            $table->string('receipt_path')->nullable();
            $table->string('receipt_hash', 64)->nullable();
            $table->timestamps();
            $table->index(['program', 'status']);
        });
        Schema::create('reserve_expense_sources', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('disbursement_id')->constrained('reserve_disbursements');
            $table->foreignId('allocation_id')->constrained('commission_allocations');
            $table->decimal('amount', 14, 2);
            $table->decimal('reversed_amount', 14, 2)->default(0);
            $table->decimal('refunded_basis', 14, 2)->default(0);
            $table->timestamps();
            $table->unique(['disbursement_id', 'allocation_id']);
        });
        Schema::create('reserve_expense_clawbacks', function (Blueprint $table): void {
            $table->id();
            $table->foreignUuid('refund_id')->constrained('commission_refunds');
            $table->foreignId('source_id')->constrained('reserve_expense_sources');
            $table->decimal('amount', 14, 2);
            $table->decimal('recovered', 14, 2)->default(0);
            $table->timestamps();
            $table->unique(['refund_id', 'source_id']);
        });
        Schema::create('reserve_recoveries', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('clawback_id')->constrained('reserve_expense_clawbacks');
            $table->decimal('amount', 14, 2);
            $table->string('payment_reference', 100)->unique();
            $table->foreignId('payment_account_id')->constrained('payment_accounts');
            $table->foreignId('actor_id')->constrained('users');
            $table->string('receipt_path');
            $table->string('receipt_hash', 64);
            $table->timestamp('paid_at');
            $table->text('reason');
            $table->timestamps();
        });
        Schema::create('financial_verifications', function (Blueprint $table): void {
            $table->id();
            $table->string('fingerprint', 64);
            $table->json('snapshot');
            $table->json('expected_totals');
            $table->string('source_reference', 150);
            $table->string('evidence_path');
            $table->string('evidence_hash', 64);
            $table->foreignId('created_by')->constrained('users');
            $table->foreignId('verified_by')->nullable()->constrained('users');
            $table->timestamp('verified_at')->nullable();
            $table->timestamps();
        });
        if (DB::getDriverName() === 'pgsql') {
            DB::statement('ALTER TABLE reserve_disbursements ADD CONSTRAINT reserve_disbursement_positive CHECK (amount > 0)');
            DB::statement('ALTER TABLE reserve_expense_sources ADD CONSTRAINT reserve_source_amounts CHECK (amount > 0 AND reversed_amount >= 0 AND reversed_amount <= amount)');
            DB::statement('ALTER TABLE reserve_expense_clawbacks ADD CONSTRAINT reserve_clawback_amounts CHECK (amount > 0 AND recovered >= 0 AND recovered <= amount)');
        }
    }

    public function down(): void
    {
        Schema::table('commission_policy_revisions', fn (Blueprint $table) => $table->dropConstrainedForeignId('schedule_id'));
        Schema::table('commission_settlement_payouts', fn (Blueprint $table) => $table->dropColumn('refunded_basis'));
        foreach (['financial_verifications', 'reserve_recoveries', 'reserve_expense_clawbacks', 'reserve_expense_sources', 'reserve_disbursements', 'commission_policy_schedules'] as $table) {
            Schema::dropIfExists($table);
        }
    }
};
