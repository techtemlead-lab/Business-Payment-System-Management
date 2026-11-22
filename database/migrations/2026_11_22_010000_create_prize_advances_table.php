<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * A bike or car prize the owner paid a dealer or sub-dealer in advance, in an emergency, out of that prize's fund
     * (owner decision, 2026-11-22). It is money the partner owes the company until he earns the same prize: the prize is
     * then set against it instead of being paid again. One open advance per partner and prize kind.
     */
    public function up(): void
    {
        Schema::create('prize_advances', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('partner_id')->constrained('users');
            $table->string('kind', 8);
            $table->decimal('amount', 14, 2);
            $table->text('reason');
            $table->date('given_on');
            $table->foreignId('approved_by')->constrained('users');
            $table->string('status', 16)->default('open');
            $table->decimal('settled_amount', 14, 2)->default(0);
            $table->foreignId('settled_by_award_id')->nullable()->constrained('reserve_disbursements');
            $table->timestamp('settled_at')->nullable();
            $table->decimal('closed_amount', 14, 2)->nullable();
            $table->string('recovery_reference', 100)->nullable();
            $table->text('note')->nullable();
            $table->foreignId('closed_by')->nullable()->constrained('users');
            $table->timestamp('closed_at')->nullable();
            $table->string('close_request_key', 80)->nullable()->unique();
            // The fund draw behind the advance: a reserve_disbursements row (program prize_advance) with its fund sources.
            $table->foreignId('disbursement_id')->unique()->constrained('reserve_disbursements');
            $table->string('request_key', 80)->unique();
            $table->timestamps();
            $table->index(['partner_id', 'kind', 'status']);
            $table->index(['status', 'given_on']);
        });
        if (DB::getDriverName() === 'pgsql') {
            DB::statement("ALTER TABLE prize_advances ADD CONSTRAINT prize_advance_kind CHECK (kind IN ('bike', 'car'))");
            DB::statement("ALTER TABLE prize_advances ADD CONSTRAINT prize_advance_status CHECK (status IN ('open', 'settled', 'written_off', 'recovered'))");
            DB::statement('ALTER TABLE prize_advances ADD CONSTRAINT prize_advance_amounts CHECK (amount > 0 AND settled_amount >= 0 AND settled_amount <= amount AND (closed_amount IS NULL OR (closed_amount > 0 AND settled_amount + closed_amount = amount)))');
            DB::statement("CREATE UNIQUE INDEX prize_advances_one_open ON prize_advances (partner_id, kind) WHERE status = 'open'");
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('prize_advances');
    }
};
