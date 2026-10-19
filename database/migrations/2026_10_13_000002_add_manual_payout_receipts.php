<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('withdrawals', function (Blueprint $t) {
            $t->timestamp('paid_at')->nullable();
            $t->foreignId('paid_by')->nullable()->constrained('users');
            $t->string('receipt_path')->nullable();
            $t->string('receipt_mime')->nullable();
            $t->string('receipt_sha256')->nullable();
        });
        Schema::create('withdrawal_events', function (Blueprint $t) {
            $t->id();
            $t->foreignUuid('withdrawal_id')->constrained();
            $t->foreignId('actor_id')->constrained('users');
            $t->string('event');
            $t->text('note');
            $t->timestamp('created_at')->useCurrent();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('withdrawal_events');
        Schema::table('withdrawals', fn (Blueprint $t) => $t->dropColumn(['paid_at', 'paid_by', 'receipt_path', 'receipt_mime', 'receipt_sha256']));
    }
};
