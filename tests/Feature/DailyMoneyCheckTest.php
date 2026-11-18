<?php

namespace Tests\Feature;

use App\Domain\Commissions\RetainedFundLedger;
use App\Domain\Finance\FinancialReadiness;
use App\Domain\Finance\FundSpending;
use App\Models\Order;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use Tests\TestCase;

/**
 * MA-19: the daily money check also compares fund spends, withdrawals, payroll and the owners'
 * benefits fund with the journals and batches behind them, names the check that failed, and
 * reads the books without taking the money lock.
 */
class DailyMoneyCheckTest extends TestCase
{
    use RefreshDatabase;

    public function test_a_paid_fund_spend_needs_a_journal_of_the_same_amount(): void
    {
        $spendId = $this->paidSpend('marketing', '100.00');
        $this->journal('fund.spend.'.$spendId, [['company_marketing_expense', null, '100.00', '0.00'], ['manual_payout_clearing', null, '0.00', '100.00']]);

        $this->assertTrue($this->check('fund_spend_journals'));

        DB::table('reserve_disbursements')->where('id', $spendId)->update(['amount' => '110.00']);

        $this->assertFalse($this->check('fund_spend_journals'));
        $this->assertContains('fund_spend_journals', FinancialReadiness::failedNames(app(FinancialReadiness::class)->checks()));
    }

    public function test_a_paid_fund_spend_without_any_journal_fails(): void
    {
        $this->paidSpend('marketing', '100.00');

        $this->assertFalse($this->check('fund_spend_journals'));
    }

    public function test_a_fund_spend_journal_for_a_spend_that_was_not_paid_fails(): void
    {
        $spendId = $this->paidSpend('marketing', '100.00');
        $this->journal('fund.spend.'.$spendId, [['company_marketing_expense', null, '100.00', '0.00'], ['manual_payout_clearing', null, '0.00', '100.00']]);
        $this->assertTrue($this->check('fund_spend_journals'));

        DB::table('reserve_disbursements')->where('id', $spendId)->update(['status' => 'rejected']);

        $this->assertFalse($this->check('fund_spend_journals'));
    }

    public function test_a_spend_from_a_commission_fund_must_draw_its_amount_from_the_fund_rows(): void
    {
        $order = $this->order();
        $allocation = $this->allocation($order, 'company_marketing_fund', '1000.00');
        $spendId = $this->paidSpend('marketing', '100.00');
        DB::table('reserve_expense_sources')->insert(['disbursement_id' => $spendId, 'allocation_id' => $allocation, 'amount' => '100.00', 'created_at' => now(), 'updated_at' => now()]);

        $this->assertTrue($this->check('fund_balances'));

        DB::table('reserve_expense_sources')->where('disbursement_id', $spendId)->update(['amount' => '60.00']);

        $this->assertFalse($this->check('fund_balances'));
    }

    public function test_a_commission_fund_spent_below_zero_fails(): void
    {
        $order = $this->order();
        $allocation = $this->allocation($order, 'company_marketing_fund', '1000.00');
        $spendId = $this->paidSpend('marketing', '1500.00');
        DB::table('reserve_expense_sources')->insert(['disbursement_id' => $spendId, 'allocation_id' => $allocation, 'amount' => '1500.00', 'created_at' => now(), 'updated_at' => now()]);

        $this->assertFalse($this->check('fund_balances'));
    }

    public function test_a_paid_withdrawal_needs_its_payout_journal(): void
    {
        $partner = User::factory()->dealer()->create();
        $id = $this->paidWithdrawal($partner, '100.00', '90.00', '10.00');
        $this->journal('manual-payout:'.$id, [['wallet_liability', $partner->id, '100.00', '0.00'], ['manual_payout_clearing', null, '0.00', '90.00'], ['payout_withholding_payable', null, '0.00', '10.00']]);

        $this->assertTrue($this->check('withdrawal_journals'));

        DB::table('withdrawals')->where('id', $id)->update(['amount' => '120.00', 'net_amount' => '110.00']);

        $this->assertFalse($this->check('withdrawal_journals'));
    }

    public function test_a_paid_withdrawal_without_a_journal_fails(): void
    {
        $this->paidWithdrawal(User::factory()->dealer()->create(), '100.00', '100.00', '0.00');

        $this->assertFalse($this->check('withdrawal_journals'));
    }

    public function test_a_requested_withdrawal_needs_no_journal(): void
    {
        $partner = User::factory()->dealer()->create();
        DB::table('withdrawals')->insert(['id' => (string) Str::uuid(), 'user_id' => $partner->id, 'amount' => '100.00', 'status' => 'requested', 'channel' => 'bKash',
            'destination' => 'masked', 'idempotency_key' => (string) Str::uuid(), 'withheld_amount' => '0.00', 'net_amount' => '100.00', 'created_at' => now(), 'updated_at' => now()]);

        $this->assertTrue($this->check('withdrawal_journals'));
    }

    public function test_paid_payroll_needs_its_approval_and_payment_journals(): void
    {
        $staff = User::factory()->staff('finance')->create();
        $id = $this->payroll($staff, 'paid', '1000.00', '100.00', '900.00');
        $this->journal('payroll.approved.'.$id, [['payroll_expense', $staff->id, '1000.00', '0.00'], ['payroll_payable', $staff->id, '0.00', '900.00'], ['payroll_deductions_payable', $staff->id, '0.00', '100.00']]);
        $this->journal('payroll.paid.'.$id, [['payroll_payable', $staff->id, '900.00', '0.00'], ['manual_payout_clearing', null, '0.00', '900.00']]);

        $this->assertTrue($this->check('payroll_journals'));

        DB::table('payroll_entries')->where('id', $id)->update(['net' => '850.00', 'deduction' => '150.00']);

        $this->assertFalse($this->check('payroll_journals'));
    }

    public function test_paid_payroll_without_a_journal_fails_and_a_draft_needs_none(): void
    {
        $staff = User::factory()->staff('finance')->create();
        $this->payroll($staff, 'draft', '1000.00', '100.00', '900.00', '2026-10');
        $this->assertTrue($this->check('payroll_journals'));

        $this->payroll($staff, 'paid', '1000.00', '100.00', '900.00', '2026-11');

        $this->assertFalse($this->check('payroll_journals'));
    }

    public function test_the_benefits_fund_rows_must_match_what_each_batch_left_unallocated(): void
    {
        $order = $this->order();
        DB::table('commission_batches')->insert(['order_id' => $order->id, 'account_type' => 'dealer', 'period_month' => '2026-11', 'cash_basis' => '1000.00', 'mrp_basis' => '1000.00',
            'company_share' => '950.00', 'total_allocated' => '0.00', 'unallocated' => '50.00', 'policy' => '{}', 'created_at' => now(), 'updated_at' => now()]);
        $allocation = $this->allocation($order, RetainedFundLedger::BenefitsCategory, '50.00');

        $this->assertTrue($this->check('benefits_fund_batches'));

        DB::table('commission_allocations')->where('id', $allocation)->update(['original_amount' => '40.00']);

        $this->assertFalse($this->check('benefits_fund_batches'));
    }

    public function test_the_command_names_the_failed_check_in_its_output_and_the_critical_log(): void
    {
        Log::spy();
        $this->payroll(User::factory()->staff('finance')->create(), 'paid', '1000.00', '100.00', '900.00');

        $this->artisan('finance:readiness')
            ->expectsOutputToContain(__('money_core.readiness_failed', ['checks' => 'payroll_journals']))
            ->assertFailed();

        Log::shouldHaveReceived('critical')->once()->with('Money check failed.', ['failed_checks' => ['payroll_journals']]);
    }

    public function test_the_command_passes_and_stays_quiet_when_every_check_agrees(): void
    {
        Log::spy();

        $this->artisan('finance:readiness')->assertSuccessful();

        Log::shouldNotHaveReceived('critical');
    }

    public function test_the_daily_read_does_not_take_the_money_lock(): void
    {
        $statements = [];
        DB::listen(function ($query) use (&$statements): void {
            $statements[] = $query->sql;
        });

        app(FinancialReadiness::class)->inspectWithoutLock();
        $this->assertSame([], array_values(array_filter($statements, fn (string $sql): bool => str_contains($sql, 'pg_advisory'))));

        app(FinancialReadiness::class)->inspect();
        $this->assertNotEmpty(array_filter($statements, fn (string $sql): bool => str_contains($sql, 'pg_advisory')));
    }

    private function check(string $name): bool
    {
        return app(FinancialReadiness::class)->checks()[$name];
    }

    private function order(): Order
    {
        return Order::create([
            'user_id' => User::factory()->dealer()->create()->id,
            'number' => 'QA-'.Str::upper(Str::random(8)),
            'total' => '1000.00',
            'paid' => '1000.00',
            'idempotency_key' => (string) Str::uuid(),
            'payload_hash' => hash('sha256', (string) Str::uuid()),
        ]);
    }

    private function allocation(Order $order, string $category, string $amount): int
    {
        return DB::table('commission_allocations')->insertGetId([
            'order_id' => $order->id, 'user_id' => null, 'category' => $category, 'rate_percentage' => '1.00', 'cash_basis' => '1000.00',
            'amount' => $amount, 'original_amount' => $amount, 'status' => 'reserved', 'metadata' => '{}', 'created_at' => now(), 'updated_at' => now(),
        ]);
    }

    private function paidSpend(string $fund, string $amount): int
    {
        $maker = User::factory()->staff('finance')->create();

        return DB::table('reserve_disbursements')->insertGetId([
            'program' => FundSpending::Program, 'fund_key' => $fund, 'amount' => $amount, 'status' => 'paid', 'request_key' => (string) Str::uuid(),
            'reason' => 'Test spend', 'created_by' => $maker->id, 'created_at' => now(), 'updated_at' => now(),
        ]);
    }

    private function paidWithdrawal(User $partner, string $amount, string $net, string $withheld): string
    {
        $id = (string) Str::uuid();
        DB::table('withdrawals')->insert(['id' => $id, 'user_id' => $partner->id, 'amount' => $amount, 'status' => 'paid', 'channel' => 'bKash',
            'destination' => 'masked', 'idempotency_key' => (string) Str::uuid(), 'withheld_amount' => $withheld, 'net_amount' => $net, 'created_at' => now(), 'updated_at' => now()]);

        return $id;
    }

    private function payroll(User $staff, string $status, string $gross, string $deduction, string $net, string $month = '2026-11'): string
    {
        $contract = DB::table('staff_contracts')->insertGetId(['staff_id' => $staff->id, 'effective_month' => $month, 'base_salary' => $gross, 'reason' => 'Test contract',
            'created_by' => $staff->id, 'created_at' => now(), 'updated_at' => now()]);
        $id = (string) Str::uuid();
        DB::table('payroll_entries')->insert(['id' => $id, 'staff_id' => $staff->id, 'contract_id' => $contract, 'period_month' => $month, 'snapshot' => '{}',
            'gross' => $gross, 'deduction' => $deduction, 'net' => $net, 'status' => $status, 'prepared_by' => $staff->id, 'created_at' => now(), 'updated_at' => now()]);

        return $id;
    }

    /** @param list<array{0: string, 1: int|null, 2: string, 3: string}> $lines account, user, debit, credit */
    private function journal(string $eventKey, array $lines): void
    {
        $entry = (string) Str::uuid();
        DB::table('journal_entries')->insert(['id' => $entry, 'event_key' => $eventKey, 'description' => 'Test', 'created_at' => now()]);
        foreach ($lines as [$account, $userId, $debit, $credit]) {
            DB::table('journal_lines')->insert(['entry_id' => $entry, 'user_id' => $userId, 'account' => $account, 'debit' => $debit, 'credit' => $credit]);
        }
    }
}
