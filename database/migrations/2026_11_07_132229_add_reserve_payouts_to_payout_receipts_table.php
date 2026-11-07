<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Reserve payouts paid by hand get the same numbered receipts as withdrawals.
     * A receipt now belongs to either one withdrawal or one reserve payout. A reserve
     * payout may go to someone outside the network, so the partner and payee name are
     * both optional; the receipt then shows the payee name.
     */
    public function up(): void
    {
        Schema::table('payout_receipts', function (Blueprint $table) {
            $table->uuid('withdrawal_id')->nullable()->change();
            $table->foreignId('user_id')->nullable()->change();
            $table->foreignId('reserve_disbursement_id')->nullable()->unique()->constrained('reserve_disbursements');
            $table->string('payee_name', 150)->nullable();
        });
        DB::statement('ALTER TABLE payout_receipts ADD CONSTRAINT payout_receipts_one_source_check CHECK ((withdrawal_id IS NOT NULL) <> (reserve_disbursement_id IS NOT NULL))');
        DB::statement('ALTER TABLE payout_receipts ADD CONSTRAINT payout_receipts_payee_check CHECK (user_id IS NOT NULL OR payee_name IS NOT NULL)');
    }

    public function down(): void
    {
        DB::statement('ALTER TABLE payout_receipts DROP CONSTRAINT IF EXISTS payout_receipts_payee_check');
        DB::statement('ALTER TABLE payout_receipts DROP CONSTRAINT IF EXISTS payout_receipts_one_source_check');
        DB::table('payout_receipts')->whereNotNull('reserve_disbursement_id')->delete();
        Schema::table('payout_receipts', function (Blueprint $table) {
            $table->dropConstrainedForeignId('reserve_disbursement_id');
            $table->dropColumn('payee_name');
            $table->uuid('withdrawal_id')->nullable(false)->change();
            $table->foreignId('user_id')->nullable(false)->change();
        });
    }
};
