<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('users', function (Blueprint $t) {
            $t->string('role')->default('dealer')->index();
            $t->text('totp_secret')->nullable();
            $t->json('recovery_codes')->nullable();
        });
        Schema::create('dealers', function (Blueprint $t) {
            $t->id();
            $t->foreignId('user_id')->unique()->constrained()->restrictOnDelete();
            $t->string('code')->unique();
            $t->string('name_bn')->nullable();
            $t->string('business_name')->nullable();
            $t->enum('level', ['Divisional', 'District', 'Upazila / Thana', 'Union']);
            $t->string('division');
            $t->string('district')->nullable();
            $t->string('upazila')->nullable();
            $t->string('union_name')->nullable();
            $t->string('mobile', 20)->nullable();
            $t->enum('status', ['pending', 'active', 'suspended', 'deactivated'])->default('pending')->index();
            $t->timestamps();
            $t->index(['division', 'level']);
        });
        Schema::create('business_config', function (Blueprint $t) {
            $t->unsignedInteger('id')->primary();
            $t->string('company_name')->default('Nexora');
            $t->string('contact_email')->nullable();
            $t->string('contact_phone')->nullable();
            $t->enum('mode', ['COMMISSION_BASED', 'RANK_BASED'])->default('COMMISSION_BASED');
            $t->unsignedInteger('mode_revision')->default(1);
            $t->timestamps();
        });
        Schema::create('rule_versions', function (Blueprint $t) {
            $t->id();
            $t->string('name');
            $t->enum('status', ['reference', 'draft']);
            $t->json('definition');
            $t->date('effective_from')->nullable();
            $t->date('effective_to')->nullable();
            $t->text('reason');
            $t->foreignId('actor_id')->nullable()->constrained('users')->restrictOnDelete();
            $t->timestamps();
        });
        Schema::create('rank_definitions', function (Blueprint $t) {
            $t->id();
            $t->string('name')->unique();
            $t->text('eligibility');
            $t->enum('status', ['draft', 'approved'])->default('draft');
            $t->string('effect')->default('status_only');
            $t->foreignId('actor_id')->constrained('users')->restrictOnDelete();
            $t->timestamps();
        });
        Schema::create('ledger_entries', function (Blueprint $t) {
            $t->id();
            $t->foreignId('dealer_id')->constrained()->restrictOnDelete();
            $t->bigInteger('amount_minor');
            $t->enum('kind', ['earned', 'reserved', 'released', 'paid', 'reversal']);
            $t->string('currency', 3)->default('BDT');
            $t->string('business_reference')->unique();
            $t->foreignId('rule_version_id')->nullable()->constrained()->restrictOnDelete();
            $t->foreignId('reversal_of')->nullable()->unique()->constrained('ledger_entries')->restrictOnDelete();
            $t->foreignId('actor_id')->nullable()->constrained('users')->restrictOnDelete();
            $t->text('reason');
            $t->timestamp('created_at');
            $t->index(['dealer_id', 'created_at']);
        });
        Schema::create('payouts', function (Blueprint $t) {
            $t->id();
            $t->foreignId('dealer_id')->constrained()->restrictOnDelete();
            $t->bigInteger('amount_minor');
            $t->enum('status', ['pending', 'processing', 'succeeded', 'failed', 'cancelled', 'needs_reconciliation'])->default('pending');
            $t->string('idempotency_key')->unique();
            $t->string('provider_reference')->nullable()->unique();
            $t->timestamps();
        });
        Schema::create('audit_events', function (Blueprint $t) {
            $t->id();
            $t->foreignId('actor_id')->nullable()->constrained('users')->restrictOnDelete();
            $t->string('action')->index();
            $t->unsignedBigInteger('subject_id')->nullable();
            $t->json('details');
            $t->timestamp('created_at')->index();
        });
        DB::table('business_config')->insert(['id' => 1, 'company_name' => 'Nexora', 'mode' => 'COMMISSION_BASED', 'mode_revision' => 1, 'created_at' => now(), 'updated_at' => now()]);
        if (DB::getDriverName() === 'pgsql') {
            DB::statement('ALTER TABLE business_config ADD CONSTRAINT singleton_config CHECK (id = 1)');
            DB::statement('ALTER TABLE payouts ADD CONSTRAINT positive_payout CHECK (amount_minor > 0)');
            DB::unprepared("CREATE OR REPLACE FUNCTION reject_history_mutation() RETURNS trigger LANGUAGE plpgsql AS $$ BEGIN RAISE EXCEPTION 'Historical records are immutable'; END; $$");
            foreach (['ledger_entries', 'audit_events', 'rule_versions'] as $table) {
                DB::unprepared("CREATE TRIGGER immutable_{$table} BEFORE UPDATE OR DELETE ON {$table} FOR EACH ROW EXECUTE FUNCTION reject_history_mutation()");
            }
        }
    }

    public function down(): void
    {
        foreach (['audit_events', 'payouts', 'ledger_entries', 'rank_definitions', 'rule_versions', 'business_config', 'dealers'] as $t) {
            Schema::dropIfExists($t);
        }
        if (DB::getDriverName() === 'pgsql') {
            DB::statement('DROP FUNCTION IF EXISTS reject_history_mutation()');
        }
        Schema::table('users', fn (Blueprint $t) => $t->dropColumn(['role', 'totp_secret', 'recovery_codes']));
    }
};
