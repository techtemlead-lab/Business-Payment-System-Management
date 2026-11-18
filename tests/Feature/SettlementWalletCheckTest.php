<?php

namespace Tests\Feature;

use App\Domain\Finance\FinancialReadiness;
use App\Models\Order;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\TestCase;

/**
 * Month-end wallet credits and their payout rows have to name the same people
 * and the same amounts. A reward paid from a fund uses a different journal.
 */
class SettlementWalletCheckTest extends TestCase
{
    use RefreshDatabase;

    public function test_a_month_end_payout_agrees_with_the_wallet_credit(): void
    {
        $dealer = User::factory()->dealer()->create();
        $this->payout($dealer, 'salary', '50.00');
        $this->settlementJournal($dealer, '50.00');

        $this->assertTrue($this->agrees());
    }

    public function test_a_wallet_credit_without_a_payout_row_does_not_agree(): void
    {
        $dealer = User::factory()->dealer()->create();
        $this->settlementJournal($dealer, '50.00');

        $this->assertFalse($this->agrees());
    }

    public function test_a_refunded_part_of_a_payout_stays_on_the_original_amount(): void
    {
        $dealer = User::factory()->dealer()->create();
        $this->payout($dealer, 'royalty', '50.00', '20.00');
        $this->settlementJournal($dealer, '50.00');

        $this->assertTrue($this->agrees());
    }

    public function test_a_reward_payout_is_not_treated_as_a_month_end_wallet_credit(): void
    {
        $dealer = User::factory()->dealer()->create();
        $this->payout($dealer, 'award_4', '50.00');

        $this->assertTrue($this->agrees());
    }

    private function agrees(): bool
    {
        return app(FinancialReadiness::class)->checks()['settlement_wallets'];
    }

    private function payout(User $dealer, string $kind, string $amount, string $reversed = '0.00'): void
    {
        $order = Order::create([
            'user_id' => $dealer->id,
            'number' => 'QA-'.Str::upper(Str::random(8)),
            'total' => '1000.00',
            'paid' => '1000.00',
            'idempotency_key' => (string) Str::uuid(),
            'payload_hash' => hash('sha256', (string) Str::uuid()),
        ]);
        $allocationId = DB::table('commission_allocations')->insertGetId([
            'order_id' => $order->id,
            'user_id' => $dealer->id,
            'category' => 'rank_incentive_pool',
            'rate_percentage' => '2.00',
            'cash_basis' => '1000.00',
            'amount' => $amount,
            'status' => 'settled',
            'metadata' => '{}',
            'created_at' => now(),
            'updated_at' => now(),
        ]);
        DB::table('commission_settlement_payouts')->insert([
            'allocation_id' => $allocationId,
            'user_id' => $dealer->id,
            'kind' => $kind,
            'period_month' => '2026-11',
            'amount' => $amount,
            'reversed_amount' => $reversed,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }

    private function settlementJournal(User $dealer, string $amount): void
    {
        $entry = (string) Str::uuid();
        DB::table('journal_entries')->insert([
            'id' => $entry,
            'event_key' => 'commissions.settlement.salary.2026-11.'.$entry,
            'description' => 'Month closing',
            'created_at' => now(),
        ]);
        DB::table('journal_lines')->insert([
            'entry_id' => $entry,
            'user_id' => $dealer->id,
            'account' => 'wallet_liability',
            'debit' => '0.00',
            'credit' => $amount,
        ]);
    }
}
