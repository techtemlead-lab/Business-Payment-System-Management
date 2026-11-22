<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * The correction for a company fund spend that was paid and whose money came back: one row per
 * spend at most (the unique key makes a repeated request a no-op). The spend keeps its status and
 * its own journal; the reversal posts its own journal. A new table only.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('fund_spend_reversals', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('disbursement_id')->unique()->constrained('reserve_disbursements');
            $table->decimal('amount', 14, 2);
            $table->date('returned_on');
            $table->text('reason');
            $table->foreignId('created_by')->constrained('users');
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('fund_spend_reversals');
    }
};
