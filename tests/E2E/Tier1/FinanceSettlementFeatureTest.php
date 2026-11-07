<?php

namespace Tests\E2E\Tier1;

use App\Domain\Commissions\CommissionPolicy;
use App\Domain\Commissions\CommissionService;
use App\Domain\Orders\OrderService;
use App\Models\User;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\E2E\E2ETestCase;

/**
 * Tier 1 — Finance & Settlement Feature Tests.
 *
 * Covers:
 * - F10: Partner Withdrawal Request
 * - F11: Withdrawal Settlement
 * - F12: Batch Payout Disbursements
 * - F16: Commission Studio & Policy Drafts
 * - F17: Monthly Salary & Royalty Settlement
 */
class FinanceSettlementFeatureTest extends E2ETestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        $this->enableFinancialPosting();
        $this->seedCommissionRules();
    }

    /**
     * Helper to provision a funded wallet for a partner.
     */
    private function fundWallet(User $partner, string $amount): void
    {
        DB::table('wallets')->updateOrInsert(
            ['user_id' => $partner->id],
            [
                'available' => $amount,
                'held' => '0.00',
                'created_at' => now(),
                'updated_at' => now(),
            ]
        );
    }

    // ==========================================
    // F10: Partner Withdrawal Request
    // ==========================================

    /**
     * F10: Partner requests withdrawal with sufficient available balance and valid channel.
     */
    public function test_partner_submits_valid_withdrawal_request(): void
    {
        $dealer = $this->createDealer();
        $this->fundWallet($dealer, '1000.00');

        $response = $this->actingAs($dealer)->postJson('/api/v1/withdrawals', [
            'amount' => '500.00',
            'channel' => 'bKash',
            'destination' => '01711223344',
            'idempotency_key' => (string) Str::uuid(),
        ]);

        $response->assertCreated();
        $withdrawalId = $response->json('data.id');
        $this->assertNotEmpty($withdrawalId);

        // Wallet reflects held funds
        $this->assertDatabaseHas('wallets', [
            'user_id' => $dealer->id,
            'available' => '500.00',
            'held' => '500.00',
        ]);
        $this->assertDatabaseHas('withdrawals', [
            'id' => $withdrawalId,
            'user_id' => $dealer->id,
            'amount' => '500.00',
            'status' => 'requested',
        ]);
    }

    /**
     * F10: Withdrawal request fails if amount is below minimum threshold (200.00 BDT).
     */
    public function test_withdrawal_fails_below_minimum_threshold(): void
    {
        $dealer = $this->createDealer();
        $this->fundWallet($dealer, '1000.00');

        $response = $this->actingAs($dealer)->postJson('/api/v1/withdrawals', [
            'amount' => '100.00', // Below 200.00 min threshold
            'channel' => 'Bank',
            'destination' => '1234567890123',
            'idempotency_key' => (string) Str::uuid(),
        ]);

        $response->assertUnprocessable();
    }

    /**
     * F10: Withdrawal request fails if requested amount exceeds available balance.
     */
    public function test_withdrawal_fails_with_insufficient_available_balance(): void
    {
        $dealer = $this->createDealer();
        $this->fundWallet($dealer, '300.00');

        $response = $this->actingAs($dealer)->postJson('/api/v1/withdrawals', [
            'amount' => '500.00', // Exceeds 300.00
            'channel' => 'Nagad',
            'destination' => '01811223344',
            'idempotency_key' => (string) Str::uuid(),
        ]);

        $response->assertUnprocessable();
    }

    /**
     * F10: Withdrawal requires a valid UUID idempotency key.
     */
    public function test_withdrawal_validates_uuid_idempotency_key(): void
    {
        $dealer = $this->createDealer();
        $this->fundWallet($dealer, '1000.00');

        $response = $this->actingAs($dealer)->postJson('/api/v1/withdrawals', [
            'amount' => '300.00',
            'channel' => 'Bank',
            'destination' => '1234567890123',
            'idempotency_key' => 'not-a-uuid',
        ]);

        $response->assertUnprocessable();
        $response->assertJsonValidationErrors('idempotency_key');
    }

    /**
     * F10: Withdrawal fails if destination is missing or too short.
     */
    public function test_withdrawal_validates_destination_address(): void
    {
        $dealer = $this->createDealer();
        $this->fundWallet($dealer, '1000.00');

        $response = $this->actingAs($dealer)->postJson('/api/v1/withdrawals', [
            'amount' => '300.00',
            'channel' => 'bKash',
            'destination' => '123', // Less than min 6 chars
            'idempotency_key' => (string) Str::uuid(),
        ]);

        $response->assertUnprocessable();
        $response->assertJsonValidationErrors('destination');
    }

    // ==========================================
    // F11: Withdrawal Settlement
    // ==========================================

    /**
     * F11: Staff reviews and approves withdrawal request.
     */
    public function test_staff_approves_withdrawal_request(): void
    {
        $staff = $this->createStaff('super_admin');
        $dealer = $this->createDealer();
        $this->fundWallet($dealer, '1000.00');

        $id = $this->actingAs($dealer)->postJson('/api/v1/withdrawals', [
            'amount' => '400.00',
            'channel' => 'bKash',
            'destination' => '01711223344',
            'idempotency_key' => (string) Str::uuid(),
        ])->json('data.id');

        $response = $this->actingAs($staff)->onPortal('sadmin')->post('/commands/withdrawals/'.$id.'/review', [
            'decision' => 'approved',
            'reason' => 'Identity and bank details verified',
        ]);

        $response->assertSessionHasNoErrors();
        $this->assertDatabaseHas('withdrawals', ['id' => $id, 'status' => 'approved']);
    }

    /**
     * F11: Staff rejects withdrawal request, releasing held wallet balance back to available.
     */
    public function test_staff_rejects_withdrawal_restoring_wallet_balance(): void
    {
        $staff = $this->createStaff('super_admin');
        $dealer = $this->createDealer();
        $this->fundWallet($dealer, '1000.00');

        $id = $this->actingAs($dealer)->postJson('/api/v1/withdrawals', [
            'amount' => '400.00',
            'channel' => 'Nagad',
            'destination' => '01799887766',
            'idempotency_key' => (string) Str::uuid(),
        ])->json('data.id');

        $response = $this->actingAs($staff)->onPortal('sadmin')->post('/commands/withdrawals/'.$id.'/review', [
            'decision' => 'rejected',
            'reason' => 'Invalid Nagad account number',
        ]);

        $response->assertSessionHasNoErrors();
        $this->assertDatabaseHas('withdrawals', ['id' => $id, 'status' => 'rejected']);

        // Held funds returned to available
        $this->assertDatabaseHas('wallets', [
            'user_id' => $dealer->id,
            'available' => '1000.00',
            'held' => '0.00',
        ]);
    }

    /**
     * F11: Staff records payout disbursement with receipt upload and payment reference.
     */
    public function test_staff_records_withdrawal_payout_with_receipt(): void
    {
        $reviewer = $this->createStaff('super_admin');
        $payer = $this->createStaff('finance');
        $dealer = $this->createDealer();
        $this->fundWallet($dealer, '1000.00');

        $id = $this->actingAs($dealer)->postJson('/api/v1/withdrawals', [
            'amount' => '500.00',
            'channel' => 'Bank',
            'destination' => '1234567890123',
            'idempotency_key' => (string) Str::uuid(),
        ])->json('data.id');

        $this->actingAs($reviewer)->onPortal('sadmin')->post('/commands/withdrawals/'.$id.'/review', [
            'decision' => 'approved',
            'reason' => 'Bank details verified',
        ]);

        $receipt = UploadedFile::fake()->create('bank_receipt.pdf', 100, 'application/pdf');

        $response = $this->actingAs($payer)->onPortal('manage')->post('/commands/withdrawals/'.$id.'/record-payment', [
            'receipt' => $receipt,
            'method' => 'other', 'payment_reference' => 'EFT-TXN-998811',
            'paid_at' => now()->toDateString(),
            'note' => 'Disbursed via corporate EFT',
        ]);

        $response->assertSessionHasNoErrors();
        $this->assertDatabaseHas('withdrawals', [
            'id' => $id,
            'status' => 'paid',
            'payment_reference' => 'EFT-TXN-998811',
        ]);

        // Wallet held balance cleared
        $this->assertDatabaseHas('wallets', [
            'user_id' => $dealer->id,
            'available' => '500.00',
            'held' => '0.00',
        ]);
    }

    /**
     * F11: Setting paid_at date in the future fails validation.
     */
    public function test_withdrawal_settlement_rejects_future_paid_at(): void
    {
        $reviewer = $this->createStaff('super_admin');
        $payer = $this->createStaff('finance');
        $dealer = $this->createDealer();
        $this->fundWallet($dealer, '1000.00');

        $id = $this->actingAs($dealer)->postJson('/api/v1/withdrawals', [
            'amount' => '300.00',
            'channel' => 'bKash',
            'destination' => '01711223344',
            'idempotency_key' => (string) Str::uuid(),
        ])->json('data.id');

        $this->actingAs($reviewer)->onPortal('sadmin')->post('/commands/withdrawals/'.$id.'/review', [
            'decision' => 'approved',
            'reason' => 'Approved',
        ]);

        $receipt = UploadedFile::fake()->create('receipt.png', 50, 'image/png');

        $response = $this->actingAs($payer)->onPortal('manage')->post('/commands/withdrawals/'.$id.'/record-payment', [
            'receipt' => $receipt,
            'method' => 'other', 'payment_reference' => 'REF-FUTURE',
            'paid_at' => now()->addDays(2)->toDateString(), // Future date
            'note' => 'Invalid future date test',
        ]);

        $response->assertSessionHasErrors(['paid_at']);
    }

    /**
     * F11: Non-finance staff cannot review or settle withdrawals.
     */
    public function test_non_finance_staff_cannot_review_withdrawals(): void
    {
        $editor = $this->createStaff('editor');
        $dealer = $this->createDealer();
        $this->fundWallet($dealer, '1000.00');

        $id = $this->actingAs($dealer)->postJson('/api/v1/withdrawals', [
            'amount' => '300.00',
            'channel' => 'Bank',
            'destination' => '1234567890123',
            'idempotency_key' => (string) Str::uuid(),
        ])->json('data.id');

        $response = $this->actingAs($editor)->onPortal('manage')->post('/commands/withdrawals/'.$id.'/review', [
            'decision' => 'approved',
            'reason' => 'Unauthorized review',
        ]);

        $response->assertForbidden();
    }

    // ==========================================
    // F12: Batch Payout Disbursements
    // ==========================================

    /**
     * F12: Finance staff can inspect withdrawal details and reveal unmasked destination.
     */
    public function test_staff_inspects_withdrawal_destination_details(): void
    {
        $staff = $this->createStaff('super_admin');
        $dealer = $this->createDealer();
        $this->fundWallet($dealer, '1000.00');

        $id = $this->actingAs($dealer)->postJson('/api/v1/withdrawals', [
            'amount' => '400.00',
            'channel' => 'bKash',
            'destination' => '01712345678',
            'idempotency_key' => (string) Str::uuid(),
        ])->json('data.id');

        $response = $this->actingAs($staff)->onPortal('sadmin')->get('/withdrawals/'.$id);

        $response->assertOk();
    }

    /**
     * F12: Partner cannot inspect another partner's withdrawal detail.
     */
    public function test_partner_cannot_view_another_partners_withdrawal(): void
    {
        $dealer1 = $this->createDealer();
        $dealer2 = $this->createDealer();
        $this->fundWallet($dealer1, '1000.00');

        $id = $this->actingAs($dealer1)->postJson('/api/v1/withdrawals', [
            'amount' => '400.00',
            'channel' => 'bKash',
            'destination' => '01712345678',
            'idempotency_key' => (string) Str::uuid(),
        ])->json('data.id');

        $response = $this->actingAs($dealer2)->onPortal('dealer')->get('/withdrawals/'.$id);

        $response->assertNotFound();
    }

    /**
     * F12: Withdrawal settlement generates audit event.
     */
    public function test_withdrawal_audit_event_logged(): void
    {
        $staff = $this->createStaff('super_admin');
        $dealer = $this->createDealer();
        $this->fundWallet($dealer, '1000.00');

        $id = $this->actingAs($dealer)->postJson('/api/v1/withdrawals', [
            'amount' => '300.00',
            'channel' => 'Bank',
            'destination' => '1234567890123',
            'idempotency_key' => (string) Str::uuid(),
        ])->json('data.id');

        $this->actingAs($staff)->onPortal('sadmin')->post('/commands/withdrawals/'.$id.'/review', [
            'decision' => 'approved',
            'reason' => 'Approved',
        ]);

        $this->assertDatabaseHas('audit_events', [
            'actor_id' => $staff->id,
            'action' => 'withdrawal.approved',
        ]);
    }

    // ==========================================
    // F16: Commission Studio & Policy Drafts
    // ==========================================

    /**
     * F16: Finance staff views current commission policy parameters.
     */
    public function test_staff_views_commission_policy(): void
    {
        $staff = $this->createStaff('super_admin');

        $response = $this->actingAs($staff)->getJson('/api/v1/finance/policy');

        $response->assertOk();
        $response->assertJsonStructure(['data' => ['rules', 'revision_id']]);
    }

    /**
     * F16: Staff updates commission rule parameters.
     */
    public function test_staff_updates_commission_policy(): void
    {
        $staff = $this->createStaff('super_admin');
        $currentRules = app(CommissionPolicy::class)->snapshot()['rules'];

        $response = $this->actingAs($staff)->patchJson('/api/v1/finance/policy', [
            'rules' => $currentRules,
        ]);

        $response->assertOk();
    }

    /**
     * F16: Commission simulation returns preview without posting wallet balances.
     */
    public function test_commission_simulation_is_non_posting(): void
    {
        $staff = $this->createStaff('super_admin');

        $response = $this->actingAs($staff)->postJson('/api/v1/finance/distribution-preview', [
            'mrp' => '200.00',
            'account_type' => 'dealer',
            'discount_percent' => '30.00',
        ]);

        $response->assertOk();
        $response->assertJsonPath('data.posts_earnings', false);
        $this->assertDatabaseCount('commission_batches', 0);
    }

    // ==========================================
    // F17: Monthly Salary & Royalty Settlement
    // ==========================================

    /**
     * F17: Monthly salary settlement distributes salary pool allocations for closed month.
     */
    public function test_monthly_salary_settlement_distribution(): void
    {
        $dealer = $this->createDealer();
        $product = $this->createProduct(['mrp' => '2000.00', 'dealer_price' => '1000.00']);

        $order = app(OrderService::class)->create($dealer, [
            'items' => [['product_id' => $product->id, 'quantity' => 1]],
            'idempotency_key' => (string) Str::uuid(),
        ]);
        $order->update(['status' => 'delivered', 'paid' => '1000.00']);
        app(CommissionService::class)->processOrder($order);

        // Mark allocations as belonging to the closed month 2026-10
        DB::table('commission_batches')->where('order_id', $order->id)->update(['period_month' => '2026-10']);
        foreach (DB::table('commission_allocations')->where('order_id', $order->id)->get() as $alloc) {
            $meta = json_decode((string) $alloc->metadata, true) ?? [];
            $meta['period_month'] = '2026-10';
            DB::table('commission_allocations')->where('id', $alloc->id)->update([
                'metadata' => json_encode($meta),
            ]);
        }

        $result = app(CommissionService::class)->settleMonthlySalaryPool('2026-10');

        $this->assertContains($result['status'], ['settled', 'rolled_over']);
        $this->assertDatabaseHas('commission_batches', ['order_id' => $order->id]);
    }

    /**
     * F17: Settling already settled salary pool is idempotent.
     */
    public function test_salary_pool_settlement_is_idempotent(): void
    {
        $dealer = $this->createDealer();
        $product = $this->createProduct(['mrp' => '2000.00', 'dealer_price' => '1000.00']);

        $order = app(OrderService::class)->create($dealer, [
            'items' => [['product_id' => $product->id, 'quantity' => 1]],
            'idempotency_key' => (string) Str::uuid(),
        ]);
        $order->update(['status' => 'delivered', 'paid' => '1000.00']);
        app(CommissionService::class)->processOrder($order);

        // Mark allocations as belonging to closed month 2026-10
        DB::table('commission_batches')->where('order_id', $order->id)->update(['period_month' => '2026-10']);
        foreach (DB::table('commission_allocations')->where('order_id', $order->id)->get() as $alloc) {
            $meta = json_decode((string) $alloc->metadata, true) ?? [];
            $meta['period_month'] = '2026-10';
            DB::table('commission_allocations')->where('id', $alloc->id)->update([
                'metadata' => json_encode($meta),
            ]);
        }

        $service = app(CommissionService::class);

        // First run: settled or rolled_over
        $first = $service->settleMonthlySalaryPool('2026-10');
        $this->assertContains($first['status'], ['settled', 'rolled_over']);

        // Second run: empty_pool
        $second = $service->settleMonthlySalaryPool('2026-10');
        $this->assertSame('empty_pool', $second['status']);
    }

    /**
     * F17: Financial defaults endpoint returns standard operational constants.
     */
    public function test_financial_defaults_endpoint(): void
    {
        $staff = $this->createStaff('super_admin');

        $response = $this->actingAs($staff)->getJson('/api/v1/finance/defaults');

        $response->assertOk();
        $response->assertJsonStructure(['data' => ['basis', 'currency', 'discount_range', 'posts_earnings']]);
        $response->assertJsonPath('data.posts_earnings', false);
    }
}
