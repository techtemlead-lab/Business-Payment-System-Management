<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Who a company-fund spend was for. A spend with no rows here is for the company itself, as before.
 * A row is money the company spent on that partner (an event prize, a tour, a contest prize): it is not
 * a wallet credit and no journal line refers to it. The share is the partner's part of the spend.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('fund_spend_partners', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('disbursement_id')->constrained('reserve_disbursements')->cascadeOnDelete();
            $table->foreignId('user_id')->constrained('users')->cascadeOnDelete();
            $table->decimal('share_amount', 14, 2);
            $table->timestamps();
            $table->unique(['disbursement_id', 'user_id']);
            $table->index('user_id');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('fund_spend_partners');
    }
};
