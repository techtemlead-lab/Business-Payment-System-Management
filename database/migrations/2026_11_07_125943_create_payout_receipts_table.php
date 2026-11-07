<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Numbered receipts for commission payouts paid by hand (cash, bank, bKash, Nagad or other).
     * One receipt per withdrawal: the unique withdrawal_id stops a second receipt. The partner's
     * account or wallet number is stored encrypted, like the withdrawal destination.
     */
    public function up(): void
    {
        Schema::create('payout_receipts', function (Blueprint $table) {
            $table->id();
            $table->string('number')->unique();
            $table->foreignUuid('withdrawal_id')->unique()->constrained();
            $table->foreignId('user_id')->index()->constrained();
            $table->decimal('amount', 14, 2);
            $table->decimal('withheld_amount', 14, 2)->default(0);
            $table->decimal('net_amount', 14, 2);
            $table->string('method', 20);
            $table->string('reference', 100)->nullable();
            $table->string('bank_name', 100)->nullable();
            $table->text('account_number')->nullable();
            $table->string('received_by', 100)->nullable();
            $table->timestamp('paid_at');
            $table->foreignId('paid_by')->index()->constrained('users');
            $table->timestamps();
            $table->index(['user_id', 'paid_at']);
        });
        DB::statement("ALTER TABLE payout_receipts ADD CONSTRAINT payout_receipts_method_check CHECK (method IN ('cash', 'bank', 'bkash', 'nagad', 'other'))");
        DB::statement('ALTER TABLE payout_receipts ADD CONSTRAINT payout_receipts_amount_check CHECK (amount > 0 AND net_amount >= 0 AND withheld_amount >= 0)');
    }

    public function down(): void
    {
        Schema::dropIfExists('payout_receipts');
    }
};
