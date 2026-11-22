<?php

namespace Tests\Feature;

use App\Domain\Finance\MoneyTaskFigures;
use App\Domain\Finance\PayableRemittances;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\TestCase;

class PayableRemittancesTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->withoutVite();
        $this->travelTo(now()->setDate(2026, 10, 20)->setTime(10, 0));
    }

    /** @param list<array{0: string, 1: string, 2: string}> $lines account, debit, credit */
    private function journal(string $key, string $when, array $lines): void
    {
        $id = (string) Str::uuid();
        DB::table('journal_entries')->insert(['id' => $id, 'event_key' => $key, 'description' => $key, 'created_at' => $when]);
        foreach ($lines as [$account, $debit, $credit]) {
            DB::table('journal_lines')->insert(['entry_id' => $id, 'user_id' => null, 'account' => $account, 'debit' => $debit, 'credit' => $credit]);
        }
    }

    private function withheld(string $key, string $when, string $amount): void
    {
        $this->journal($key, $when, [['wallet_liability', $amount, '0.00'], ['payout_withholding_payable', '0.00', $amount]]);
    }

    /** @return array<string, string> */
    private function payload(array $overrides = []): array
    {
        return ['kind' => 'withheld_tax', 'amount' => '60.00', 'paid_on' => '2026-10-19', 'reference' => 'challan-77', 'note' => 'Paid at the tax office', 'request_key' => (string) Str::uuid(), ...$overrides];
    }

    public function test_withheld_tax_is_paid_on_by_two_people_with_a_balanced_journal(): void
    {
        $this->enableFinancialPosting();
        $this->withheld('w1', '2026-09-05 06:00:00', '100.00');
        $maker = User::factory()->staff('finance')->create();
        $checker = User::factory()->staff('finance')->create();

        $this->actingAs($maker)->onPortal('manage')->postJson('/commands/operations/accounting/payables', $this->payload(['amount' => '100.01']))->assertUnprocessable()->assertJsonValidationErrors('amount');
        $payload = $this->payload();
        $id = $this->postJson('/commands/operations/accounting/payables', $payload)->assertOk()->json('data.id');
        // The same form sent twice is one record.
        $this->postJson('/commands/operations/accounting/payables', $payload)->assertOk()->assertJsonPath('data.id', $id);
        $this->postJson('/commands/operations/accounting/payables', [...$payload, 'amount' => '61.00'])->assertStatus(409);
        // 60 waits, so only 40 more may be recorded.
        $this->postJson('/commands/operations/accounting/payables', $this->payload(['amount' => '41.00', 'reference' => 'other']))->assertUnprocessable();
        $this->assertSame(1, DB::table('payable_remittances')->count());

        // The person who recorded it cannot approve it.
        $this->postJson('/commands/operations/accounting/payables/'.$id.'/review', ['decision' => 'approved', 'reason' => 'Checked the challan'])->assertUnprocessable();
        $this->actingAs($checker)->postJson('/commands/operations/accounting/payables/'.$id.'/review', ['decision' => 'approved', 'reason' => 'Checked the challan'])->assertOk();
        $this->postJson('/commands/operations/accounting/payables/'.$id.'/review', ['decision' => 'approved', 'reason' => 'Checked the challan'])->assertUnprocessable();

        $entry = DB::table('journal_entries')->where('event_key', 'payable.remittance.'.$id)->first();
        $this->assertNotNull($entry);
        $lines = DB::table('journal_lines')->where('entry_id', $entry->id)->get()->keyBy('account');
        $this->assertEquals(60.00, $lines['payout_withholding_payable']->debit);
        $this->assertEquals(60.00, $lines['manual_payout_clearing']->credit);
        $this->assertEquals(DB::table('journal_lines')->sum('debit'), DB::table('journal_lines')->sum('credit'));
        $this->assertSame(1, DB::table('journal_entries')->where('event_key', 'like', 'payable.remittance.%')->count());
        $this->assertDatabaseHas('payable_remittances', ['id' => $id, 'status' => 'paid', 'decided_by' => $checker->id]);
        $this->assertDatabaseHas('audit_events', ['action' => 'payable.remittance_approved', 'subject' => (string) $id]);

        $figures = app(PayableRemittances::class)->figures('withheld_tax');
        $this->assertSame(4000, $figures['balance']);
        $this->assertSame(0, $figures['pending']);
    }

    public function test_approval_waits_for_money_posting_and_the_owner_may_finish_alone(): void
    {
        $this->withheld('w1', '2026-10-01 06:00:00', '50.00');
        $owner = User::factory()->create(['role' => 'super_admin']);
        $id = $this->actingAs($owner)->onPortal('sadmin')->postJson('/commands/operations/accounting/payables', $this->payload(['amount' => '50.00']))->assertOk()->json('data.id');
        $this->postJson('/commands/operations/accounting/payables/'.$id.'/review', ['decision' => 'approved', 'reason' => 'Paid today'])->assertUnprocessable();
        $this->assertSame(0, DB::table('journal_entries')->where('event_key', 'payable.remittance.'.$id)->count());

        $this->enableFinancialPosting();
        $this->actingAs($owner)->postJson('/commands/operations/accounting/payables/'.$id.'/review', ['decision' => 'approved', 'reason' => 'Paid today'])->assertOk();
        $this->assertSame(0, app(PayableRemittances::class)->figures('withheld_tax')['balance']);
    }

    public function test_salary_deductions_are_settled_and_a_refused_or_withdrawn_record_posts_nothing(): void
    {
        $this->enableFinancialPosting();
        $this->journal('payroll.approved.x', '2026-09-30 06:00:00', [['payroll_expense', '1000.00', '0.00'], ['payroll_payable', '0.00', '900.00'], ['payroll_deductions_payable', '0.00', '100.00']]);
        $maker = User::factory()->staff('finance')->create();
        $checker = User::factory()->staff('finance')->create();

        $refused = $this->actingAs($maker)->onPortal('manage')->postJson('/commands/operations/accounting/payables', $this->payload(['kind' => 'payroll_deductions', 'amount' => '100.00']))->assertOk()->json('data.id');
        $this->actingAs($checker)->postJson('/commands/operations/accounting/payables/'.$refused.'/review', ['decision' => 'rejected', 'reason' => 'Wrong amount'])->assertOk();
        // A refused record frees its receipt number.
        $withdrawn = $this->actingAs($maker)->postJson('/commands/operations/accounting/payables', $this->payload(['kind' => 'payroll_deductions', 'amount' => '100.00']))->assertOk()->json('data.id');
        $this->actingAs($checker)->postJson('/commands/operations/accounting/payables/'.$withdrawn.'/cancel', ['reason' => 'Not mine to take back'])->assertUnprocessable();
        $this->actingAs($maker)->postJson('/commands/operations/accounting/payables/'.$withdrawn.'/cancel', ['reason' => 'Recorded twice by mistake'])->assertOk();
        $this->assertSame(0, DB::table('journal_entries')->where('event_key', 'like', 'payable.remittance.%')->count());

        $paid = $this->postJson('/commands/operations/accounting/payables', $this->payload(['kind' => 'payroll_deductions', 'amount' => '100.00', 'reference' => 'bank-2']))->assertOk()->json('data.id');
        $this->actingAs($checker)->postJson('/commands/operations/accounting/payables/'.$paid.'/review', ['decision' => 'approved', 'reason' => 'Bank slip seen'])->assertOk();
        $this->assertSame(0, app(PayableRemittances::class)->figures('payroll_deductions')['balance']);
        $this->assertEquals(DB::table('journal_lines')->sum('debit'), DB::table('journal_lines')->sum('credit'));
    }

    public function test_monthly_summary_and_overdue_task(): void
    {
        $this->enableFinancialPosting();
        $this->withheld('w-aug', '2026-08-10 06:00:00', '30.00');
        $this->withheld('w-sep', '2026-09-10 06:00:00', '20.00');
        $this->journal('payable.remittance.manual', '2026-09-25 06:00:00', [['payout_withholding_payable', '30.00', '0.00'], ['manual_payout_clearing', '0.00', '30.00']]);

        $months = app(PayableRemittances::class)->monthly('withheld_tax');
        $this->assertSame([
            ['month' => '2026-09', 'withheld' => '20.00', 'remitted' => '30.00', 'still_payable' => '20.00'],
            ['month' => '2026-08', 'withheld' => '30.00', 'remitted' => '0.00', 'still_payable' => '30.00'],
        ], $months);

        // The August amount is paid, so the oldest unpaid one is from 10 September: 40 days, not overdue yet.
        $figures = app(PayableRemittances::class)->figures('withheld_tax');
        $this->assertSame(40, $figures['oldest_unpaid_days']);
        $this->assertSame(0, app(MoneyTaskFigures::class)->stalePayables()['count']);

        $this->travel(6)->days();
        $stale = app(MoneyTaskFigures::class)->stalePayables();
        $this->assertSame(1, $stale['count']);
        $this->assertSame('20.00', $stale['amount']);

        $owner = User::factory()->create(['role' => 'super_admin']);
        $this->actingAs($owner)->onPortal('sadmin')->get('/operations/accounting')->assertOk()
            ->assertInertia(fn ($page) => $page->where('payables.kinds.0.kind', 'withheld_tax')->where('payables.kinds.0.balance', '20.00')->where('payables.stale_days', 45));
    }
}
