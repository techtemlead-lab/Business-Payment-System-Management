<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Indexes for lookups that grow with every money event and had none.
 *
 * PostgreSQL does not index foreign keys on its own, so the ledger page summed
 * journal_lines by entry_id with a full scan per row. Each index is built with
 * CREATE INDEX CONCURRENTLY outside a transaction so a live database keeps
 * accepting orders and payments while it builds.
 */
return new class extends Migration
{
    /**
     * CREATE INDEX CONCURRENTLY cannot run inside a transaction block.
     */
    public $withinTransaction = false;

    /**
     * @var array<string, string>
     */
    private array $indexes = [
        // Ledger page and ledger export sum lines per journal entry and sort entries by date.
        'journal_lines_entry_id_index' => 'journal_lines (entry_id)',
        'journal_entries_created_at_index' => 'journal_entries (created_at)',
        // Monthly settlement, the salary screen and auto-approval filter pool allocations by month.
        'commission_allocations_category_status_month_index' => "commission_allocations (category, status, (metadata->>'period_month'))",
        'commission_allocations_created_at_index' => 'commission_allocations (created_at)',
        // A partner's settlement payouts on the withdrawals page and the API.
        'commission_settlement_payouts_user_id_created_at_index' => 'commission_settlement_payouts (user_id, created_at)',
        // Wallet balance subtracts a partner's unrecovered reversals; refunds look up their reversals.
        'commission_reversals_user_id_index' => 'commission_reversals (user_id)',
        'commission_reversals_refund_id_index' => 'commission_reversals (refund_id)',
        'commission_reversals_allocation_id_index' => 'commission_reversals (allocation_id)',
        // Receipts per order on order pages; this month's collections on the staff dashboard.
        'receipts_order_id_index' => 'receipts (order_id)',
        'receipts_created_at_index' => 'receipts (created_at)',
        // Withdrawal history on the withdrawal detail page; payout lists sort newest first.
        'withdrawal_events_withdrawal_id_index' => 'withdrawal_events (withdrawal_id)',
        'withdrawals_created_at_index' => 'withdrawals (created_at)',
        // Branch filter on staff order lists.
        'orders_fulfillment_branch_id_index' => 'orders (fulfillment_branch_id)',
        // Activity filtered by staff member in the audit log.
        'audit_events_actor_id_index' => 'audit_events (actor_id)',
        // Support ticket threads, payroll history and import rows load children by parent.
        'support_ticket_replies_support_ticket_id_index' => 'support_ticket_replies (support_ticket_id)',
        'payroll_events_payroll_id_index' => 'payroll_events (payroll_id)',
        'opening_balance_entries_import_id_index' => 'opening_balance_entries (import_id)',
    ];

    public function up(): void
    {
        foreach ($this->indexes as $name => $definition) {
            DB::statement("CREATE INDEX {$this->concurrently()}IF NOT EXISTS {$name} ON {$definition}");
        }
    }

    public function down(): void
    {
        foreach (array_keys($this->indexes) as $name) {
            DB::statement("DROP INDEX {$this->concurrently()}IF EXISTS {$name}");
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
