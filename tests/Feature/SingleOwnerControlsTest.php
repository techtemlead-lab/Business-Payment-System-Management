<?php

namespace Tests\Feature;

use App\Domain\Commissions\CommissionWallet;
use App\Domain\Commissions\PolicyScheduling;
use App\Domain\Finance\FinancialReadiness;
use App\Domain\Finance\FundSpending;
use App\Domain\Finance\ImportWorkbook;
use App\Domain\Finance\OpeningBalanceImport;
use App\Domain\Finance\PaymentAccounts;
use App\Domain\Finance\PayrollService;
use App\Domain\Finance\PolicyDraftService;
use App\Domain\Finance\ReserveService;
use App\Domain\Inventory\StockCountService;
use App\Domain\Payouts\ManualPayoutService;
use App\Models\BranchProductStock;
use App\Models\CompanyBranch;
use App\Models\Product;
use App\Models\StockCountLine;
use App\Models\StockCountSession;
use App\Models\User;
use Database\Seeders\CommissionRuleSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use OpenSpout\Common\Entity\Cell;
use OpenSpout\Common\Entity\Row;
use OpenSpout\Writer\XLSX\Writer;
use Tests\TestCase;

class SingleOwnerControlsTest extends TestCase
{
    use RefreshDatabase;

    private User $owner;

    private const Reason = 'Checked by the owner';

    protected function setUp(): void
    {
        parent::setUp();
        $this->withoutVite();
        Storage::fake('local');
        $this->travelTo(now()->setDate(2026, 11, 8)->setTime(12, 0));
        $this->seed(CommissionRuleSeeder::class);
        $this->owner = User::factory()->staff('super_admin')->create();
    }

    public function test_staff_can_enter_money_but_not_approve_by_default_and_a_grant_is_kept(): void
    {
        $default = User::factory()->enterOnly()->create();
        $employee = User::factory()->create(['role' => 'employee', 'dealer_code' => null, 'profile' => ['permissions' => ['finance']]]);
        $trusted = User::factory()->staff('finance')->create();

        $this->assertTrue($default->canAct('finance', 'create'));
        $this->assertFalse($default->canAct('finance', 'approve'));
        $this->assertFalse($employee->canAct('finance', 'approve'));
        $this->assertTrue($trusted->canAct('finance', 'approve'), 'An approval granted in the matrix stays.');
        $this->assertTrue($this->owner->canAct('finance', 'approve'));
    }

    public function test_fresh_start_opens_the_money_gate_and_paper_records_can_still_be_imported(): void
    {
        config(['madina.financial_activation' => true, 'madina.preview_mode' => false]);
        $partner = User::factory()->dealer()->create(['dealer_code' => 'D-PAPER']);
        $this->assertGateClosed();

        app(FinancialReadiness::class)->freshStart($this->owner, 'New company, nothing to bring in', true);

        CommissionWallet::requireActive();
        $this->assertAudited('opening_balances.fresh_start');
        $imports = app(OpeningBalanceImport::class);
        $this->assertFalse($imports->openingLocked());

        $id = $imports->preview($this->owner, 'opening_balances', $this->workbook([['D-PAPER', 'OPEN-1', '2026-10-01', '100.00', '0.00', '0.00', '0.00']]), 'Paper records from the old shop');
        $imports->apply($this->owner, $id, self::Reason, true);

        $this->assertDatabaseHas('wallets', ['user_id' => $partner->id, 'available' => '100.00']);
        $this->assertAudited('data_import.applied');
    }

    public function test_fresh_start_needs_the_owner_a_confirmation_and_only_happens_once(): void
    {
        $finance = User::factory()->staff('finance')->create();
        $this->actingAs($finance)->postJson('/api/v1/operations/opening-balances/fresh-start', ['reason' => self::Reason, 'self_approve' => true])->assertForbidden();
        $this->actingAs($this->owner)->postJson('/api/v1/operations/opening-balances/fresh-start', ['reason' => self::Reason, 'self_approve' => false])->assertUnprocessable();
        $this->actingAs($this->owner)->postJson('/api/v1/operations/opening-balances/fresh-start', ['reason' => self::Reason, 'self_approve' => true])->assertOk();
        $this->actingAs($this->owner)->postJson('/api/v1/operations/opening-balances/fresh-start', ['reason' => self::Reason, 'self_approve' => true])->assertUnprocessable();
    }

    public function test_rule_1_opening_check_owner_alone_and_staff_then_owner(): void
    {
        config(['madina.financial_activation' => true, 'madina.preview_mode' => false]);
        $readiness = app(FinancialReadiness::class);
        $own = $readiness->submit($this->owner, $this->zeroTotals(), 'Owner closing statement', $this->pdf());

        $this->assertRefused(fn () => $readiness->verify($this->owner, $own), 'balances', __('self_approval.err_confirm'));
        $readiness->verify($this->owner, $own, self::Reason, true);
        CommissionWallet::requireActive();
        $this->assertAudited('opening_balances.verified');

        DB::table('financial_verifications')->delete();
        $staff = User::factory()->enterOnly()->create();
        $entered = $readiness->submit($staff, $this->zeroTotals(), 'Staff closing statement', $this->pdf());
        $readiness->verify($this->owner, $entered);
        $this->assertDatabaseHas('financial_verifications', ['id' => $entered, 'verified_by' => $this->owner->id]);
    }

    public function test_rule_1_is_refused_when_a_second_approver_exists(): void
    {
        $readiness = app(FinancialReadiness::class);
        $id = $readiness->submit($this->owner, $this->zeroTotals(), 'Owner closing statement', $this->pdf());
        User::factory()->staff('finance')->create();

        $this->assertRefused(fn () => $readiness->verify($this->owner, $id, self::Reason, true), 'balances', __('portal.err_ready_separate_verify'));
    }

    public function test_rule_2_opening_import_is_refused_when_a_second_approver_exists(): void
    {
        User::factory()->dealer()->create(['dealer_code' => 'D-OPEN']);
        $imports = app(OpeningBalanceImport::class);
        $id = $imports->preview($this->owner, 'opening_balances', $this->workbook([['D-OPEN', 'OPEN-2', '2026-10-01', '10.00', '0.00', '0.00', '0.00']]), 'Client closing statement');
        User::factory()->staff('finance')->create();

        $this->assertRefused(fn () => $imports->apply($this->owner, $id, self::Reason, true), 'import', __('portal.err_import_separate_reviewer'));
    }

    public function test_rule_2_staff_prepares_and_owner_approves(): void
    {
        $partner = User::factory()->dealer()->create(['dealer_code' => 'D-STAFF']);
        $imports = app(OpeningBalanceImport::class);
        $id = $imports->preview(User::factory()->enterOnly()->create(), 'opening_balances', $this->workbook([['D-STAFF', 'OPEN-3', '2026-10-01', '20.00', '0.00', '0.00', '0.00']]), 'Client closing statement');

        $imports->apply($this->owner, $id, self::Reason);

        $this->assertDatabaseHas('wallets', ['user_id' => $partner->id, 'available' => '20.00']);
    }

    public function test_rule_3_owner_approves_and_pays_a_withdrawal_alone_with_a_receipt(): void
    {
        $this->openMoneyGate();
        $partner = User::factory()->dealer()->create();
        $service = app(ManualPayoutService::class);
        $id = $this->withdrawal($partner);
        $service->review($this->owner, $id, 'approved', self::Reason);

        $this->assertRefused(fn () => $this->settle($id, false, $this->pdf()), 'withdrawal', __('self_approval.err_confirm'));
        $this->assertRefused(fn () => $this->settle($id, true, null), 'withdrawal', __('self_approval.err_receipt'));
        $this->settle($id, true, $this->pdf());

        $this->assertDatabaseHas('withdrawals', ['id' => $id, 'status' => 'paid', 'paid_by' => $this->owner->id]);
        $this->assertAudited('withdrawal.paid');
    }

    public function test_rule_3_a_second_payer_blocks_paying_alone_and_can_finish_it(): void
    {
        $this->openMoneyGate();
        $id = $this->withdrawal(User::factory()->dealer()->create());
        app(ManualPayoutService::class)->review($this->owner, $id, 'approved', self::Reason);
        $second = User::factory()->staff('finance')->create();

        $this->assertRefused(fn () => $this->settle($id, true, $this->pdf()), 'withdrawal', __('portal.err_wd_payer_separation'));

        app(ManualPayoutService::class)->settle($second, $id, ['method' => 'cash', 'received_by' => 'Rahim'], null, null, now()->toDateTimeString(), 'Paid over the counter');
        $this->assertDatabaseHas('withdrawals', ['id' => $id, 'status' => 'paid', 'paid_by' => $second->id]);
    }

    public function test_rule_3_owner_never_pays_their_own_withdrawal(): void
    {
        $this->openMoneyGate();
        $id = $this->withdrawal($this->owner, 'approved', $this->owner->id);

        $this->assertRefused(fn () => $this->settle($id, true, $this->pdf()), 'withdrawal', __('portal.err_wd_payer_separation'));
    }

    public function test_rule_4_owner_runs_payroll_alone_and_stays_off_it(): void
    {
        $this->openMoneyGate();
        $payroll = app(PayrollService::class);
        $employee = User::factory()->enterOnly()->create();
        $this->assertRefused(fn () => $payroll->contract($this->owner, $this->contract($this->owner)), 'staff_id', __('self_approval.err_owner_not_on_payroll'));
        $payroll->contract($this->owner, $this->contract($employee));
        DB::table('staff_contracts')->insert([...$this->contract($this->owner), 'revision' => 1, 'created_by' => $this->owner->id, 'created_at' => now(), 'updated_at' => now()]);

        $ids = $payroll->prepare($this->owner, '2026-10');
        $this->assertCount(1, $ids, 'The owner is left off payroll, even with an old contract on file.');
        $this->assertDatabaseMissing('payroll_entries', ['staff_id' => $this->owner->id]);

        $payroll->review($this->owner, $ids[0], 'approved', self::Reason, true);
        $this->assertAudited('payroll.approved');
        $payroll->pay($this->owner, $ids[0], $this->payrollPayment('PAY-ALONE'), $this->pdf(), true);

        $this->assertDatabaseHas('payroll_entries', ['id' => $ids[0], 'status' => 'paid', 'paid_by' => $this->owner->id]);
        $this->assertAudited('payroll.paid');
    }

    public function test_rule_4_staff_prepares_owner_approves_owner_pays_a_sole_clerks_own_salary(): void
    {
        $this->openMoneyGate();
        $payroll = app(PayrollService::class);
        $clerk = User::factory()->enterOnly()->create();
        $payroll->contract($this->owner, $this->contract($clerk));
        $ids = $payroll->prepare($clerk, '2026-10');

        $payroll->review($this->owner, $ids[0], 'approved', self::Reason);
        $payroll->pay($this->owner, $ids[0], $this->payrollPayment('PAY-CLERK'), $this->pdf(), true);

        $this->assertDatabaseHas('payroll_entries', ['id' => $ids[0], 'status' => 'paid', 'prepared_by' => $clerk->id, 'approved_by' => $this->owner->id]);
    }

    public function test_rule_4_is_refused_when_a_second_approver_exists_or_the_actor_is_the_payee(): void
    {
        $this->openMoneyGate();
        $payroll = app(PayrollService::class);
        $employee = User::factory()->enterOnly()->create();
        $payroll->contract($this->owner, $this->contract($employee));
        $ids = $payroll->prepare($this->owner, '2026-10');
        User::factory()->staff('finance')->create();

        $this->assertRefused(fn () => $payroll->review($this->owner, $ids[0], 'approved', self::Reason, true), 'payroll', __('portal.err_payroll_reviewer'));

        DB::table('payroll_entries')->where('id', $ids[0])->update(['staff_id' => $this->owner->id]);
        $this->assertRefused(fn () => $payroll->review($this->owner, $ids[0], 'approved', self::Reason, true), 'payroll', __('portal.err_payroll_reviewer'));
    }

    public function test_rule_5_marketing_expense_owner_alone_needs_a_receipt_to_pay(): void
    {
        $this->openMoneyGate();
        $this->allocation('company_marketing_fund', '500.00');
        $reserves = app(ReserveService::class);
        $id = $reserves->request($this->owner, $this->marketing());

        $reserves->review($this->owner, $id, 'approved', self::Reason, true);
        $this->assertAudited('reserve.approved');
        $this->assertRefused(fn () => $reserves->pay($this->owner, $id, $this->reservePayment(), null, true), 'reserve', __('self_approval.err_receipt'));
        $reserves->pay($this->owner, $id, $this->reservePayment(), $this->pdf(), true);

        $this->assertDatabaseHas('reserve_disbursements', ['id' => $id, 'status' => 'paid', 'paid_by' => $this->owner->id]);
        $this->assertAudited('reserve.paid');
    }

    public function test_rule_5_staff_requests_and_owner_approves_but_a_second_approver_blocks_alone(): void
    {
        $this->openMoneyGate();
        $this->allocation('company_marketing_fund', '500.00');
        $reserves = app(ReserveService::class);
        $entered = $reserves->request(User::factory()->enterOnly()->create(), $this->marketing());
        $reserves->review($this->owner, $entered, 'approved', self::Reason);
        $this->assertDatabaseHas('reserve_disbursements', ['id' => $entered, 'status' => 'approved', 'approved_by' => $this->owner->id]);

        $own = $reserves->request($this->owner, $this->marketing());
        User::factory()->staff('finance')->create();
        $this->assertRefused(fn () => $reserves->review($this->owner, $own, 'approved', self::Reason, true), 'reserve', __('portal.err_reserve_reviewer'));
    }

    public function test_rule_5_owner_never_approves_a_reward_for_themselves(): void
    {
        $this->openMoneyGate();
        $id = DB::table('reserve_disbursements')->insertGetId(['program' => 'bike', 'user_id' => $this->owner->id, 'amount' => '10.00', 'request_key' => (string) Str::uuid(), 'reason' => 'Self reward', 'created_by' => $this->owner->id, 'created_at' => now(), 'updated_at' => now()]);

        $this->assertRefused(fn () => app(ReserveService::class)->review($this->owner, $id, 'approved', self::Reason, true), 'reserve', __('portal.err_reserve_reviewer'));
    }

    public function test_rule_6_owner_approves_their_own_spend_alone_with_a_receipt(): void
    {
        $this->openMoneyGate();
        $this->allocation('company_profit_fund', '500.00');
        $funds = app(FundSpending::class);
        $id = $funds->request($this->owner, $this->spend('company_profit'), null);

        $this->assertRefused(fn () => $funds->review($this->owner, $id, 'approved', self::Reason, true), 'spend', __('self_approval.err_receipt'));
        $funds->review($this->owner, $id, 'approved', self::Reason, true, $this->pdf());

        $this->assertDatabaseHas('reserve_disbursements', ['id' => $id, 'status' => 'paid', 'approved_by' => $this->owner->id]);
        $this->assertNotNull(DB::table('reserve_disbursements')->where('id', $id)->value('receipt_path'));
        $this->assertAudited('fund.spend_approved');
    }

    public function test_rule_6_staff_records_and_owner_approves_but_a_second_approver_blocks_alone(): void
    {
        $this->openMoneyGate();
        $this->allocation('company_profit_fund', '500.00');
        $funds = app(FundSpending::class);
        $entered = $funds->request(User::factory()->enterOnly()->create(), $this->spend('company_profit'), null);
        $funds->review($this->owner, $entered, 'approved', self::Reason);
        $this->assertDatabaseHas('reserve_disbursements', ['id' => $entered, 'status' => 'paid']);

        $own = $funds->request($this->owner, $this->spend('company_profit'), $this->pdf());
        User::factory()->staff('finance')->create();
        $this->assertRefused(fn () => $funds->review($this->owner, $own, 'approved', self::Reason, true), 'spend', __('funds.err_own_spend'));
    }

    public function test_rule_7_benefits_fund_owner_alone_until_another_owner_exists(): void
    {
        $this->openMoneyGate();
        $this->benefitsMoney();
        $funds = app(FundSpending::class);
        User::factory()->staff('finance')->create();
        $id = $funds->request($this->owner, $this->spend('benefits'), $this->pdf());

        $funds->review($this->owner, $id, 'approved', self::Reason, true);
        $this->assertDatabaseHas('reserve_disbursements', ['id' => $id, 'status' => 'paid']);
        $this->assertAudited('fund.spend_approved');

        User::factory()->create(['role' => 'md', 'dealer_code' => null]);
        $next = $funds->request($this->owner, $this->spend('benefits'), $this->pdf());
        $this->assertRefused(fn () => $funds->review($this->owner, $next, 'approved', self::Reason, true), 'spend', __('funds.err_own_spend'));
    }

    public function test_recorder_can_cancel_their_own_unreviewed_spend_and_nobody_else_can(): void
    {
        $this->openMoneyGate();
        $this->allocation('company_profit_fund', '500.00');
        $funds = app(FundSpending::class);
        $staff = User::factory()->enterOnly()->create();
        $id = $funds->request($staff, $this->spend('company_profit'), null);

        $this->assertRefused(fn () => $funds->cancel($this->owner, $id, self::Reason), 'spend', __('self_approval.err_cancel_spend'));
        $funds->cancel($staff, $id, self::Reason);

        $this->assertDatabaseHas('reserve_disbursements', ['id' => $id, 'status' => 'cancelled']);
        $this->assertDatabaseHas('audit_events', ['action' => 'fund.spend_cancelled', 'subject' => (string) $id]);
    }

    public function test_rule_8_owner_schedule_applies_at_once_and_staff_schedule_waits_for_the_owner(): void
    {
        $partner = User::factory()->dealer()->create();
        $schedules = app(PolicyScheduling::class);
        $own = $schedules->propose($this->owner, $this->schedule($partner));

        $this->assertDatabaseHas('commission_policy_schedules', ['id' => $own, 'status' => 'approved', 'approved_by' => $this->owner->id]);
        $this->assertAudited('policy_schedule.approved');

        $entered = $schedules->propose(User::factory()->enterOnly()->create(), $this->schedule($partner));
        $this->assertDatabaseHas('commission_policy_schedules', ['id' => $entered, 'status' => 'draft']);
        $schedules->review($this->owner, $entered, 'approved', self::Reason);
        $this->assertDatabaseHas('commission_policy_schedules', ['id' => $entered, 'status' => 'approved', 'approved_by' => $this->owner->id]);
    }

    public function test_rule_9_policy_draft_owner_alone_staff_then_owner_and_second_person(): void
    {
        $drafts = app(PolicyDraftService::class);
        $own = $this->draft($this->owner);

        $this->assertRefused(fn () => $drafts->publish($this->owner, $own), 'policy', __('self_approval.err_confirm'));
        $drafts->publish($this->owner, $own, self::Reason, true);
        $this->assertDatabaseHas('policy_drafts', ['id' => $own, 'status' => 'published', 'self_approved' => true]);
        $this->assertAudited('policy.published');

        $entered = $this->draft(User::factory()->enterOnly()->create());
        $drafts->publish($this->owner, $entered);
        $this->assertDatabaseHas('policy_drafts', ['id' => $entered, 'status' => 'published', 'self_approved' => false]);

        $next = $this->draft($this->owner);
        User::factory()->staff('finance')->create();
        $this->assertRefused(fn () => $drafts->publish($this->owner, $next, self::Reason, true), 'policy', __('portal.err_draft_publisher'));
    }

    public function test_rule_10_owner_decides_their_own_stock_count_alone(): void
    {
        $counts = app(StockCountService::class);
        [$session, $product, $branch] = $this->submittedCount($this->owner);

        $this->assertRefused(fn () => $counts->approve($this->owner, $session, 'Shelf recount', false), 'count', __('self_approval.err_confirm'));
        $counts->approve($this->owner, $session, 'Shelf recount', true);

        $this->assertSame(StockCountSession::StatusApproved, $session->fresh()->status);
        $this->assertSame(7, BranchProductStock::query()->where('branch_id', $branch->id)->where('product_id', $product->id)->value('quantity'));
        $this->assertAudited('stock_count.approved');
    }

    public function test_rule_10_staff_counts_owner_approves_and_a_second_decider_blocks_alone(): void
    {
        $counts = app(StockCountService::class);
        [$session] = $this->submittedCount(User::factory()->staff('inventory')->create());
        $counts->approve($this->owner, $session);
        $this->assertSame(StockCountSession::StatusApproved, $session->fresh()->status);

        [$own] = $this->submittedCount($this->owner);
        User::factory()->staff('inventory')->create();
        DB::table('users')->where('role', 'inventory')->update(['profile' => json_encode(['all_branches' => true])]);
        $other = User::factory()->create(['role' => 'super_admin', 'dealer_code' => null]);
        $this->assertRefused(fn () => $counts->approve($this->owner, $own, 'Shelf recount', true), 'count', __('purchasing.err_second_person'));
        $other->update(['status' => 'suspended']);
    }

    public function test_rule_10_a_submitted_count_can_be_cancelled_with_a_reason(): void
    {
        $counts = app(StockCountService::class);
        [$session] = $this->submittedCount($this->owner);

        $this->assertRefused(fn () => $counts->cancel($this->owner, $session), 'count', __('self_approval.err_reason'));
        $counts->cancel($this->owner, $session, 'Counted the wrong shelf');

        $this->assertSame(StockCountSession::StatusCancelled, $session->fresh()->status);
    }

    public function test_staff_can_never_finish_a_step_alone(): void
    {
        $this->openMoneyGate();
        $this->allocation('company_profit_fund', '500.00');
        DB::table('users')->where('id', $this->owner->id)->update(['status' => 'suspended']);
        $trusted = User::factory()->staff('finance')->create();
        $funds = app(FundSpending::class);
        $id = $funds->request($trusted, $this->spend('company_profit'), $this->pdf());

        $this->assertRefused(fn () => $funds->review($trusted, $id, 'approved', self::Reason, true), 'spend', __('funds.err_own_spend'));
    }

    public function test_owners_drawings_head_is_seeded_and_editable(): void
    {
        $head = DB::table('fund_expense_heads')->where('name_en', "Owner's drawings")->first();

        $this->assertNotNull($head);
        $this->assertSame('মালিকের উত্তোলন', $head->name_bn);
        app(FundSpending::class)->updateHead($this->owner, (int) $head->id, ['name_en' => "Owner's drawings", 'name_bn' => 'মালিকের নেওয়া টাকা', 'is_active' => true]);
        $this->assertDatabaseHas('fund_expense_heads', ['id' => $head->id, 'name_bn' => 'মালিকের নেওয়া টাকা']);
    }

    private function openMoneyGate(): void
    {
        config(['madina.financial_activation' => true, 'madina.preview_mode' => false]);
        app(FinancialReadiness::class)->freshStart($this->owner, 'New company, nothing to bring in', true);
    }

    private function assertGateClosed(): void
    {
        try {
            CommissionWallet::requireActive();
        } catch (ValidationException) {
            $this->addToAssertionCount(1);

            return;
        }
        $this->fail('The money gate should still be closed.');
    }

    private function assertRefused(callable $attempt, string $field, string $message): void
    {
        try {
            $attempt();
        } catch (ValidationException $exception) {
            $this->assertSame($message, $exception->errors()[$field][0] ?? null);

            return;
        }
        $this->fail('Expected this step to be refused.');
    }

    private function assertAudited(string $action): void
    {
        $metadata = DB::table('audit_events')->where('action', $action)->latest('id')->value('metadata');
        $this->assertNotNull($metadata, "Missing audit entry for {$action}.");
        $this->assertTrue(json_decode((string) $metadata, true)['self_approved'] ?? false, "{$action} must be marked self_approved.");
    }

    private function pdf(): UploadedFile
    {
        return UploadedFile::fake()->create('receipt.pdf', 5, 'application/pdf');
    }

    /** @return array<string, string> */
    private function zeroTotals(): array
    {
        return ['wallet_available' => '0.00', 'wallet_held' => '0.00', 'order_paid' => '0.00', 'withdrawal_paid' => '0.00'];
    }

    /** @param list<list<string>> $rows */
    private function workbook(array $rows): UploadedFile
    {
        $path = tempnam(sys_get_temp_dir(), 'madina-single-owner-');
        $writer = new Writer;
        $writer->openToFile($path);
        $writer->getCurrentSheet()->setName('Data');
        $writer->addRow(Row::fromValues(ImportWorkbook::Columns['opening_balances']));
        foreach ($rows as $row) {
            $writer->addRow(new Row(array_map(fn (string $value): Cell => Cell::fromValue($value), $row)));
        }
        $writer->close();
        $file = UploadedFile::fake()->createWithContent('source.xlsx', (string) file_get_contents($path));
        unlink($path);

        return $file;
    }

    private function withdrawal(User $partner, string $status = 'requested', ?int $reviewer = null): string
    {
        $id = (string) Str::uuid();
        DB::table('wallets')->updateOrInsert(['user_id' => $partner->id], ['available' => '0.00', 'held' => '200.00', 'commission_held' => '0.00', 'created_at' => now(), 'updated_at' => now()]);
        DB::table('withdrawals')->insert(['id' => $id, 'user_id' => $partner->id, 'amount' => '200.00', 'status' => $status, 'channel' => 'Bank', 'destination' => encrypt('TEST-ACCOUNT-1234'),
            'idempotency_key' => (string) Str::uuid(), 'reviewed_by' => $reviewer, 'created_at' => now(), 'updated_at' => now()]);

        return $id;
    }

    private function settle(string $id, bool $confirmed, ?UploadedFile $receipt): string
    {
        return app(ManualPayoutService::class)->settle($this->owner, $id, ['method' => 'cash', 'received_by' => 'Rahim'], $receipt, null, now()->toDateTimeString(), 'Paid over the counter', null, $confirmed);
    }

    /** @return array<string, mixed> */
    private function contract(User $staff): array
    {
        return ['staff_id' => $staff->id, 'effective_month' => '2026-10', 'base_salary' => '1000.00', 'allowance' => '0.00', 'deduction' => '0.00', 'reason' => 'Agreed monthly salary'];
    }

    /** @return array<string, mixed> */
    private function payrollPayment(string $reference): array
    {
        return ['payment_account_id' => $this->account(), 'payment_reference' => $reference, 'paid_at' => now()->subMinute()->toDateTimeString(), 'reason' => 'Salary paid by bank'];
    }

    private function account(): int
    {
        return app(PaymentAccounts::class)->save($this->owner, ['label' => 'Company bank '.Str::random(5), 'channel' => 'Bank', 'holder' => 'Madina',
            'account_number' => 'BANK-'.random_int(100000, 999999), 'collects_payments' => true, 'pays_out' => true, 'is_active' => true, 'reason' => 'Approved company account']);
    }

    /** @return array<string, mixed> */
    private function marketing(): array
    {
        return ['program' => 'marketing', 'user_id' => null, 'payee_name' => 'Print shop', 'amount' => '100.00', 'request_key' => (string) Str::uuid(), 'reason' => 'Leaflets for the launch'];
    }

    /** @return array<string, mixed> */
    private function reservePayment(): array
    {
        return ['method' => 'cash', 'received_by' => 'Rahim', 'paid_at' => now()->subMinute()->toDateTimeString(), 'reason' => 'Paid at the office'];
    }

    /** @return array<string, mixed> */
    private function spend(string $fund): array
    {
        return ['fund' => $fund, 'expense_head_id' => (int) DB::table('fund_expense_heads')->where('name_en', "Owner's drawings")->value('id'), 'amount' => '100.00', 'spent_on' => '2026-11-07',
            'payee' => 'The owner', 'payment_method' => 'cash', 'request_key' => (string) Str::uuid()];
    }

    private function order(): string
    {
        $id = (string) Str::uuid();
        DB::table('orders')->insert(['id' => $id, 'number' => 'T-'.Str::random(8), 'user_id' => User::factory()->dealer()->create()->id, 'status' => 'delivered', 'total' => '1000.00', 'paid' => '1000.00',
            'idempotency_key' => (string) Str::uuid(), 'payload_hash' => str_repeat('a', 64), 'commission_policy_snapshot' => '{}', 'created_at' => now(), 'updated_at' => now()]);

        return $id;
    }

    private function allocation(string $category, string $amount): void
    {
        DB::table('commission_allocations')->insert(['order_id' => $this->order(), 'category' => $category, 'rate_percentage' => '0.00', 'cash_basis' => '1000.00', 'amount' => $amount,
            'original_amount' => $amount, 'status' => 'reserved', 'metadata' => '{}', 'created_at' => now(), 'updated_at' => now()]);
    }

    private function benefitsMoney(): void
    {
        DB::table('commission_batches')->insert(['order_id' => $this->order(), 'account_type' => 'dealer', 'period_month' => '2026-10', 'policy' => '{}', 'cash_basis' => '1000.00', 'company_share' => '0.00', 'total_allocated' => '0.00', 'unallocated' => '1000.00', 'refunded_amount' => '0.00', 'created_at' => now(), 'updated_at' => now()]);
    }

    /** @return array<string, mixed> */
    private function schedule(User $partner): array
    {
        return ['scope' => 'recipient', 'user_id' => $partner->id, 'rules' => ['direct_referral' => ['percentage' => '12.00', 'funding_mode' => 'company']], 'effective_at' => now()->addHour()->toIso8601String(), 'reason' => 'Special plan for this dealer'];
    }

    private function draft(User $actor): int
    {
        return (int) DB::table('policy_drafts')->insertGetId(['name' => 'Plan '.Str::random(4), 'account_type' => 'dealer', 'basis' => 'purchase_value', 'components' => '[]', 'notes' => 'Draft', 'created_by' => $actor->id, 'created_at' => now(), 'updated_at' => now()]);
    }

    /** @return array{0: StockCountSession, 1: Product, 2: CompanyBranch} */
    private function submittedCount(User $counter): array
    {
        $branch = CompanyBranch::factory()->create();
        $product = Product::factory()->create(['stock' => 10]);
        BranchProductStock::query()->create(['branch_id' => $branch->id, 'product_id' => $product->id, 'quantity' => 10]);
        if ($counter->role !== 'super_admin') {
            DB::table('branch_staff_assignments')->insert(['branch_id' => $branch->id, 'user_id' => $counter->id, 'created_at' => now(), 'updated_at' => now()]);
        }
        $counts = app(StockCountService::class);
        $session = $counts->start($counter, $branch->id);
        $line = StockCountLine::query()->where('stock_count_session_id', $session->id)->where('product_id', $product->id)->firstOrFail();
        $counts->saveCounts($counter, $session, [['line_id' => $line->id, 'counted_quantity' => 7]]);

        return [$counts->submit($counter, $session), $product, $branch];
    }
}
