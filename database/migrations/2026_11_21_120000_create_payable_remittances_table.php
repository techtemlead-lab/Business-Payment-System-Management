<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Money the company held back and owes to someone else: tax withheld from payouts (paid to the
 * tax office) and deductions taken from salaries (paid on to whoever they are for). One person
 * records the payment, a second person checks it; only then does the journal post.
 * A new table only: nothing existing is read or changed.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('payable_remittances', function (Blueprint $table): void {
            $table->id();
            $table->string('kind', 30);
            $table->decimal('amount', 14, 2);
            $table->date('paid_on');
            $table->string('reference', 100)->nullable();
            $table->text('note');
            $table->uuid('request_key')->unique();
            $table->string('status', 20)->default('requested');
            $table->foreignId('created_by')->constrained('users');
            $table->foreignId('decided_by')->nullable()->constrained('users');
            $table->timestamp('decided_at')->nullable();
            $table->text('decision_reason')->nullable();
            $table->timestamps();
            $table->index(['kind', 'status']);
        });
        if (DB::getDriverName() === 'pgsql') {
            DB::statement("ALTER TABLE payable_remittances ADD CONSTRAINT payable_remittances_kind_check CHECK (kind IN ('withheld_tax', 'payroll_deductions'))");
            DB::statement("ALTER TABLE payable_remittances ADD CONSTRAINT payable_remittances_status_check CHECK (status IN ('requested', 'paid', 'rejected', 'cancelled'))");
            DB::statement('ALTER TABLE payable_remittances ADD CONSTRAINT payable_remittances_amount_check CHECK (amount > 0)');
            DB::statement("CREATE UNIQUE INDEX payable_remittances_live_reference ON payable_remittances (kind, UPPER(reference)) WHERE reference IS NOT NULL AND status IN ('requested', 'paid')");
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('payable_remittances');
    }
};
