<?php

namespace Tests\Feature;

use App\Domain\Commissions\CommissionPosting;
use App\Domain\Commissions\CommissionReversal;
use App\Domain\Finance\FinancialReadiness;
use App\Domain\Finance\PaymentAccounts;
use App\Domain\Finance\PayrollService;
use App\Domain\Orders\OrderService;
use App\Domain\Staff\TerritoryAccess;
use App\Models\AreaDealerAssignment;
use App\Models\CommissionAllocation;
use App\Models\Order;
use App\Models\Product;
use App\Models\User;
use Database\Seeders\CommissionRuleSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Tests\TestCase;

class OperationsCompletionTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->withoutVite();
        $this->travelTo(now()->setDate(2026, 10, 17)->startOfDay());
        $this->seed(CommissionRuleSeeder::class);
    }

    private function account(User $actor): int
    {
        return app(PaymentAccounts::class)->save($actor, ['label' => 'Company bank', 'channel' => 'Bank', 'holder' => 'Madina',
            'account_number' => 'BANK-123456', 'collects_payments' => true, 'pays_out' => true, 'is_active' => true, 'reason' => 'Approved company account']);
    }

    private function postedOrder(User $buyer, string $total = '100.00'): Order
    {
        $product = Product::factory()->create(['mrp' => '200.00', 'dealer_price' => '100.00', 'sub_dealer_price' => '120.00']);
        $order = app(OrderService::class)->create($buyer, ['items' => [['product_id' => $product->id, 'quantity' => 1]], 'idempotency_key' => (string) Str::uuid()]);
        $order->update(['status' => 'delivered', 'paid' => $total]);
        app(CommissionPosting::class)->process($order);

        return $order;
    }

    public function test_sr_can_only_order_for_assigned_partners_and_cannot_access_finance(): void
    {
        $admin = User::factory()->create(['role' => 'super_admin']);
        $sr = User::factory()->create(['role' => 'sr']);
        $inside = User::factory()->create(['division' => 'Rangpur', 'district' => 'Rangpur']);
        $outside = User::factory()->create(['division' => 'Dhaka', 'district' => 'Dhaka']);
        $product = Product::factory()->create();
        $this->actingAs($admin)->postJson('/api/v1/operations/staff/territories', ['staff_id' => $sr->id, 'level' => 'district', 'division' => 'Rangpur', 'district' => 'Rangpur'])->assertOk();
        $payload = ['user_id' => $inside->id, 'items' => [['product_id' => $product->id, 'quantity' => 2]], 'idempotency_key' => (string) Str::uuid()];
        $response = $this->actingAs($sr)->postJson('/api/v1/operations/staff/orders', $payload)->assertOk();
        $this->assertDatabaseHas('orders', ['id' => $response->json('data.id'), 'user_id' => $inside->id, 'submitted_by' => $sr->id]);
        $this->postJson('/api/v1/operations/staff/orders', $payload)->assertOk()->assertJsonPath('data.id', $response->json('data.id'));
        $this->postJson('/api/v1/operations/staff/orders', [...$payload, 'user_id' => $outside->id])->assertNotFound();
        $this->getJson('/api/v1/operations/views/field-sales')->assertOk()->assertJsonCount(1, 'data.partners');
        $this->getJson('/api/v1/operations/views/payment-accounts')->assertForbidden();
        $this->postJson('/api/v1/operations/payroll/prepare', ['period_month' => '2026-09'])->assertForbidden();
    }

    public function test_sr_onboarding_is_reviewed_and_cannot_escalate_roles_or_cross_territories(): void
    {
        $admin = User::factory()->create(['role' => 'super_admin']);
        $sr = User::factory()->create(['role' => 'sr']);
        app(TerritoryAccess::class)->assign($admin, ['staff_id' => $sr->id, 'level' => 'division', 'division' => 'Rangpur']);
        $payload = ['name' => 'New dealer', 'email' => 'new-partner@example.test', 'phone' => '01711111111', 'role' => 'dealer', 'division' => 'Rangpur', 'district' => 'Kurigram'];
        $id = $this->actingAs($sr)->postJson('/api/v1/operations/staff/applications', $payload)->assertOk()->json('data.id');
        $this->assertDatabaseMissing('users', ['email' => $payload['email']]);
        $this->postJson('/api/v1/operations/staff/applications', [...$payload, 'email' => 'other@example.test', 'division' => 'Dhaka'])->assertForbidden();
        $this->postJson('/api/v1/operations/staff/applications', [...$payload, 'role' => 'finance'])->assertUnprocessable();
        $this->actingAs($admin)->postJson('/api/v1/operations/staff/applications/'.$id.'/review', ['decision' => 'approved', 'reason' => 'Identity and territory reviewed'])->assertOk();
        $this->assertDatabaseHas('users', ['email' => $payload['email'], 'role' => 'dealer']);
        $this->assertDatabaseHas('referral_tree_paths', ['ancestor_id' => User::where('email', $payload['email'])->value('id'), 'depth' => 0]);
    }

    public function test_company_accounts_are_encrypted_versioned_and_captured_on_claims(): void
    {
        $admin = User::factory()->create(['role' => 'finance']);
        $id = $this->account($admin);
        $this->assertNotSame('BANK-123456', DB::table('payment_accounts')->value('account_number'));
        $buyer = User::factory()->create();
        $product = Product::factory()->create();
        $payload = ['items' => [['product_id' => $product->id, 'quantity' => 1]], 'idempotency_key' => (string) Str::uuid(), 'reference' => 'REAL-REFERENCE', 'amount' => '10', 'channel' => 'Bank'];
        $this->actingAs($buyer)->postJson('/api/v1/orders', $payload)->assertUnprocessable()->assertJsonValidationErrors('payment_account_id');
        $this->postJson('/api/v1/orders', [...$payload, 'payment_account_id' => $id])->assertCreated();
        $claim = DB::table('payment_claims')->first();
        $this->assertSame($id, $claim->payment_account_id);
        $this->assertSame('BANK-123456', decrypt($claim->account_snapshot)['account_number']);
        $this->actingAs($admin)->patchJson('/api/v1/operations/payment-accounts/'.$id, ['label' => 'Changed', 'channel' => 'Bank', 'holder' => 'Madina', 'collects_payments' => true, 'pays_out' => true, 'is_active' => true, 'version' => 9, 'reason' => 'Stale concurrent edit'])->assertUnprocessable();
        $this->patchJson('/api/v1/operations/payment-accounts/'.$id, ['label' => 'Changed', 'channel' => 'Bank', 'holder' => 'Madina', 'account_number' => 'BANK-NEW', 'collects_payments' => true, 'pays_out' => true, 'is_active' => true, 'version' => 1, 'reason' => 'Approved replacement account'])->assertOk();
        $this->getJson('/api/v1/operations/views/payment-accounts?account_history='.$id)->assertOk()->assertJsonCount(2, 'data.accountHistory')->assertJsonPath('data.accountHistory.1.account_number', 'BANK-123456');
    }

    public function test_payroll_is_idempotent_separates_preparer_reviewer_payer_and_requires_proof(): void
    {
        Storage::fake('local');
        $preparer = User::factory()->create(['role' => 'finance']);
        $reviewer = User::factory()->create(['role' => 'finance']);
        $staff = User::factory()->create(['role' => 'sr']);
        $account = $this->account($preparer);
        $this->actingAs($preparer)->postJson('/api/v1/operations/payroll/contracts', ['staff_id' => $staff->id, 'effective_month' => '2026-09', 'base_salary' => '20000', 'allowance' => '1000', 'deduction' => '500', 'reason' => 'Approved employment terms'])->assertOk();
        $id = $this->postJson('/api/v1/operations/payroll/prepare', ['period_month' => '2026-09'])->assertOk()->json('data.ids.0');
        $this->postJson('/api/v1/operations/payroll/prepare', ['period_month' => '2026-09'])->assertOk()->assertJsonPath('data.ids.0', $id);
        $this->postJson('/api/v1/operations/payroll/prepare', ['period_month' => '2026-10'])->assertUnprocessable();
        $this->postJson('/api/v1/operations/payroll/'.$id.'/review', ['decision' => 'approved', 'reason' => 'Salary reviewed'])->assertUnprocessable();
        config(['madina.financial_activation' => true]);
        $this->postJson('/api/v1/operations/payroll/'.$id.'/review', ['decision' => 'approved', 'reason' => 'Salary reviewed'])->assertUnprocessable();
        $this->actingAs($reviewer)->postJson('/api/v1/operations/payroll/'.$id.'/review', ['decision' => 'approved', 'reason' => 'Salary reviewed independently'])->assertOk();
        $payment = ['payment_account_id' => $account, 'payment_reference' => 'SALARY-SEP', 'paid_at' => now()->subMinute()->timezone('Asia/Dhaka')->format('Y-m-d\TH:i'), 'reason' => 'Actual payment confirmed', 'receipt' => UploadedFile::fake()->create('proof.pdf', 5, 'application/pdf')];
        $this->postJson('/api/v1/operations/payroll/'.$id.'/pay', $payment)->assertUnprocessable();
        $this->actingAs($preparer)->postJson('/api/v1/operations/payroll/'.$id.'/pay', $payment)->assertOk();
        $this->assertDatabaseHas('payroll_entries', ['id' => $id, 'status' => 'paid', 'net' => '20500.00']);
        $snapshot = json_decode(DB::table('audit_events')->where('action', 'manual_payment.account_captured')->value('metadata'), true);
        $this->assertSame(1, $snapshot['payment_account_version']);
        $this->assertSame('BANK-123456', decrypt($snapshot['account_snapshot'])['account_number']);
        $this->postJson('/api/v1/operations/payroll/'.$id.'/pay', $payment)->assertUnprocessable();
        $this->actingAs($staff)->get('/commands/operations/receipts/payroll/'.$id)->assertOk();
        $this->actingAs(User::factory()->create(['role' => 'sr']))->get('/commands/operations/receipts/payroll/'.$id)->assertNotFound();
        $this->assertEquals(DB::table('journal_lines')->sum('debit'), DB::table('journal_lines')->sum('credit'));
    }

    public function test_tour_reserve_is_owned_cannot_be_overdrawn_and_reverses_after_refund(): void
    {
        config(['madina.financial_activation' => true]);
        $admin = User::factory()->create(['role' => 'finance']);
        $reviewer = User::factory()->create(['role' => 'finance']);
        $buyer = User::factory()->create(['role' => 'sub_dealer']);
        $other = User::factory()->create(['role' => 'sub_dealer']);
        $order = $this->postedOrder($buyer, '120.00');
        $payload = ['program' => 'tour', 'user_id' => $buyer->id, 'amount' => '3.60', 'request_key' => (string) Str::uuid(), 'reason' => 'Approved sub-dealer tour benefit'];
        $id = $this->actingAs($admin)->postJson('/api/v1/operations/reserves', $payload)->assertOk()->json('data.id');
        $this->postJson('/api/v1/operations/reserves', $payload)->assertOk()->assertJsonPath('data.id', $id);
        $this->postJson('/api/v1/operations/reserves/'.$id.'/review', ['decision' => 'approved', 'reason' => 'Approve tour benefit'])->assertUnprocessable();
        $wrong = $this->postJson('/api/v1/operations/reserves', [...$payload, 'user_id' => $other->id, 'request_key' => (string) Str::uuid()])->assertOk()->json('data.id');
        $this->actingAs($reviewer)->postJson('/api/v1/operations/reserves/'.$wrong.'/review', ['decision' => 'approved', 'reason' => 'Approve own funded tour'])->assertUnprocessable();
        $this->postJson('/api/v1/operations/reserves/'.$id.'/review', ['decision' => 'approved', 'reason' => 'Approve own funded tour'])->assertOk();
        $this->assertDatabaseHas('commission_settlement_payouts', ['kind' => 'award_'.$id, 'amount' => '3.60']);
        app(CommissionReversal::class)->refund($order, 6000, 'Half order returned', 'TOUR-REFUND');
        $this->assertDatabaseHas('commission_settlement_payouts', ['kind' => 'award_'.$id, 'reversed_amount' => '1.80']);
        $this->assertDatabaseHas('reserve_disbursements', ['id' => $id, 'status' => 'credited']);
    }

    public function test_reward_after_partial_refund_only_claws_back_subsequent_refund_fraction(): void
    {
        config(['madina.financial_activation' => true]);
        $buyer = User::factory()->create(['role' => 'sub_dealer']);
        $order = $this->postedOrder($buyer, '120.00');
        app(CommissionReversal::class)->refund($order, 6000, 'First half returned', 'FIRST-HALF');
        $admin = User::factory()->create(['role' => 'finance']);
        $id = $this->actingAs($admin)->postJson('/api/v1/operations/reserves', ['program' => 'tour', 'user_id' => $buyer->id, 'amount' => '1.80', 'request_key' => (string) Str::uuid(), 'reason' => 'Reward remaining tour reserve'])->assertOk()->json('data.id');
        $this->actingAs(User::factory()->create(['role' => 'finance']))->postJson('/api/v1/operations/reserves/'.$id.'/review', ['decision' => 'approved', 'reason' => 'Reviewed remaining funds'])->assertOk();
        app(CommissionReversal::class)->refund($order, 3000, 'Next quarter returned', 'NEXT-QUARTER');
        $this->assertDatabaseHas('commission_settlement_payouts', ['kind' => 'award_'.$id, 'refunded_basis' => '60.00', 'reversed_amount' => '0.90']);
    }

    public function test_inactive_earning_is_released_once_only_when_recipient_active(): void
    {
        config(['madina.financial_activation' => true]);
        $sponsor = User::factory()->create(['status' => 'suspended']);
        $buyer = User::factory()->create(['sponsor_id' => $sponsor->id]);
        $this->postedOrder($buyer);
        $hold = CommissionAllocation::where('user_id', $sponsor->id)->where('category', 'direct_referral')->firstOrFail();
        $admin = User::factory()->create(['role' => 'finance']);
        $url = '/api/v1/operations/holds/'.$hold->id.'/release';
        $this->actingAs($admin)->postJson($url, ['reason' => 'Recipient status reviewed'])->assertUnprocessable();
        $sponsor->update(['status' => 'active']);
        $this->postJson($url, ['reason' => 'Recipient status reviewed'])->assertOk();
        $this->assertDatabaseHas('wallets', ['user_id' => $sponsor->id, 'available' => '10.00']);
        $this->postJson($url, ['reason' => 'Repeat release attempt'])->assertUnprocessable();
    }

    public function test_opening_balances_require_real_matching_evidence_and_independent_review(): void
    {
        Storage::fake('local');
        $admin = User::factory()->create(['role' => 'finance']);
        $reviewer = User::factory()->create(['role' => 'finance']);
        $payload = ['wallet_available' => '0', 'wallet_held' => '0', 'order_paid' => '0', 'withdrawal_paid' => '0',
            'source_reference' => 'Independent opening statement', 'evidence' => UploadedFile::fake()->create('opening.pdf', 5, 'application/pdf')];
        $this->actingAs($admin)->postJson('/api/v1/operations/opening-balances', [...$payload, 'wallet_available' => '1'])->assertUnprocessable();
        $id = $this->postJson('/api/v1/operations/opening-balances', $payload)->assertOk()->json('data.id');
        $this->postJson('/api/v1/operations/opening-balances/'.$id.'/verify')->assertUnprocessable();
        $this->actingAs($reviewer)->postJson('/api/v1/operations/opening-balances/'.$id.'/verify')->assertOk();
        $this->assertTrue(app(FinancialReadiness::class)->inspect()['opening_verified']);
        $this->assertFalse((bool) config('madina.financial_activation'));
        DB::table('wallets')->insert(['user_id' => $admin->id, 'available' => '20.00', 'created_at' => now(), 'updated_at' => now()]);
        $this->assertFalse(app(FinancialReadiness::class)->inspect()['internally_reconciled']);
    }

    public function test_sr_targets_use_captured_geography_and_net_sales_in_closed_month_payroll(): void
    {
        config(['madina.financial_activation' => true]);
        $admin = User::factory()->create(['role' => 'super_admin']);
        $sr = User::factory()->create(['role' => 'sr']);
        $buyer = User::factory()->create(['division' => 'RANGPUR', 'district' => 'Kurigram']);
        $territory = app(TerritoryAccess::class)->assign($admin, ['staff_id' => $sr->id, 'level' => 'division', 'division' => 'Rangpur']);
        $this->actingAs($admin)->postJson('/api/v1/operations/staff/targets', ['staff_id' => $sr->id, 'period_month' => '2026-10', 'target' => '50', 'bonus' => '1000'])->assertOk();
        $order = $this->postedOrder($buyer);
        app(CommissionReversal::class)->refund($order, 5000, 'Half of sale returned', 'TARGET-REFUND');
        $buyer->update(['division' => 'Dhaka']);
        $this->postJson('/api/v1/operations/staff/territories/'.$territory.'/remove', ['reason' => 'Sales representative reassigned'])->assertOk();
        app(PayrollService::class)->contract($admin, ['staff_id' => $sr->id, 'effective_month' => '2026-10', 'base_salary' => '20000', 'allowance' => '0', 'deduction' => '0', 'reason' => 'Effective employment contract']);
        $this->travel(20)->days();
        $id = $this->postJson('/api/v1/operations/payroll/prepare', ['period_month' => '2026-10'])->assertOk()->json('data.ids.0');
        $this->assertDatabaseHas('payroll_entries', ['id' => $id, 'net' => '21000.00']);
        $snapshot = json_decode(DB::table('payroll_entries')->where('id', $id)->value('snapshot'), true);
        $this->assertSame('50.00', $snapshot['qualifying_volume']);
        $this->postJson('/api/v1/operations/staff/targets', ['staff_id' => $sr->id, 'period_month' => '2026-10', 'target' => '1', 'bonus' => '90000', 'version' => 1])->assertUnprocessable();
    }

    public function test_area_hold_uses_original_period_and_quota_despite_later_assignment_changes(): void
    {
        config(['madina.financial_activation' => true]);
        $admin = User::factory()->create(['role' => 'finance']);
        $area = User::factory()->create(['division' => 'Rangpur']);
        $buyer = User::factory()->create(['division' => 'Rangpur']);
        $assignment = AreaDealerAssignment::create(['user_id' => $area->id, 'level' => 'division', 'division' => 'Rangpur', 'zone_name' => 'Rangpur', 'monthly_quota' => '100', 'is_active' => true]);
        $order = $this->postedOrder($buyer);
        $hold = CommissionAllocation::where('order_id', $order->id)->where('category', 'area_division')->firstOrFail();
        $url = '/api/v1/operations/holds/'.$hold->id.'/release';
        $this->actingAs($admin)->postJson($url, ['reason' => 'Review monthly area quota'])->assertUnprocessable();
        $assignment->update(['monthly_quota' => '1000']);
        $this->postedOrder($area);
        $this->postJson($url, ['reason' => 'Original monthly quota achieved'])->assertOk();
        $this->assertSame('available', $hold->fresh()->status);
    }

    public function test_marketing_expenses_preserve_refund_clawbacks_and_private_recovery_proofs(): void
    {
        Storage::fake('local');
        config(['madina.financial_activation' => true]);
        $preparer = User::factory()->create(['role' => 'finance']);
        $reviewer = User::factory()->create(['role' => 'finance']);
        $account = $this->account($preparer);
        $order = $this->postedOrder(User::factory()->create());
        $id = $this->actingAs($preparer)->postJson('/api/v1/operations/reserves', ['program' => 'marketing', 'payee_name' => 'Approved advertising supplier', 'amount' => '2',
            'request_key' => (string) Str::uuid(), 'reason' => 'Approved marketing invoice'])->assertOk()->json('data.id');
        $this->actingAs($reviewer)->postJson('/api/v1/operations/reserves/'.$id.'/review', ['decision' => 'approved', 'reason' => 'Marketing invoice reviewed'])->assertOk();
        $proof = ['payment_account_id' => $account, 'payment_reference' => 'EXPENSE-1', 'paid_at' => now()->subMinute()->toIso8601String(), 'reason' => 'Invoice payment confirmed', 'receipt' => UploadedFile::fake()->create('expense.pdf', 5, 'application/pdf')];
        $this->actingAs($preparer)->postJson('/api/v1/operations/reserves/'.$id.'/pay', $proof)->assertOk();
        app(CommissionReversal::class)->refund($order, 5000, 'Half order return after expense', 'EXPENSE-REFUND');
        $debt = DB::table('reserve_expense_clawbacks')->first();
        $this->assertSame('1.00', $debt->amount);
        $url = '/api/v1/operations/reserves/clawbacks/'.$debt->id.'/recover';
        $this->postJson($url, [...$proof, 'payment_reference' => 'RECOVERY-1', 'amount' => '2'])->assertUnprocessable();
        $this->postJson($url, [...$proof, 'payment_reference' => 'RECOVERY-1', 'amount' => '1'])->assertOk();
        $this->postJson($url, [...$proof, 'payment_reference' => 'RECOVERY-1', 'amount' => '1'])->assertUnprocessable();
        $this->assertDatabaseHas('reserve_expense_clawbacks', ['id' => $debt->id, 'recovered' => '1.00']);
        $recovery = DB::table('reserve_recoveries')->first();
        $this->get('/commands/operations/receipts/recovery/'.$recovery->id)->assertOk();
        $this->assertEquals(DB::table('journal_lines')->sum('debit'), DB::table('journal_lines')->sum('credit'));
    }

    public function test_salary_corrections_append_contract_revisions_and_preserve_rejected_payroll(): void
    {
        config(['madina.financial_activation' => true]);
        $admin = User::factory()->create(['role' => 'finance']);
        $reviewer = User::factory()->create(['role' => 'finance']);
        $staff = User::factory()->create(['role' => 'sr']);
        $contract = ['staff_id' => $staff->id, 'effective_month' => '2026-09', 'base_salary' => '10000', 'allowance' => '0', 'deduction' => '0', 'reason' => 'Initial salary agreement'];
        $first = $this->actingAs($admin)->postJson('/api/v1/operations/payroll/contracts', $contract)->assertOk()->json('data.id');
        $this->postJson('/api/v1/operations/payroll/contracts', $contract)->assertOk()->assertJsonPath('data.id', $first);
        $oldPayroll = $this->postJson('/api/v1/operations/payroll/prepare', ['period_month' => '2026-09'])->assertOk()->json('data.ids.0');
        $this->actingAs($reviewer)->postJson('/api/v1/operations/payroll/'.$oldPayroll.'/review', ['decision' => 'rejected', 'reason' => 'Salary agreement needs correction'])->assertOk();
        $second = $this->actingAs($admin)->postJson('/api/v1/operations/payroll/contracts', [...$contract, 'base_salary' => '12000', 'reason' => 'Corrected signed salary agreement'])->assertOk()->json('data.id');
        $newPayroll = $this->postJson('/api/v1/operations/payroll/prepare', ['period_month' => '2026-09'])->assertOk()->json('data.ids.0');
        $this->assertNotSame($oldPayroll, $newPayroll);
        $this->assertDatabaseHas('staff_contracts', ['id' => $first, 'revision' => 1, 'base_salary' => '10000.00']);
        $this->assertDatabaseHas('staff_contracts', ['id' => $second, 'revision' => 2, 'base_salary' => '12000.00']);
        $this->assertDatabaseHas('payroll_entries', ['id' => $oldPayroll, 'status' => 'rejected', 'net' => '10000.00']);
        $this->assertDatabaseHas('payroll_entries', ['id' => $newPayroll, 'status' => 'draft', 'net' => '12000.00']);
    }

    public function test_production_posting_requires_opening_verification_even_when_configuration_is_enabled(): void
    {
        config(['madina.financial_activation' => true]);
        $this->app->instance('env', 'production');
        $admin = User::factory()->create(['role' => 'finance']);
        $this->actingAs($admin)->withSession(['_token' => 'production-opening-test'])->postJson('/api/v1/operations/holds/1/release', ['_token' => 'production-opening-test', 'reason' => 'Attempt posting before opening review'])->assertUnprocessable()->assertJsonValidationErrors('policy');
        $this->assertDatabaseCount('journal_entries', 0);
    }
}
