<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Money the clinic took in and carried to the company's bank or main cash. Each row posts one
 * journal entry (debit manual_collection_clearing, credit clinic_cash). A new table only.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('clinic_cash_deposits', function (Blueprint $table): void {
            $table->id();
            $table->decimal('amount', 14, 2);
            $table->date('deposited_on');
            $table->string('reference', 100)->nullable();
            $table->text('note');
            $table->uuid('request_key')->unique();
            $table->foreignId('created_by')->constrained('users');
            $table->timestamps();
        });
        if (DB::getDriverName() === 'pgsql') {
            DB::statement('ALTER TABLE clinic_cash_deposits ADD CONSTRAINT clinic_cash_deposits_amount_check CHECK (amount > 0)');
            DB::statement('CREATE UNIQUE INDEX clinic_cash_deposits_reference ON clinic_cash_deposits (UPPER(reference)) WHERE reference IS NOT NULL');
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('clinic_cash_deposits');
    }
};
