<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * The event log reads each source table from the last place it stopped, by time. These tables had no
 * index on created_at yet. Built without blocking writes, because they are the live money tables.
 */
return new class extends Migration
{
    /**
     * CREATE INDEX CONCURRENTLY cannot run inside a transaction block.
     */
    public $withinTransaction = false;

    private const Tables = ['order_events', 'commission_batches', 'commission_reversals', 'commission_settlement_runs', 'rank_incentive_awards', 'withdrawal_events', 'stock_movements', 'commission_refunds'];

    public function up(): void
    {
        foreach (self::Tables as $table) {
            DB::statement("CREATE INDEX {$this->concurrently()}IF NOT EXISTS {$table}_created_at_index ON {$table} (created_at)");
        }
    }

    public function down(): void
    {
        foreach (self::Tables as $table) {
            DB::statement("DROP INDEX {$this->concurrently()}IF EXISTS {$table}_created_at_index");
        }
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
