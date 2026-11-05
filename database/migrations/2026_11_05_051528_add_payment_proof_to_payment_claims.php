<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Proof that the dealer really sent the money: a screenshot or PDF of the
 * bKash / Nagad / Rocket / bank transfer, kept privately next to the
 * transaction ID. Nullable so claims made before this change stay valid.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('payment_claims', function (Blueprint $table) {
            $table->string('proof_path')->nullable();
            $table->string('proof_mime', 100)->nullable();
            $table->char('proof_sha256', 64)->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('payment_claims', function (Blueprint $table) {
            $table->dropColumn(['proof_path', 'proof_mime', 'proof_sha256']);
        });
    }
};
