<?php

namespace Tests\Feature;

use App\Domain\Finance\AccountingStatements;
use App\Models\User;
use App\Support\Money;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\TestCase;

class AccountingStatementsTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->withoutVite();
    }

    /**
     * @param  list<array{0: string, 1: string, 2: string}>  $lines  account, debit, credit
     */
    private function entry(string $when, array $lines): void
    {
        $id = (string) Str::uuid();
        DB::table('journal_entries')->insert(['id' => $id, 'event_key' => 'test.'.$id, 'description' => 'Test entry', 'created_at' => $when]);
        foreach ($lines as [$account, $debit, $credit]) {
            DB::table('journal_lines')->insert(['entry_id' => $id, 'account' => $account, 'debit' => $debit, 'credit' => $credit]);
        }
    }

    private function seedJournal(): void
    {
        // Before the period: an opening balance and one commission.
        $this->entry('2026-09-10 06:00:00', [['opening_balance_equity', '500.00', '0.00'], ['wallet_liability', '0.00', '500.00']]);
        $this->entry('2026-09-20 06:00:00', [['commission_expense', '120.50', '0.00'], ['wallet_liability', '0.00', '120.50']]);
        // Inside October: commission, payroll, a payout, a clinic sale and a clawback.
        $this->entry('2026-10-05 06:00:00', [['commission_expense', '300.25', '0.00'], ['wallet_liability', '0.00', '300.25']]);
        $this->entry('2026-10-06 06:00:00', [['payroll_expense', '1000.00', '0.00'], ['payroll_payable', '0.00', '900.00'], ['payroll_deductions_payable', '0.00', '100.00']]);
        $this->entry('2026-10-07 06:00:00', [['wallet_liability', '200.00', '0.00'], ['manual_payout_clearing', '0.00', '190.00'], ['payout_withholding_payable', '0.00', '10.00']]);
        $this->entry('2026-10-08 06:00:00', [['clinic_cash', '700.00', '0.00'], ['manufacturer_fund', '0.00', '300.00'], ['clinic_fund', '0.00', '400.00']]);
        $this->entry('2026-10-09 06:00:00', [['wallet_liability', '50.00', '0.00'], ['commission_clawback_recovery', '0.00', '50.00']]);
        // After the period: must not appear.
        $this->entry('2026-11-02 06:00:00', [['commission_expense', '999.99', '0.00'], ['wallet_liability', '0.00', '999.99']]);
    }

    public function test_the_excel_tab_of_a_bangla_statement_is_in_bangla(): void
    {
        $finance = User::factory()->staff('finance')->create(['locale' => 'bn']);

        $response = $this->actingAs($finance)->onPortal('manage')->get('/commands/operations/accounting/export?type=trial-balance&format=xlsx&from=2026-10-01&to=2026-10-31')->assertOk();

        $zip = new \ZipArchive;
        $this->assertTrue($zip->open($response->baseResponse->getFile()->getPathname()));
        $workbook = (string) $zip->getFromName('xl/workbook.xml');
        $zip->close();

        $this->assertStringNotContainsString('Trial Balance', $workbook);
        $this->assertStringContainsString('name="'.__('accounting.statement_trial_balance', [], 'bn').'"', $workbook);
    }

    public function test_trial_balance_ties_exactly_to_the_journal_for_the_period(): void
    {
        $this->seedJournal();

        $statements = app(AccountingStatements::class)->build('2026-10-01', '2026-10-31');

        $period = DB::table('journal_lines as l')->join('journal_entries as j', 'j.id', '=', 'l.entry_id')
            ->where('j.created_at', '>=', '2026-09-30 18:00:00')->where('j.created_at', '<', '2026-10-31 18:00:00')
            ->selectRaw('SUM(l.debit) AS debit, SUM(l.credit) AS credit')->first();

        $this->assertSame(Money::decimal(Money::cents((string) $period->debit)), $statements['trial_balance']['totals']['debit']);
        $this->assertSame(Money::decimal(Money::cents((string) $period->credit)), $statements['trial_balance']['totals']['credit']);
        $this->assertSame('2250.25', $statements['trial_balance']['totals']['debit']);
        $this->assertSame($statements['trial_balance']['totals']['debit'], $statements['trial_balance']['totals']['credit']);
        $this->assertSame('0.00', $statements['trial_balance']['totals']['opening']);
        $this->assertSame('0.00', $statements['trial_balance']['totals']['closing']);
        $this->assertTrue($statements['checks']['ties_to_journal']);
        $this->assertTrue($statements['checks']['journal_balanced']);
    }

    public function test_trial_balance_splits_opening_period_and_closing_for_each_account(): void
    {
        $this->seedJournal();

        $rows = collect(app(AccountingStatements::class)->build('2026-10-01', '2026-10-31')['trial_balance']['rows'])->keyBy('account');

        $this->assertSame('120.50', $rows['commission_expense']['opening']);
        $this->assertSame('300.25', $rows['commission_expense']['debit']);
        $this->assertSame('420.75', $rows['commission_expense']['closing']);
        $this->assertSame('-620.50', $rows['wallet_liability']['opening']);
        $this->assertSame('250.00', $rows['wallet_liability']['debit']);
        $this->assertSame('300.25', $rows['wallet_liability']['credit']);
        $this->assertSame('-670.75', $rows['wallet_liability']['closing']);
    }

    public function test_profit_and_loss_counts_only_this_period(): void
    {
        $this->seedJournal();

        $pl = app(AccountingStatements::class)->build('2026-10-01', '2026-10-31')['profit_loss'];

        $this->assertSame('400.00', $pl['total_income']);
        // 300.25 commission + 1000.00 salary - 50.00 taken back.
        $this->assertSame('1250.25', $pl['total_expenses']);
        $this->assertSame('-850.25', $pl['result']);
    }

    public function test_balance_sheet_balances_with_the_period_result(): void
    {
        $this->seedJournal();

        $sheet = app(AccountingStatements::class)->build('2026-10-01', '2026-10-31')['balance_sheet'];

        // Clinic cash 700.00 less payouts sent 190.00.
        $this->assertSame('510.00', $sheet['total_assets']);
        // Wallets 670.75 + salaries 900 + deductions 100 + tax held 10 + manufacturer 300.
        $this->assertSame('1980.75', $sheet['total_liabilities']);
        $this->assertSame('-500.00', $sheet['total_equity']);
        $this->assertSame('-120.50', $sheet['earlier_result']);
        $this->assertSame('-850.25', $sheet['period_result']);
        $this->assertSame($sheet['total_assets'], $sheet['total_liabilities_and_equity']);
        $this->assertSame('0.00', $sheet['difference']);
    }

    public function test_statements_still_balance_for_any_period_and_day_boundary(): void
    {
        $this->seedJournal();

        foreach ([['2026-09-01', '2026-09-30'], ['2026-10-05', '2026-10-05'], ['2026-10-01', '2026-11-30'], ['2020-01-01', '2020-12-31']] as [$from, $to]) {
            $checks = app(AccountingStatements::class)->build($from, $to);
            $this->assertTrue($checks['checks']['ties_to_journal'], $from);
            $this->assertTrue($checks['checks']['statement_balances'], $from);
            $this->assertSame('0.00', $checks['balance_sheet']['difference'], $from);
        }
    }

    public function test_period_days_are_counted_in_bangladesh_time(): void
    {
        // 18:30 UTC on 30 September is already 1 October in Dhaka.
        $this->entry('2026-09-30 18:30:00', [['commission_expense', '10.00', '0.00'], ['wallet_liability', '0.00', '10.00']]);

        $october = app(AccountingStatements::class)->build('2026-10-01', '2026-10-31');
        $september = app(AccountingStatements::class)->build('2026-09-01', '2026-09-30');

        $this->assertSame('10.00', $october['trial_balance']['totals']['debit']);
        $this->assertSame('0.00', $september['trial_balance']['totals']['debit']);
    }

    public function test_a_new_unsorted_account_is_shown_and_the_sheet_still_balances(): void
    {
        $this->entry('2026-10-05 06:00:00', [['some_future_account', '75.00', '0.00'], ['wallet_liability', '0.00', '75.00']]);

        $statements = app(AccountingStatements::class)->build('2026-10-01', '2026-10-31');

        $this->assertSame(['some_future_account'], $statements['checks']['unclassified_accounts']);
        $this->assertSame('some_future_account', $statements['balance_sheet']['unclassified'][0]['account']);
        $this->assertTrue($statements['checks']['statement_balances']);
    }

    public function test_an_unbalanced_journal_is_reported_and_not_hidden(): void
    {
        $this->entry('2026-10-05 06:00:00', [['commission_expense', '75.00', '0.00'], ['wallet_liability', '0.00', '70.00']]);

        $statements = app(AccountingStatements::class)->build('2026-10-01', '2026-10-31');

        $this->assertFalse($statements['checks']['journal_balanced']);
        $this->assertFalse($statements['checks']['statement_balances']);
        $this->assertSame('5.00', $statements['balance_sheet']['difference']);
    }

    public function test_reading_the_statements_never_writes_to_the_journal(): void
    {
        $this->seedJournal();
        $entries = DB::table('journal_entries')->count();
        $lines = DB::table('journal_lines')->count();

        app(AccountingStatements::class)->build('2026-10-01', '2026-10-31');

        $this->assertSame($entries, DB::table('journal_entries')->count());
        $this->assertSame($lines, DB::table('journal_lines')->count());
    }

    public function test_owner_and_finance_can_open_the_page_and_see_the_management_notice(): void
    {
        $this->seedJournal();
        $owner = User::factory()->create(['role' => 'super_admin', 'status' => 'active']);
        $finance = User::factory()->staff('finance')->create(['status' => 'active']);

        $this->actingAs($owner)->onPortal('sadmin')->get('/operations/accounting?from=2026-10-01&to=2026-10-31')
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->component('Accounting')
                ->where('statements.checks.ties_to_journal', true)
                ->where('translations.accounting.management_notice', __('accounting.management_notice')));

        $this->actingAs($finance)->onPortal('manage')->get('/operations/accounting')->assertOk();
    }

    public function test_notice_says_it_is_not_audited_in_both_languages(): void
    {
        $english = require lang_path('en/accounting.php');
        $bangla = require lang_path('bn/accounting.php');

        $this->assertStringContainsString('not audited', $english['management_notice']);
        $this->assertStringContainsString('অডিট', $bangla['management_notice']);
        $this->assertSame(array_keys($english), array_keys($bangla));
    }

    public function test_people_without_finance_permission_are_refused(): void
    {
        $reports = User::factory()->staff('employee')->create(['status' => 'active', 'profile' => ['permissions' => ['reports']]]);
        $dealer = User::factory()->dealer()->create(['status' => 'active']);

        $this->actingAs($reports)->onPortal('manage')->get('/operations/accounting')->assertForbidden();
        $this->actingAs($reports)->onPortal('manage')->get('/commands/operations/accounting/export?type=trial-balance&format=xlsx')->assertForbidden();
        $this->actingAs($dealer)->onPortal('dealer')->get('/commands/operations/accounting/export?type=trial-balance&format=xlsx')->assertNotFound();
        $this->actingAs($dealer)->onPortal('manage')->get('/operations/accounting')->assertForbidden();
    }

    public function test_finance_can_download_each_statement_and_the_download_is_logged(): void
    {
        $this->seedJournal();
        $finance = User::factory()->staff('finance')->create(['status' => 'active']);

        foreach (AccountingStatements::Types as $type) {
            $response = $this->actingAs($finance)->onPortal('manage')
                ->get('/commands/operations/accounting/export?type='.$type.'&format=xlsx&from=2026-10-01&to=2026-10-31');
            $response->assertOk();
            $this->assertStringContainsString('Madina-'.$type.'-2026-10-01-2026-10-31.xlsx', (string) $response->headers->get('content-disposition'));
            $this->assertDatabaseHas('audit_events', ['actor_id' => $finance->id, 'action' => 'report.exported', 'subject' => $type]);
        }
    }

    public function test_period_end_before_start_is_rejected(): void
    {
        $finance = User::factory()->staff('finance')->create(['status' => 'active']);

        $this->actingAs($finance)->onPortal('manage')->getJson('/commands/operations/accounting/export?type=trial-balance&format=xlsx&from=2026-10-10&to=2026-10-01')
            ->assertStatus(422);
    }
}
