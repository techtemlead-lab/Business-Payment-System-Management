<?php

namespace Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * The ledger, settlement and payout lookups grow with every money event, so
 * their columns must be indexed. The indexes are built concurrently, which can
 * leave an invalid index behind if a build fails, so validity is checked too.
 */
class HotPathIndexesTest extends TestCase
{
    use RefreshDatabase;

    public function test_money_lookup_columns_have_valid_indexes(): void
    {
        $indexes = collect(DB::select(
            "select c.relname as name, i.indisvalid as valid from pg_index i join pg_class c on c.oid = i.indexrelid join pg_namespace n on n.oid = c.relnamespace where n.nspname = 'public'"
        ))->pluck('valid', 'name');

        foreach ([
            'journal_lines_entry_id_index',
            'journal_entries_created_at_index',
            'commission_allocations_category_status_month_index',
            'commission_allocations_created_at_index',
            'commission_settlement_payouts_user_id_created_at_index',
            'commission_reversals_user_id_index',
            'commission_reversals_refund_id_index',
            'commission_reversals_allocation_id_index',
            'receipts_order_id_index',
            'receipts_created_at_index',
            'withdrawal_events_withdrawal_id_index',
            'withdrawals_created_at_index',
            'orders_fulfillment_branch_id_index',
            'audit_events_actor_id_index',
            'support_ticket_replies_support_ticket_id_index',
            'payroll_events_payroll_id_index',
            'opening_balance_entries_import_id_index',
        ] as $index) {
            $this->assertTrue($indexes->has($index), "Missing index {$index}.");
            $this->assertTrue((bool) $indexes[$index], "Index {$index} is invalid.");
        }
    }

    public function test_payment_claims_order_status_is_indexed_exactly_once(): void
    {
        $indexes = DB::table('pg_indexes')
            ->where('schemaname', 'public')
            ->where('tablename', 'payment_claims')
            ->where('indexdef', 'like', '%(order_id, status)')
            ->pluck('indexname')
            ->all();

        $this->assertSame(['payment_claims_order_status_index'], $indexes);
    }

    public function test_monthly_pool_query_matches_the_expression_index(): void
    {
        $sql = DB::table('commission_allocations')
            ->where('category', 'rank_royalty_pool')
            ->where('status', 'pending')
            ->where('metadata->period_month', '2026-10')
            ->toSql();

        $definition = DB::table('pg_indexes')->where('indexname', 'commission_allocations_category_status_month_index')->value('indexdef');

        $this->assertStringContainsString("->>'period_month'", $sql);
        $this->assertStringContainsString("->> 'period_month'", (string) $definition);
    }
}
