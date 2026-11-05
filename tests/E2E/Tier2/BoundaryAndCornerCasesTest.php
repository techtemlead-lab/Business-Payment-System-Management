<?php

namespace Tests\E2E\Tier2;

use App\Domain\Commissions\CommissionWallet;
use App\Domain\Finance\FinanceSettings;
use App\Models\BranchProductStock;
use App\Models\Order;
use App\Models\Product;
use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Tests\E2E\E2ETestCase;

/**
 * Tier 2 — Boundary, Negative & Corner Cases E2E Test Suite.
 *
 * Covers:
 * - Zero and negative amounts (order quantities, payment claims, withdrawals)
 * - Exceeding bounds (stock availability, outstanding balance, credit authorization)
 * - Unauthorized / non-wholesaler roles attempting restricted actions
 * - Invalid lifecycle status transitions
 * - Idempotency conflicts (409 Conflict upon payload divergence)
 * - Financial safety gates (MADINA_FINANCIAL_ACTIVATION)
 * - TOTP / 2FA edge cases (invalid and expired codes)
 */
class BoundaryAndCornerCasesTest extends E2ETestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        $this->enableFinancialPosting();
        $this->seedCommissionRules();
    }

    // ==========================================
    // 1. Order Creation Boundary & Negative Cases
    // ==========================================

    public function test_order_creation_rejects_zero_quantity(): void
    {
        $dealer = $this->createDealer();
        $product = $this->createProduct(['stock' => 50]);

        $response = $this->actingAs($dealer)->postJson('/api/v1/orders', [
            'idempotency_key' => (string) Str::uuid(),
            'items' => [
                ['product_id' => $product->id, 'quantity' => 0],
            ],
        ]);

        $response->assertStatus(422);
        $response->assertJsonValidationErrors(['items.0.quantity']);
    }

    public function test_order_creation_rejects_negative_quantity(): void
    {
        $dealer = $this->createDealer();
        $product = $this->createProduct(['stock' => 50]);

        $response = $this->actingAs($dealer)->postJson('/api/v1/orders', [
            'idempotency_key' => (string) Str::uuid(),
            'items' => [
                ['product_id' => $product->id, 'quantity' => -5],
            ],
        ]);

        $response->assertStatus(422);
        $response->assertJsonValidationErrors(['items.0.quantity']);
    }

    public function test_order_creation_rejects_empty_items_array(): void
    {
        $dealer = $this->createDealer();

        $response = $this->actingAs($dealer)->postJson('/api/v1/orders', [
            'idempotency_key' => (string) Str::uuid(),
            'items' => [],
        ]);

        $response->assertStatus(422);
        $response->assertJsonValidationErrors(['items']);
    }

    public function test_order_creation_rejects_quantity_exceeding_max_limit(): void
    {
        $dealer = $this->createDealer();
        $product = $this->createProduct();

        $response = $this->actingAs($dealer)->postJson('/api/v1/orders', [
            'idempotency_key' => (string) Str::uuid(),
            'items' => [
                ['product_id' => $product->id, 'quantity' => 10001],
            ],
        ]);

        $response->assertStatus(422);
        $response->assertJsonValidationErrors(['items.0.quantity']);
    }

    public function test_order_creation_rejects_inactive_or_suspended_partner(): void
    {
        $dealer = $this->createDealer(['status' => 'suspended']);
        $product = $this->createProduct();

        $response = $this->actingAs($dealer)->postJson('/api/v1/orders', [
            'idempotency_key' => (string) Str::uuid(),
            'items' => [
                ['product_id' => $product->id, 'quantity' => 5],
            ],
        ]);

        $response->assertStatus(403);
    }

    public function test_order_creation_rejects_non_wholesaler_staff_role(): void
    {
        $staff = $this->createStaff('finance');
        $product = $this->createProduct();

        $response = $this->actingAs($staff)->postJson('/api/v1/orders', [
            'idempotency_key' => (string) Str::uuid(),
            'items' => [
                ['product_id' => $product->id, 'quantity' => 5],
            ],
        ]);

        $response->assertStatus(403);
    }

    public function test_order_creation_rejects_stock_paused_product(): void
    {
        $dealer = $this->createDealer();
        $product = $this->createProduct(['is_stock_paused' => true]);

        $response = $this->actingAs($dealer)->postJson('/api/v1/orders', [
            'idempotency_key' => (string) Str::uuid(),
            'items' => [
                ['product_id' => $product->id, 'quantity' => 2],
            ],
        ]);

        $response->assertStatus(422);
        $response->assertJsonValidationErrors(['items']);
    }

    // ==========================================
    // 2. Payment Claim Boundary & Corner Cases
    // ==========================================

    public function test_payment_claim_rejects_negative_amount(): void
    {
        $dealer = $this->createDealer();
        $product = $this->createProduct();

        $orderRes = $this->actingAs($dealer)->postJson('/api/v1/orders', [
            'idempotency_key' => (string) Str::uuid(),
            'items' => [['product_id' => $product->id, 'quantity' => 2]],
        ]);
        $orderRes->assertCreated();
        $orderId = $orderRes->json('data.id');

        $response = $this->actingAs($dealer)->postJson("/api/v1/orders/{$orderId}/payments", [
            'channel' => 'Bank',
            'reference' => 'TXN-NEG-'.Str::random(6),
            'amount' => '-50.00',
        ]);

        $response->assertStatus(422);
        $response->assertJsonValidationErrors(['amount']);
    }

    public function test_payment_claim_rejects_zero_amount(): void
    {
        $dealer = $this->createDealer();
        $product = $this->createProduct();

        $orderRes = $this->actingAs($dealer)->postJson('/api/v1/orders', [
            'idempotency_key' => (string) Str::uuid(),
            'items' => [['product_id' => $product->id, 'quantity' => 2]],
        ]);
        $orderRes->assertCreated();
        $orderId = $orderRes->json('data.id');

        $response = $this->actingAs($dealer)->postJson("/api/v1/orders/{$orderId}/payments", [
            'channel' => 'Bank',
            'reference' => 'TXN-ZERO-'.Str::random(6),
            'amount' => '0.00',
        ]);

        $response->assertStatus(422);
        $response->assertJsonValidationErrors(['amount']);
    }

    public function test_payment_claim_rejects_amount_exceeding_outstanding_balance(): void
    {
        $dealer = $this->createDealer();
        $product = $this->createProduct(['dealer_price' => '100.00']);

        $orderRes = $this->actingAs($dealer)->postJson('/api/v1/orders', [
            'idempotency_key' => (string) Str::uuid(),
            'items' => [['product_id' => $product->id, 'quantity' => 1]],
        ]);
        $orderRes->assertCreated();
        $orderId = $orderRes->json('data.id');

        // Order total is 100.00, claiming 1000.00 exceeds outstanding
        $response = $this->actingAs($dealer)->postJson("/api/v1/orders/{$orderId}/payments", [
            'channel' => 'Bank',
            'reference' => 'TXN-OVER-'.Str::random(6),
            'amount' => '1000.00',
        ]);

        $response->assertStatus(422);
        $response->assertJsonValidationErrors(['amount']);
    }

    public function test_payment_claim_rejects_duplicate_reference(): void
    {
        $dealer = $this->createDealer();
        $product = $this->createProduct();

        $orderRes = $this->actingAs($dealer)->postJson('/api/v1/orders', [
            'idempotency_key' => (string) Str::uuid(),
            'items' => [['product_id' => $product->id, 'quantity' => 2]],
        ]);
        $orderId = $orderRes->json('data.id');

        $ref = 'TXN-DUP-'.Str::random(8);

        // First claim succeeds
        $first = $this->actingAs($dealer)->postJson("/api/v1/orders/{$orderId}/payments", [
            'channel' => 'bKash',
            'reference' => $ref,
            'amount' => '50.00',
        ]);
        $first->assertCreated();

        // Second claim with identical channel and reference must be rejected
        $second = $this->actingAs($dealer)->postJson("/api/v1/orders/{$orderId}/payments", [
            'channel' => 'bKash',
            'reference' => $ref,
            'amount' => '50.00',
        ]);
        $second->assertStatus(422);
        $second->assertJsonValidationErrors(['reference']);
    }

    public function test_payment_claim_rejects_on_cancelled_or_rejected_order(): void
    {
        $dealer = $this->createDealer();
        $product = $this->createProduct();

        $orderRes = $this->actingAs($dealer)->postJson('/api/v1/orders', [
            'idempotency_key' => (string) Str::uuid(),
            'items' => [['product_id' => $product->id, 'quantity' => 2]],
        ]);
        $orderId = $orderRes->json('data.id');

        // Mark order rejected
        Order::where('id', $orderId)->update(['status' => 'rejected']);

        $response = $this->actingAs($dealer)->postJson("/api/v1/orders/{$orderId}/payments", [
            'channel' => 'Nagad',
            'reference' => 'TXN-CLO-'.Str::random(6),
            'amount' => '50.00',
        ]);

        $response->assertStatus(422);
        $response->assertJsonValidationErrors(['reference']);
    }

    // ==========================================
    // 3. Dispatch & Inventory Boundary Cases
    // ==========================================

    public function test_dispatch_rejects_unapproved_order_status(): void
    {
        $dealer = $this->createDealer();
        $staff = $this->createStaff('super_admin');
        $branch = $this->createBranch();
        $product = $this->createProduct(['stock' => 100]);
        BranchProductStock::create(['branch_id' => $branch->id, 'product_id' => $product->id, 'quantity' => 100]);

        $orderRes = $this->actingAs($dealer)->postJson('/api/v1/orders', [
            'idempotency_key' => (string) Str::uuid(),
            'items' => [['product_id' => $product->id, 'quantity' => 5]],
        ]);
        $orderId = $orderRes->json('data.id');
        $lineId = $orderRes->json('data.lines.0.id');

        // Order is still 'submitted' (not approved yet)
        $response = $this->actingAs($staff)->postJson("/api/v1/orders/{$orderId}/dispatch", [
            'idempotency_key' => (string) Str::uuid(),
            'branch_id' => $branch->id,
            'courier' => 'Steadfast',
            'tracking' => 'TRK-'.Str::random(8),
            'items' => [
                ['line_id' => $lineId, 'quantity' => 5],
            ],
        ]);

        $response->assertStatus(422);
        $response->assertJsonValidationErrors(['shipment']);
    }

    public function test_dispatch_rejects_quantity_exceeding_ordered_line(): void
    {
        $dealer = $this->createDealer();
        $staff = $this->createStaff('super_admin');
        $branch = $this->createBranch();
        $product = $this->createProduct(['stock' => 100]);
        BranchProductStock::create(['branch_id' => $branch->id, 'product_id' => $product->id, 'quantity' => 100]);

        $orderRes = $this->actingAs($dealer)->postJson('/api/v1/orders', [
            'idempotency_key' => (string) Str::uuid(),
            'items' => [['product_id' => $product->id, 'quantity' => 5]],
        ]);
        $orderId = $orderRes->json('data.id');
        $lineId = $orderRes->json('data.lines.0.id');

        // Approve order
        $this->actingAs($staff)->postJson("/api/v1/orders/{$orderId}/decision", [
            'status' => 'approved',
            'reason' => 'Approved for dispatch test',
            'allow_credit' => true,
        ])->assertOk();

        // Try to dispatch 10 when only 5 were ordered
        $response = $this->actingAs($staff)->postJson("/api/v1/orders/{$orderId}/dispatch", [
            'idempotency_key' => (string) Str::uuid(),
            'branch_id' => $branch->id,
            'courier' => 'Steadfast',
            'tracking' => 'TRK-'.Str::random(8),
            'items' => [
                ['line_id' => $lineId, 'quantity' => 10],
            ],
        ]);

        $response->assertStatus(422);
        $response->assertJsonValidationErrors(['shipment']);
    }

    public function test_dispatch_rejects_quantity_exceeding_branch_inventory_stock(): void
    {
        $dealer = $this->createDealer();
        $staff = $this->createStaff('super_admin');
        $branch = $this->createBranch();
        $product = $this->createProduct(['stock' => 2]);
        // Branch only has 2 items in stock
        BranchProductStock::create(['branch_id' => $branch->id, 'product_id' => $product->id, 'quantity' => 2]);

        $orderRes = $this->actingAs($dealer)->postJson('/api/v1/orders', [
            'idempotency_key' => (string) Str::uuid(),
            'items' => [['product_id' => $product->id, 'quantity' => 5]],
        ]);
        $orderId = $orderRes->json('data.id');
        $lineId = $orderRes->json('data.lines.0.id');

        $this->actingAs($staff)->postJson("/api/v1/orders/{$orderId}/decision", [
            'status' => 'approved',
            'reason' => 'Approved for stock test',
            'allow_credit' => true,
        ])->assertOk();

        // Dispatch 5 when branch only has 2 in stock
        $response = $this->actingAs($staff)->postJson("/api/v1/orders/{$orderId}/dispatch", [
            'idempotency_key' => (string) Str::uuid(),
            'branch_id' => $branch->id,
            'courier' => 'Steadfast',
            'tracking' => 'TRK-'.Str::random(8),
            'items' => [
                ['line_id' => $lineId, 'quantity' => 5],
            ],
        ]);

        $response->assertStatus(422);
        $response->assertJsonValidationErrors(['shipment']);
    }

    // ==========================================
    // 4. Invalid Status Transitions
    // ==========================================

    public function test_order_decision_rejects_already_decided_order(): void
    {
        $dealer = $this->createDealer();
        $staff = $this->createStaff('super_admin');
        $product = $this->createProduct();

        $orderRes = $this->actingAs($dealer)->postJson('/api/v1/orders', [
            'idempotency_key' => (string) Str::uuid(),
            'items' => [['product_id' => $product->id, 'quantity' => 2]],
        ]);
        $orderId = $orderRes->json('data.id');

        // Approve first time
        $this->actingAs($staff)->postJson("/api/v1/orders/{$orderId}/decision", [
            'status' => 'approved',
            'reason' => 'First approval',
            'allow_credit' => true,
        ])->assertOk();

        // Second approval attempt should fail
        $second = $this->actingAs($staff)->postJson("/api/v1/orders/{$orderId}/decision", [
            'status' => 'approved',
            'reason' => 'Duplicate approval attempt',
            'allow_credit' => true,
        ]);

        $second->assertStatus(422);
        $second->assertJsonValidationErrors(['status']);
    }

    public function test_order_decision_rejects_approval_with_unauthorized_credit(): void
    {
        $dealer = $this->createDealer();
        $staff = $this->createStaff('super_admin');
        $product = $this->createProduct(['dealer_price' => '150.00']);

        $orderRes = $this->actingAs($dealer)->postJson('/api/v1/orders', [
            'idempotency_key' => (string) Str::uuid(),
            'items' => [['product_id' => $product->id, 'quantity' => 2]],
        ]);
        $orderId = $orderRes->json('data.id');

        // Attempting to approve an unpaid order without allow_credit => true
        $response = $this->actingAs($staff)->postJson("/api/v1/orders/{$orderId}/decision", [
            'status' => 'approved',
            'reason' => 'Approve without credit check',
            'allow_credit' => false,
        ]);

        $response->assertStatus(422);
        $response->assertJsonValidationErrors(['allow_credit']);
    }

    public function test_order_decision_rejects_invalid_status_value(): void
    {
        $dealer = $this->createDealer();
        $staff = $this->createStaff('super_admin');
        $product = $this->createProduct();

        $orderRes = $this->actingAs($dealer)->postJson('/api/v1/orders', [
            'idempotency_key' => (string) Str::uuid(),
            'items' => [['product_id' => $product->id, 'quantity' => 1]],
        ]);
        $orderId = $orderRes->json('data.id');

        $response = $this->actingAs($staff)->postJson("/api/v1/orders/{$orderId}/decision", [
            'status' => 'completed', // Not an allowed decision status (must be approved, held, or rejected)
            'reason' => 'Invalid status transition attempt',
        ]);

        $response->assertStatus(422);
        $response->assertJsonValidationErrors(['status']);
    }

    // ==========================================
    // 5. Idempotency Conflicts (409 Conflict)
    // ==========================================

    public function test_order_idempotency_key_replay_with_different_payload_returns_409(): void
    {
        $dealer = $this->createDealer();
        $product1 = $this->createProduct();
        $product2 = $this->createProduct();
        $key = (string) Str::uuid();

        // First order
        $first = $this->actingAs($dealer)->postJson('/api/v1/orders', [
            'idempotency_key' => $key,
            'items' => [['product_id' => $product1->id, 'quantity' => 2]],
        ]);
        $first->assertCreated();

        // Second order with SAME idempotency key but DIFFERENT product payload
        $second = $this->actingAs($dealer)->postJson('/api/v1/orders', [
            'idempotency_key' => $key,
            'items' => [['product_id' => $product2->id, 'quantity' => 4]],
        ]);

        $second->assertStatus(409);
    }

    public function test_dispatch_idempotency_key_replay_with_different_payload_returns_409(): void
    {
        $dealer = $this->createDealer();
        $staff = $this->createStaff('super_admin');
        $branch = $this->createBranch();
        $staff->update(['active_branch_id' => $branch->id]);
        $product = $this->createProduct(['stock' => 50]);
        BranchProductStock::create(['branch_id' => $branch->id, 'product_id' => $product->id, 'quantity' => 50]);

        $orderRes = $this->actingAs($dealer)->postJson('/api/v1/orders', [
            'idempotency_key' => (string) Str::uuid(),
            'items' => [['product_id' => $product->id, 'quantity' => 5]],
        ]);
        $orderId = $orderRes->json('data.id');
        $lineId = $orderRes->json('data.lines.0.id');

        $this->actingAs($staff)->postJson("/api/v1/orders/{$orderId}/decision", [
            'status' => 'approved',
            'reason' => 'Approved for dispatch test',
            'allow_credit' => true,
        ])->assertOk();

        $key = (string) Str::uuid();

        // First dispatch
        $first = $this->actingAs($staff)->postJson("/api/v1/orders/{$orderId}/dispatch", [
            'idempotency_key' => $key,
            'branch_id' => $branch->id,
            'courier' => 'Steadfast',
            'tracking' => 'TRK-DISPATCH-1',
            'items' => [
                ['line_id' => $lineId, 'quantity' => 2],
            ],
        ]);
        $first->assertOk();

        // Second dispatch with SAME key but different courier/tracking payload
        $second = $this->actingAs($staff)->postJson("/api/v1/orders/{$orderId}/dispatch", [
            'idempotency_key' => $key,
            'branch_id' => $branch->id,
            'courier' => 'Pathao',
            'tracking' => 'TRK-DISPATCH-2-ALTERED',
            'items' => [
                ['line_id' => $lineId, 'quantity' => 2],
            ],
        ]);

        $second->assertStatus(409);
    }

    // ==========================================
    // 6. Withdrawal Boundary & Negative Cases
    // ==========================================

    public function test_withdrawal_rejects_amount_below_minimum(): void
    {
        $dealer = $this->createDealer();
        $minimum = app(FinanceSettings::class)->withdrawalMinimum()['amount'];

        DB::table('wallets')->insert([
            'user_id' => $dealer->id,
            'available' => '5000.00',
            'held' => '0.00',
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        // Request an amount strictly less than the minimum
        $belowMin = (float) $minimum > 1.00 ? '1.00' : '0.10';

        $response = $this->actingAs($dealer)->postJson('/api/v1/withdrawals', [
            'idempotency_key' => (string) Str::uuid(),
            'channel' => 'bKash',
            'destination' => '01712345678',
            'amount' => $belowMin,
        ]);

        $response->assertStatus(422);
        $response->assertJsonValidationErrors(['amount']);
    }

    public function test_withdrawal_rejects_insufficient_wallet_balance(): void
    {
        $dealer = $this->createDealer();

        DB::table('wallets')->insert([
            'user_id' => $dealer->id,
            'available' => '100.00',
            'held' => '0.00',
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $response = $this->actingAs($dealer)->postJson('/api/v1/withdrawals', [
            'idempotency_key' => (string) Str::uuid(),
            'channel' => 'bKash',
            'destination' => '01712345678',
            'amount' => '5000.00',
        ]);

        $response->assertStatus(422);
        $response->assertJsonValidationErrors(['amount']);
    }

    public function test_withdrawal_idempotency_key_replay_with_different_payload_returns_409(): void
    {
        $dealer = $this->createDealer();
        $minimum = app(FinanceSettings::class)->withdrawalMinimum()['amount'];
        $amount = (string) max((float) $minimum, 500.00);

        DB::table('wallets')->insert([
            'user_id' => $dealer->id,
            'available' => '20000.00',
            'held' => '0.00',
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $key = (string) Str::uuid();

        // First request succeeds
        $first = $this->actingAs($dealer)->postJson('/api/v1/withdrawals', [
            'idempotency_key' => $key,
            'channel' => 'bKash',
            'destination' => '01711112222',
            'amount' => $amount,
        ]);
        $first->assertCreated();

        // Second request with SAME key but different destination phone
        $second = $this->actingAs($dealer)->postJson('/api/v1/withdrawals', [
            'idempotency_key' => $key,
            'channel' => 'bKash',
            'destination' => '01799998888',
            'amount' => $amount,
        ]);

        $second->assertStatus(409);
    }

    // ==========================================
    // 7. Financial Activation Safety Gate
    // ==========================================

    public function test_financial_mutations_blocked_when_financial_activation_is_false(): void
    {
        Config::set('madina.financial_activation', false);

        $dealer = $this->createDealer();

        DB::table('wallets')->insert([
            'user_id' => $dealer->id,
            'available' => '10000.00',
            'held' => '0.00',
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $this->expectException(ValidationException::class);
        CommissionWallet::requireActive();
    }

    // ==========================================
    // 8. TOTP / 2FA Challenge Edge Cases
    // ==========================================

    public function test_totp_2fa_challenge_rejects_invalid_code(): void
    {
        Config::set('madina.preview_mode', false);
        Config::set('madina.totp.enabled', true);

        $staff = $this->createStaff('finance', [
            'email' => 'totp.invalid@example.com',
            'password' => 'StaffPass123',
            'totp_secret' => encrypt('JBSWY3DPEHPK3PXP'),
            'totp_confirmed_at' => now(),
        ]);

        $login = $this->onPortal('manage')->post('/login', [
            'email' => $staff->email,
            'password' => 'StaffPass123',
        ]);

        $login->assertRedirect('/login/2fa');

        $challenge = $this->onPortal('manage')->post('/login/2fa', [
            'code' => '000000', // Invalid code
        ]);

        $challenge->assertSessionHasErrors('code');
    }

    public function test_totp_2fa_challenge_rejects_malformed_code(): void
    {
        Config::set('madina.preview_mode', false);
        Config::set('madina.totp.enabled', true);

        $staff = $this->createStaff('finance', [
            'email' => 'totp.malformed@example.com',
            'password' => 'StaffPass123',
            'totp_secret' => encrypt('JBSWY3DPEHPK3PXP'),
            'totp_confirmed_at' => now(),
        ]);

        $this->onPortal('manage')->post('/login', [
            'email' => $staff->email,
            'password' => 'StaffPass123',
        ])->assertRedirect('/login/2fa');

        $challenge = $this->onPortal('manage')->post('/login/2fa', [
            'code' => 'abc', // Malformed (not 6 digits)
        ]);

        $challenge->assertSessionHasErrors('code');
    }
}
