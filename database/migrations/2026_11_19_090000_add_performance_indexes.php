<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Indexes the 2026-11-18 readiness audit found missing on hot paths (PQ-05).
 *
 * PostgreSQL does not index foreign keys on its own, so deleting or joining a parent row scans the
 * child table, and a partner's newest-first notice list sorted after a bitmap scan. Every index is
 * built with CREATE INDEX CONCURRENTLY (outside a transaction) so the live database keeps accepting
 * orders and payments while it builds, and IF NOT EXISTS keeps a re-run harmless.
 */
return new class extends Migration
{
    /** CREATE INDEX CONCURRENTLY cannot run inside a transaction block. */
    public $withinTransaction = false;

    /** @var array<string, string> */
    private array $indexes = [
        // A partner's notice list is newest first; pruning scans by age.
        'account_notices_user_id_created_at_index' => 'account_notices (user_id, created_at DESC)',
        'account_notices_created_at_index' => 'account_notices (created_at)',
        // Wallet statements filter lines by partner alone, which (account, user_id) cannot serve.
        'journal_lines_user_id_id_index' => 'journal_lines (user_id, id DESC)',
        // Prescription pages load their lines by prescription and stock reports by product.
        'prescription_lines_prescription_id_index' => 'prescription_lines (prescription_id)',
        'prescription_lines_product_id_index' => 'prescription_lines (product_id)',
        'prescriptions_branch_id_index' => 'prescriptions (branch_id)',
        // Order lines under a dated scheme, and the scheme's own free product.
        'order_lines_dated_scheme_id_index' => 'order_lines (dated_scheme_id)',
        'dated_schemes_free_product_id_index' => 'dated_schemes (free_product_id)',
        // Branch stock, shipments and movements are read per branch or per product.
        'shipments_branch_id_index' => 'shipments (branch_id)',
        'stock_movements_branch_id_index' => 'stock_movements (branch_id)',
        'branch_product_stocks_product_id_index' => 'branch_product_stocks (product_id)',
        'stock_lots_product_id_index' => 'stock_lots (product_id)',
        'stock_lots_parent_lot_id_index' => 'stock_lots (parent_lot_id)',
        'stock_lot_movements_product_id_index' => 'stock_lot_movements (product_id)',
        'stock_lot_movements_branch_id_index' => 'stock_lot_movements (branch_id)',
        'stock_lot_movements_order_id_index' => 'stock_lot_movements (order_id)',
        'stock_transfers_product_id_index' => 'stock_transfers (product_id)',
        'stock_transfers_source_branch_id_index' => 'stock_transfers (source_branch_id)',
        'stock_transfers_destination_branch_id_index' => 'stock_transfers (destination_branch_id)',
        'goods_receipts_branch_id_index' => 'goods_receipts (branch_id)',
        'goods_receipt_lines_product_id_index' => 'goods_receipt_lines (product_id)',
        // Catalog joins.
        'products_product_subcategory_id_index' => 'products (product_subcategory_id)',
        'products_product_unit_id_index' => 'products (product_unit_id)',
        // Money-side lookups by payment account and by partner.
        'payment_claims_payment_account_id_index' => 'payment_claims (payment_account_id)',
        'receipts_payment_account_id_index' => 'receipts (payment_account_id)',
        'withdrawals_payment_account_id_index' => 'withdrawals (payment_account_id)',
        'withdrawals_paid_by_index' => 'withdrawals (paid_by)',
        'area_dealer_assignments_user_id_index' => 'area_dealer_assignments (user_id)',
        'dealer_applications_user_id_index' => 'dealer_applications (user_id)',
        'support_ticket_replies_user_id_index' => 'support_ticket_replies (user_id)',
        'support_tickets_assigned_to_index' => 'support_tickets (assigned_to)',
        'partner_messages_sender_id_index' => 'partner_messages (sender_id)',
        'partner_former_codes_user_id_index' => 'partner_former_codes (user_id)',
        'users_active_branch_id_index' => 'users (active_branch_id)',
        'orders_submitted_by_index' => 'orders (submitted_by)',
        // Place codes are looked up by their base place when a branch or patient picks a location.
        'place_codes_base_place_id_index' => 'place_codes (base_place_id)',
    ];

    public function up(): void
    {
        foreach ($this->indexes as $name => $definition) {
            if ($this->tableAndColumnsExist($definition)) {
                DB::statement("CREATE INDEX {$this->concurrently()}IF NOT EXISTS {$name} ON {$definition}");
            }
        }
    }

    public function down(): void
    {
        foreach (array_keys($this->indexes) as $name) {
            DB::statement("DROP INDEX {$this->concurrently()}IF EXISTS {$name}");
        }
    }

    /** A table or column renamed by a later branch must not break this migration. */
    private function tableAndColumnsExist(string $definition): bool
    {
        preg_match('/^(\w+) \((.+)\)$/', $definition, $parts);
        $columns = array_map(fn (string $column): string => explode(' ', trim($column))[0], explode(',', $parts[2]));
        $found = DB::table('information_schema.columns')
            ->where('table_schema', 'public')->where('table_name', $parts[1])->whereIn('column_name', $columns)
            ->count();

        return $found === count($columns);
    }

    /** Inside an outer transaction (a test rolling migrations back) a plain build is the only option. */
    private function concurrently(): string
    {
        return DB::transactionLevel() === 0 ? 'CONCURRENTLY ' : '';
    }
};
