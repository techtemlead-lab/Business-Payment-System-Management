<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * A transaction ID stays unique among pending and verified payment claims, but a
 * rejected claim (for example an unreadable screenshot) no longer blocks the
 * dealer from claiming the same real payment again. Receipts keep their own
 * unique (channel, reference), so one payment can still be counted only once.
 */
return new class extends Migration
{
    /**
     * CREATE INDEX CONCURRENTLY cannot run inside a transaction block.
     */
    public $withinTransaction = false;

    public function up(): void
    {
        DB::statement("CREATE UNIQUE INDEX {$this->concurrently()}IF NOT EXISTS payment_claims_open_reference_unique ON payment_claims (channel, reference) WHERE status <> 'rejected'");
        DB::statement('ALTER TABLE payment_claims DROP CONSTRAINT IF EXISTS payment_claims_channel_reference_unique');
    }

    public function down(): void
    {
        DB::statement('ALTER TABLE payment_claims ADD CONSTRAINT payment_claims_channel_reference_unique UNIQUE (channel, reference)');
        DB::statement("DROP INDEX {$this->concurrently()}IF EXISTS payment_claims_open_reference_unique");
    }

    /**
     * Build without blocking writes when running on its own. Inside an outer
     * transaction (a test rolling migrations back) a plain build is the only option.
     */
    private function concurrently(): string
    {
        return DB::transactionLevel() === 0 ? 'CONCURRENTLY ' : '';
    }
};
