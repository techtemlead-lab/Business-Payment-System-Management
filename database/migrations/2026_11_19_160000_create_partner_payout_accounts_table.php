<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/** V3: the payout accounts (bank, bKash, Nagad, Rocket) a dealer or sub-dealer keeps in his profile. */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('partner_payout_accounts', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->string('channel', 10);
            // Bank name, branch, account name and number, encrypted together like other personal data.
            $table->text('details');
            $table->boolean('is_default')->default(false);
            $table->timestamps();
            $table->index(['user_id', 'created_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('partner_payout_accounts');
    }
};
