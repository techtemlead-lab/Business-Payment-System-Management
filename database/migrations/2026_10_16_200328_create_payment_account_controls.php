<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('payment_accounts', function (Blueprint $table): void {
            $table->id();
            $table->string('label', 120);
            $table->string('channel', 30);
            $table->string('holder', 150);
            $table->text('account_number');
            $table->text('details')->nullable();
            $table->boolean('collects_payments')->default(true);
            $table->boolean('pays_out')->default(false);
            $table->boolean('is_active')->default(true);
            $table->unsignedInteger('version')->default(1);
            $table->foreignId('created_by')->constrained('users');
            $table->foreignId('updated_by')->constrained('users');
            $table->timestamps();
        });
        Schema::table('payment_claims', function (Blueprint $table): void {
            $table->foreignId('payment_account_id')->nullable()->constrained('payment_accounts');
            $table->json('account_snapshot')->nullable();
        });
        Schema::table('receipts', fn (Blueprint $table) => $table->foreignId('payment_account_id')->nullable()->constrained('payment_accounts'));
        Schema::table('withdrawals', fn (Blueprint $table) => $table->foreignId('payment_account_id')->nullable()->constrained('payment_accounts'));
        Schema::create('manual_payment_references', function (Blueprint $table): void {
            $table->id();
            $table->string('reference', 100)->unique();
            $table->string('source', 80)->unique();
            $table->foreignId('payment_account_id')->nullable()->constrained('payment_accounts');
            $table->foreignId('actor_id')->constrained('users');
            $table->timestamp('created_at')->useCurrent();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('manual_payment_references');
        foreach (['receipts', 'withdrawals', 'payment_claims'] as $name) {
            Schema::table($name, fn (Blueprint $table) => $table->dropConstrainedForeignId('payment_account_id'));
        }
        Schema::table('payment_claims', fn (Blueprint $table) => $table->dropColumn('account_snapshot'));
        Schema::dropIfExists('payment_accounts');
    }
};
