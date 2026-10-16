<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('staff_territories', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('staff_id')->constrained('users');
            $table->string('level', 20);
            foreach (['division', 'district', 'upazila', 'union'] as $field) {
                $table->string($field, 80)->nullable();
            }
            $table->string('territory_key', 64)->nullable()->unique();
            $table->boolean('is_active')->default(true);
            $table->foreignId('created_by')->constrained('users');
            $table->timestamps();
            $table->index(['staff_id', 'is_active']);
        });
        Schema::create('staff_targets', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('staff_id')->constrained('users');
            $table->string('period_month', 7);
            $table->decimal('target', 14, 2);
            $table->decimal('bonus', 14, 2)->default(0);
            $table->json('territories');
            $table->foreignId('created_by')->constrained('users');
            $table->unsignedInteger('version')->default(1);
            $table->timestamps();
            $table->unique(['staff_id', 'period_month']);
        });
        Schema::create('dealer_applications', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->foreignId('submitted_by')->constrained('users');
            $table->string('email', 254);
            $table->json('data');
            $table->string('status', 20)->default('pending');
            $table->foreignId('reviewed_by')->nullable()->constrained('users');
            $table->foreignId('user_id')->nullable()->constrained('users');
            $table->text('reason')->nullable();
            $table->timestamps();
            $table->index(['submitted_by', 'status']);
        });
        Schema::table('orders', fn (Blueprint $table) => $table->foreignId('submitted_by')->nullable()->constrained('users'));
        Schema::create('staff_contracts', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('staff_id')->constrained('users');
            $table->string('effective_month', 7);
            $table->unsignedInteger('revision')->default(1);
            $table->decimal('base_salary', 14, 2);
            $table->decimal('allowance', 14, 2)->default(0);
            $table->decimal('deduction', 14, 2)->default(0);
            $table->text('reason');
            $table->foreignId('created_by')->constrained('users');
            $table->timestamps();
            $table->unique(['staff_id', 'effective_month', 'revision']);
        });
        Schema::create('payroll_entries', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->foreignId('staff_id')->constrained('users');
            $table->foreignId('contract_id')->constrained('staff_contracts');
            $table->string('period_month', 7);
            $table->json('snapshot');
            foreach (['gross', 'deduction', 'net'] as $field) {
                $table->decimal($field, 14, 2);
            }
            $table->string('status', 20)->default('draft');
            $table->foreignId('prepared_by')->constrained('users');
            $table->foreignId('approved_by')->nullable()->constrained('users');
            $table->foreignId('paid_by')->nullable()->constrained('users');
            $table->foreignId('payment_account_id')->nullable()->constrained('payment_accounts');
            $table->string('payment_reference', 100)->nullable()->unique();
            $table->timestamp('paid_at')->nullable();
            $table->string('receipt_path')->nullable();
            $table->string('receipt_hash', 64)->nullable();
            $table->timestamps();
            $table->index(['staff_id', 'period_month']);
        });
        Schema::create('payroll_events', function (Blueprint $table): void {
            $table->id();
            $table->foreignUuid('payroll_id')->constrained('payroll_entries');
            $table->foreignId('actor_id')->constrained('users');
            $table->string('action', 30);
            $table->text('reason');
            $table->timestamp('created_at')->useCurrent();
        });
        if (DB::getDriverName() === 'pgsql') {
            DB::statement("CREATE UNIQUE INDEX pending_dealer_application_email ON dealer_applications (LOWER(email)) WHERE status = 'pending'");
            DB::statement("CREATE UNIQUE INDEX active_payroll_staff_month ON payroll_entries (staff_id, period_month) WHERE status IN ('draft','approved','paid')");
            DB::statement('ALTER TABLE staff_targets ADD CONSTRAINT staff_target_amounts CHECK (target > 0 AND bonus >= 0)');
            DB::statement('ALTER TABLE staff_contracts ADD CONSTRAINT staff_contract_amounts CHECK (base_salary >= 0 AND allowance >= 0 AND deduction >= 0 AND deduction <= base_salary + allowance)');
            DB::statement('ALTER TABLE payroll_entries ADD CONSTRAINT payroll_amounts CHECK (gross >= 0 AND deduction >= 0 AND net > 0 AND gross = deduction + net)');
        }
    }

    public function down(): void
    {
        foreach (['payroll_events', 'payroll_entries', 'staff_contracts', 'dealer_applications', 'staff_targets', 'staff_territories'] as $table) {
            Schema::dropIfExists($table);
        }
        Schema::table('orders', fn (Blueprint $table) => $table->dropConstrainedForeignId('submitted_by'));
    }
};
