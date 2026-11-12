<?php

namespace Tests\Feature;

use App\Domain\Commissions\PolicyScheduling;
use App\Domain\Finance\FinancialReadiness;
use App\Domain\Finance\PaymentAccounts;
use App\Domain\Inventory\StockCountService;
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
use Inertia\Testing\AssertableInertia as Assert;
use Tests\TestCase;

/**
 * The company has one owner and nobody ranks above them: the owner finishes a step they
 * started themselves, even when other staff could have done it. Staff still hand the next
 * step to someone else.
 */
class OwnerActsAloneTest extends TestCase
{
    use RefreshDatabase;

    private const Reason = 'Checked by the owner';

    private User $owner;

    /** A staff member the owner trusted with money approvals: the "second person" of the old rule. */
    private User $approver;

    protected function setUp(): void
    {
        parent::setUp();
        $this->withoutVite();
        Storage::fake('local');
        $this->travelTo(now()->setDate(2026, 11, 8)->setTime(12, 0));
        $this->seed(CommissionRuleSeeder::class);
        $this->owner = User::factory()->staff('super_admin')->create();
        $this->approver = User::factory()->staff('finance')->create();
        config(['madina.financial_activation' => true, 'madina.preview_mode' => false]);
        app(FinancialReadiness::class)->freshStart($this->owner, 'New company, nothing to bring in', true);
    }

    public function test_owner_reviews_and_pays_the_salaries_they_prepared(): void
    {
        $clerk = $this->staffOnSalary();
        $entry = $this->prepareSalaries($this->owner)[0];

        $this->actingAs($this->owner)->postJson("/api/v1/operations/payroll/{$entry}/review", ['decision' => 'approved', 'reason' => self::Reason])->assertOk();
        $this->actingAs($this->owner)->post("/api/v1/operations/payroll/{$entry}/pay", $this->salaryPayment(), ['Accept' => 'application/json'])->assertOk();

        $this->assertDatabaseHas('payroll_entries', ['id' => $entry, 'staff_id' => $clerk->id, 'status' => 'paid', 'prepared_by' => $this->owner->id, 'approved_by' => $this->owner->id, 'paid_by' => $this->owner->id]);
        $this->assertDoneAlone('payroll.approved', $entry);
        $this->assertDoneAlone('payroll.paid', $entry);
    }

    public function test_staff_cannot_review_salaries_they_prepared_or_pay_ones_they_approved(): void
    {
        $this->staffOnSalary();
        $prepared = $this->prepareSalaries($this->approver)[0];

        $this->actingAs($this->approver)->postJson("/api/v1/operations/payroll/{$prepared}/review", ['decision' => 'approved', 'reason' => self::Reason])
            ->assertUnprocessable()->assertJsonPath('errors.payroll.0', __('portal.err_payroll_reviewer'));

        DB::table('payroll_entries')->where('id', $prepared)->update(['status' => 'approved', 'approved_by' => $this->approver->id]);
        $this->actingAs($this->approver)->post("/api/v1/operations/payroll/{$prepared}/pay", $this->salaryPayment(), ['Accept' => 'application/json'])
            ->assertUnprocessable()->assertJsonPath('errors.payroll.0', __('portal.err_payroll_payer'));
        $this->assertDatabaseHas('payroll_entries', ['id' => $prepared, 'status' => 'approved', 'paid_by' => null]);
    }

    public function test_owner_approves_a_spend_they_recorded_without_a_receipt_or_a_second_owner(): void
    {
        User::factory()->staff('md')->create();
        $this->fundMoney();

        $spend = $this->actingAs($this->owner)->postJson('/api/v1/operations/company-funds/spends', $this->spend())->assertOk()->json('data.id');
        $this->actingAs($this->owner)->postJson("/api/v1/operations/company-funds/spends/{$spend}/review", ['decision' => 'approved', 'reason' => self::Reason])->assertOk();

        $this->assertDatabaseHas('reserve_disbursements', ['id' => $spend, 'status' => 'paid', 'created_by' => $this->owner->id, 'approved_by' => $this->owner->id]);
        $this->assertDoneAlone('fund.spend_approved', (string) $spend);
    }

    public function test_staff_cannot_approve_a_spend_they_recorded(): void
    {
        $this->fundMoney();
        $spend = $this->actingAs($this->approver)->postJson('/api/v1/operations/company-funds/spends', $this->spend())->assertOk()->json('data.id');

        $this->actingAs($this->approver)->postJson("/api/v1/operations/company-funds/spends/{$spend}/review", ['decision' => 'approved', 'reason' => self::Reason])
            ->assertUnprocessable()->assertJsonPath('errors.spend.0', __('funds.err_own_spend'));
        $this->assertDatabaseHas('reserve_disbursements', ['id' => $spend, 'status' => 'requested']);
    }

    public function test_the_md_still_needs_someone_else_for_a_spend_they_recorded(): void
    {
        $managingDirector = User::factory()->staff('md')->create();
        $this->fundMoney();
        $spend = $this->actingAs($managingDirector)->postJson('/api/v1/operations/company-funds/spends', $this->spend())->assertOk()->json('data.id');

        $this->actingAs($managingDirector)->postJson("/api/v1/operations/company-funds/spends/{$spend}/review", ['decision' => 'approved', 'reason' => self::Reason])
            ->assertUnprocessable()->assertJsonPath('errors.spend.0', __('funds.err_own_spend'));
    }

    public function test_owner_pays_a_withdrawal_they_approved_and_the_page_offers_it(): void
    {
        $withdrawal = $this->withdrawal();
        $this->actingAs($this->owner)->post("/commands/withdrawals/{$withdrawal}/review", ['decision' => 'approved', 'reason' => self::Reason])->assertSessionHasNoErrors();

        $this->actingAs($this->owner)->onPortal('sadmin')->get("/withdrawals/{$withdrawal}")
            ->assertOk()->assertInertia(fn (Assert $page) => $page->where('withdrawal.payer_state', null));
        $this->actingAs($this->owner)->post("/commands/withdrawals/{$withdrawal}/record-payment", $this->cashPayout())->assertSessionHasNoErrors();

        $this->assertDatabaseHas('withdrawals', ['id' => $withdrawal, 'status' => 'paid', 'reviewed_by' => $this->owner->id, 'paid_by' => $this->owner->id]);
        $this->assertDoneAlone('withdrawal.paid', $withdrawal);
    }

    public function test_staff_cannot_pay_a_withdrawal_they_approved_and_the_page_says_so(): void
    {
        $withdrawal = $this->withdrawal();
        $this->actingAs($this->approver)->post("/commands/withdrawals/{$withdrawal}/review", ['decision' => 'approved', 'reason' => self::Reason])->assertSessionHasNoErrors();

        $this->actingAs($this->approver)->onPortal('manage')->get("/withdrawals/{$withdrawal}")
            ->assertOk()->assertInertia(fn (Assert $page) => $page->where('withdrawal.payer_state', 'second_person'));
        $this->actingAs($this->approver)->post("/commands/withdrawals/{$withdrawal}/record-payment", $this->cashPayout())
            ->assertSessionHasErrors(['withdrawal' => __('portal.err_wd_payer_separation')]);
        $this->assertDatabaseHas('withdrawals', ['id' => $withdrawal, 'status' => 'approved']);
    }

    public function test_owner_approves_a_stock_count_they_took_part_in(): void
    {
        $session = $this->submittedCount($this->owner);

        $this->actingAs($this->owner)->onPortal('sadmin')->get('/stock-counts')
            ->assertOk()->assertInertia(fn (Assert $page) => $page->where('data.stock_counts.selected.id', $session->id)->where('data.stock_counts.selected.can_decide', true));
        $this->actingAs($this->owner)->post("/commands/stock-counts/{$session->id}/approve")->assertSessionHasNoErrors();

        $this->assertSame(StockCountSession::StatusApproved, $session->fresh()->status);
        $this->assertDoneAlone('stock_count.approved', (string) $session->id);
    }

    public function test_staff_cannot_approve_a_stock_count_they_took_part_in(): void
    {
        $counter = User::factory()->staff('inventory')->create();
        $session = $this->submittedCount($counter);

        $this->actingAs($counter)->onPortal('manage')->get('/stock-counts')
            ->assertOk()->assertInertia(fn (Assert $page) => $page->where('data.stock_counts.selected.can_decide', false));
        $this->actingAs($counter)->post("/commands/stock-counts/{$session->id}/approve")
            ->assertSessionHasErrors(['count' => __('purchasing.err_second_person')]);
        $this->assertSame(StockCountSession::StatusSubmitted, $session->fresh()->status);
    }

    public function test_owner_publishes_a_policy_draft_they_wrote_with_one_press(): void
    {
        $draft = $this->policyDraft($this->owner);

        $this->actingAs($this->owner)->post("/commands/policies/{$draft}/publish")->assertSessionHasNoErrors();

        $this->assertDatabaseHas('policy_drafts', ['id' => $draft, 'status' => 'published', 'published_by' => $this->owner->id, 'self_approved' => true]);
        $this->assertDoneAlone('policy.published', (string) $draft);
    }

    public function test_staff_cannot_publish_a_policy_draft_they_wrote(): void
    {
        $draft = $this->policyDraft($this->approver);

        $this->actingAs($this->approver)->post("/commands/policies/{$draft}/publish")
            ->assertSessionHasErrors(['policy' => __('portal.err_draft_publisher')]);
        $this->assertDatabaseHas('policy_drafts', ['id' => $draft, 'status' => 'draft']);
    }

    public function test_owner_approves_a_commission_change_that_is_recorded_as_their_own(): void
    {
        $partner = User::factory()->dealer()->create();
        $change = app(PolicyScheduling::class)->propose($this->approver, ['scope' => 'recipient', 'user_id' => $partner->id,
            'rules' => ['direct_referral' => ['percentage' => '12.00', 'funding_mode' => 'company']], 'effective_at' => now()->addHour()->toIso8601String(), 'reason' => 'Special plan for this dealer']);
        DB::table('commission_policy_schedules')->where('id', $change)->update(['created_by' => $this->owner->id]);

        $this->actingAs($this->owner)->postJson("/api/v1/operations/policy-schedules/{$change}/review", ['decision' => 'approved', 'reason' => self::Reason])->assertOk();

        $this->assertDatabaseHas('commission_policy_schedules', ['id' => $change, 'status' => 'approved', 'approved_by' => $this->owner->id]);
        $this->assertDoneAlone('policy_schedule.approved', (string) $change);
    }

    public function test_owner_decides_a_dealer_application_they_sent_in(): void
    {
        $application = $this->dealerApplication($this->owner);

        $this->actingAs($this->owner)->postJson("/api/v1/operations/staff/applications/{$application}/review", ['decision' => 'rejected', 'reason' => 'Applied in the wrong area'])->assertOk();

        $this->assertDatabaseHas('dealer_applications', ['id' => $application, 'status' => 'rejected']);
        $this->assertDoneAlone('dealer_application.rejected', $application);
    }

    public function test_staff_cannot_decide_a_dealer_application_they_sent_in(): void
    {
        $accountManager = User::factory()->staff('account_manager')->create();
        $application = $this->dealerApplication($accountManager);

        $this->actingAs($accountManager)->postJson("/api/v1/operations/staff/applications/{$application}/review", ['decision' => 'rejected', 'reason' => 'Applied in the wrong area'])
            ->assertUnprocessable()->assertJsonPath('errors.application.0', __('portal.err_staff_app_reviewer'));
        $this->assertDatabaseHas('dealer_applications', ['id' => $application, 'status' => 'pending']);
    }

    /** The activity log must still show that one person did both steps. */
    private function assertDoneAlone(string $action, string $subject): void
    {
        $metadata = DB::table('audit_events')->where('action', $action)->where('subject', $subject)->where('actor_id', $this->owner->id)->latest('id')->value('metadata');
        $this->assertNotNull($metadata, "Missing activity log entry for {$action}.");
        $this->assertTrue(json_decode((string) $metadata, true)['self_approved'] ?? false, "{$action} must be marked as done alone.");
    }

    private function staffOnSalary(): User
    {
        $clerk = User::factory()->enterOnly()->create();
        $this->actingAs($this->owner)->postJson('/api/v1/operations/payroll/contracts', ['staff_id' => $clerk->id, 'effective_month' => '2026-10',
            'base_salary' => '1000.00', 'allowance' => '0.00', 'deduction' => '0.00', 'reason' => 'Agreed monthly salary'])->assertOk();

        return $clerk;
    }

    /** @return list<string> */
    private function prepareSalaries(User $preparer): array
    {
        return $this->actingAs($preparer)->postJson('/api/v1/operations/payroll/prepare', ['period_month' => '2026-10'])->assertOk()->json('data.ids');
    }

    /** @return array<string, mixed> */
    private function salaryPayment(): array
    {
        $account = app(PaymentAccounts::class)->save($this->owner, ['label' => 'Company bank', 'channel' => 'Bank', 'holder' => 'Madina',
            'account_number' => 'BANK-'.random_int(100000, 999999), 'collects_payments' => true, 'pays_out' => true, 'is_active' => true, 'reason' => 'Approved company account']);

        return ['payment_account_id' => $account, 'payment_reference' => 'PAY-'.Str::upper(Str::random(8)), 'paid_at' => now()->subMinute()->toDateTimeString(),
            'receipt' => UploadedFile::fake()->create('receipt.pdf', 5, 'application/pdf'), 'reason' => 'Salary paid by bank'];
    }

    /** Puts ৳500 in the company profit fund so a spend has money behind it. */
    private function fundMoney(): void
    {
        $order = (string) Str::uuid();
        DB::table('orders')->insert(['id' => $order, 'number' => 'T-'.Str::random(8), 'user_id' => User::factory()->dealer()->create()->id, 'status' => 'delivered', 'total' => '1000.00', 'paid' => '1000.00',
            'idempotency_key' => (string) Str::uuid(), 'payload_hash' => str_repeat('a', 64), 'commission_policy_snapshot' => '{}', 'created_at' => now(), 'updated_at' => now()]);
        DB::table('commission_allocations')->insert(['order_id' => $order, 'category' => 'company_profit_fund', 'rate_percentage' => '0.00', 'cash_basis' => '1000.00', 'amount' => '500.00',
            'original_amount' => '500.00', 'status' => 'reserved', 'metadata' => '{}', 'created_at' => now(), 'updated_at' => now()]);
    }

    /** @return array<string, mixed> */
    private function spend(): array
    {
        return ['fund' => 'company_profit', 'expense_head_id' => (int) DB::table('fund_expense_heads')->where('name_en', "Owner's drawings")->value('id'), 'amount' => '100.00', 'spent_on' => '2026-11-07',
            'payee' => 'Office rent', 'payment_method' => 'cash', 'request_key' => (string) Str::uuid()];
    }

    private function withdrawal(): string
    {
        $partner = User::factory()->dealer()->create();
        $id = (string) Str::uuid();
        DB::table('wallets')->insert(['user_id' => $partner->id, 'available' => '0.00', 'held' => '200.00', 'commission_held' => '0.00', 'created_at' => now(), 'updated_at' => now()]);
        DB::table('withdrawals')->insert(['id' => $id, 'user_id' => $partner->id, 'amount' => '200.00', 'status' => 'requested', 'channel' => 'Bank', 'destination' => encrypt('TEST-ACCOUNT-1234'),
            'idempotency_key' => (string) Str::uuid(), 'created_at' => now(), 'updated_at' => now()]);

        return $id;
    }

    /** @return array<string, string> */
    private function cashPayout(): array
    {
        return ['method' => 'cash', 'received_by' => 'Rahim', 'paid_at' => now()->subMinute()->toDateTimeString(), 'note' => 'Paid over the counter'];
    }

    private function submittedCount(User $counter): StockCountSession
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

        return $counts->submit($counter, $session);
    }

    private function policyDraft(User $author): int
    {
        return (int) DB::table('policy_drafts')->insertGetId(['name' => 'Dealer plan', 'account_type' => 'dealer', 'basis' => 'purchase_value', 'components' => '[]', 'notes' => 'Draft',
            'created_by' => $author->id, 'created_at' => now(), 'updated_at' => now()]);
    }

    private function dealerApplication(User $sender): string
    {
        $id = (string) Str::uuid();
        DB::table('dealer_applications')->insert(['id' => $id, 'submitted_by' => $sender->id, 'email' => 'applicant@example.test',
            'data' => json_encode(['name' => 'Applicant Dealer', 'email' => 'applicant@example.test', 'phone' => '01700000011', 'role' => 'dealer']),
            'status' => 'pending', 'source' => 'staff', 'created_at' => now(), 'updated_at' => now()]);

        return $id;
    }
}
