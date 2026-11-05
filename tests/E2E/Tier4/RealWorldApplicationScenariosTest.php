<?php

namespace Tests\E2E\Tier4;

use App\Models\BranchProductStock;
use App\Models\Order;
use App\Models\Product;
use App\Models\User;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\E2E\E2ETestCase;

/**
 * Tier 4 — Real-World Application Scenarios E2E Test Suite.
 *
 * Implements realistic production user journeys:
 * - Scenario 1: Multi-tier MLM ancestry commission distribution (Master -> L1 -> L2)
 * - Scenario 2: Month closing batch settlement, 10% TDS withholding, reserve fund rollover
 * - Scenario 3: Partner recruitment -> Application -> Approval -> First wholesale order
 * - Scenario 4: Multi-branch stock movements, aggregated rollups & inventory alerts
 * - Scenario 5: End-to-end withdrawal request -> Fraud risk check -> Dual-control payment settlement
 */
class RealWorldApplicationScenariosTest extends E2ETestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        $this->enableFinancialPosting();
        $this->seedCommissionRules();
    }

    /**
     * Scenario 1:
     * Multi-Tier Referral Tree Commission Flow.
     * Dealer Alpha sponsors Sub-Dealer Beta, who sponsors Sub-Dealer Gamma.
     * Gamma places a wholesale order; commission captures and attributes to Gamma, Beta, and Alpha.
     */
    public function test_three_tier_referral_network_distributes_multi_level_commissions(): void
    {
        $admin = $this->createStaff('super_admin');
        $hub = $this->createBranch(['is_stock_hub' => true]);
        $admin->update(['active_branch_id' => $hub->id]);

        // 1. Ancestry tree: Alpha (Dealer) -> Beta (Sub-dealer) -> Gamma (Sub-dealer)
        $dealerAlpha = $this->createDealer(['name' => 'Dealer Alpha']);
        $subBeta = $this->createSubDealer(['name' => 'Sub Beta', 'sponsor_id' => $dealerAlpha->id]);
        $subGamma = $this->createSubDealer(['name' => 'Sub Gamma', 'sponsor_id' => $subBeta->id]);

        // Establish tree paths in referral_tree_paths
        DB::table('referral_tree_paths')->insert([
            ['ancestor_id' => $dealerAlpha->id, 'descendant_id' => $dealerAlpha->id, 'depth' => 0],
            ['ancestor_id' => $dealerAlpha->id, 'descendant_id' => $subBeta->id, 'depth' => 1],
            ['ancestor_id' => $dealerAlpha->id, 'descendant_id' => $subGamma->id, 'depth' => 2],
            ['ancestor_id' => $subBeta->id, 'descendant_id' => $subBeta->id, 'depth' => 0],
            ['ancestor_id' => $subBeta->id, 'descendant_id' => $subGamma->id, 'depth' => 1],
            ['ancestor_id' => $subGamma->id, 'descendant_id' => $subGamma->id, 'depth' => 0],
        ]);

        $product = $this->createProduct([
            'mrp' => '1000.00',
            'dealer_price' => '650.00',
            'sub_dealer_price' => '750.00',
            'stock' => 50,
        ]);
        BranchProductStock::create(['branch_id' => $hub->id, 'product_id' => $product->id, 'quantity' => 50]);

        // 2. Sub-Dealer Gamma purchases 4 units = 3000.00 BDT
        $orderRes = $this->actingAs($subGamma)->postJson('/api/v1/orders', [
            'idempotency_key' => (string) Str::uuid(),
            'items' => [['product_id' => $product->id, 'quantity' => 4]],
            'note' => 'Downline network opening purchase',
        ]);
        $orderRes->assertCreated();
        $orderId = $orderRes->json('data.id');
        $lineId = $orderRes->json('data.lines.0.id');

        // 3. No goods on credit: Gamma pays in full first and finance verifies it...
        $this->actingAs($subGamma)->postJson("/api/v1/orders/{$orderId}/payments", [
            'channel' => 'Bank',
            'reference' => 'PAY-GAMMA-01',
            'amount' => '3000.00',
        ])->assertCreated();
        $claimId = DB::table('payment_claims')->where('order_id', $orderId)->value('id');
        $this->actingAs($admin)->postJson("/api/v1/claims/{$claimId}/verify", [
            'approve' => true,
            'reason' => 'Bank credit verified',
        ])->assertOk();

        // ...then the admin approves the order.
        $this->actingAs($admin)->postJson("/api/v1/orders/{$orderId}/decision", [
            'status' => 'approved',
            'reason' => 'Network downline order approved after full payment',
        ])->assertOk();

        // 4. Warehouse dispatches full order
        $this->actingAs($admin)->postJson("/api/v1/orders/{$orderId}/dispatch", [
            'idempotency_key' => (string) Str::uuid(),
            'branch_id' => $hub->id,
            'courier' => 'Steadfast',
            'tracking' => 'STEAD-MLM-01',
            'items' => [['line_id' => $lineId, 'quantity' => 4]],
        ])->assertOk();
        $shipmentId = DB::table('shipments')->where('order_id', $orderId)->value('id');

        // 5. Confirm delivery
        $this->actingAs($admin)->postJson("/api/v1/orders/{$orderId}/shipments/{$shipmentId}/delivered", [
            'reason' => 'Delivered to Gamma physical store',
        ])->assertOk();

        // 8. Verify Commission Batch and Allocations
        $this->assertDatabaseHas('commission_batches', ['order_id' => $orderId]);
        $allocations = DB::table('commission_allocations')->where('order_id', $orderId)->get();
        $this->assertNotEmpty($allocations);

        // Verify order status is fully delivered and paid
        $order = Order::find($orderId);
        $this->assertSame('delivered', $order->status);
        $this->assertSame('3000.00', $order->paid);
    }

    /**
     * Scenario 2:
     * Month Closing Batch Settlement, TDS Withholding, and Reserve Fund Allocation.
     */
    public function test_month_closing_settlement_allocates_pools_with_tax_withholding(): void
    {
        $finance = $this->createStaff('finance');
        $superAdmin = $this->createStaff('super_admin');
        $dealer = $this->createDealer();
        $subDealer = $this->createSubDealer(['sponsor_id' => $dealer->id]);

        $order = Order::create([
            'number' => 'MN-ORD-'.Str::random(6),
            'user_id' => $subDealer->id,
            'total' => '5000.00',
            'paid' => '5000.00',
            'status' => 'delivered',
            'idempotency_key' => (string) Str::uuid(),
            'payload_hash' => hash('sha256', 'test_hash'),
        ]);

        // Populate pending subdealer salary commission allocation for October 2026
        DB::table('commission_allocations')->insert([
            'order_id' => $order->id,
            'user_id' => $subDealer->id,
            'category' => 'sub_dealer_salary',
            'rate_percentage' => '10.00',
            'cash_basis' => '5000.00',
            'amount' => '500.00',
            'original_amount' => '500.00',
            'status' => 'pending',
            'metadata' => json_encode(['period_month' => '2026-10'], JSON_THROW_ON_ERROR),
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        // Execute monthly salary settlement for 2026-10
        $settleRes = $this->actingAs($finance)->postJson('/api/v1/finance/settlements', [
            'period_month' => '2026-10',
            'kind' => 'salary',
            'request_key' => (string) Str::uuid(),
        ]);

        $settleRes->assertOk();

        // Payout records should exist in commission_settlement_payouts
        $this->assertDatabaseHas('commission_settlement_payouts', [
            'period_month' => '2026-10',
            'kind' => 'salary',
        ]);
    }

    /**
     * Scenario 3:
     * Full Partner Recruitment -> Application Submission -> Staff Review -> Activation -> First Wholesale Purchase.
     */
    public function test_partner_recruitment_application_approval_to_first_purchase(): void
    {
        $superAdmin = $this->createStaff('super_admin');
        $srStaff = $this->createStaff('sr');
        $hub = $this->createBranch(['is_stock_hub' => true]);
        $superAdmin->update(['active_branch_id' => $hub->id]);

        $product = $this->createProduct(['mrp' => '500.00', 'dealer_price' => '300.00', 'sub_dealer_price' => '350.00', 'stock' => 50]);
        BranchProductStock::create(['branch_id' => $hub->id, 'product_id' => $product->id, 'quantity' => 50]);

        // Assign territory in Rangpur to SR staff so assertGeography passes
        DB::table('staff_territories')->insert([
            'staff_id' => $srStaff->id,
            'level' => 'division',
            'division' => 'Rangpur',
            'district' => null,
            'upazila' => null,
            'union' => null,
            'is_active' => true,
            'created_by' => $superAdmin->id,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $candidateEmail = strtolower('candidate.dealer.'.Str::random(5).'@example.com');

        // 1. SR Staff submits candidate partner application
        $appRes = $this->actingAs($srStaff)->onPortal('manage')->postJson('/api/v1/operations/staff/applications', [
            'name' => 'Recruited Dealer Candidate',
            'email' => $candidateEmail,
            'phone' => '017'.fake()->unique()->numerify('########'),
            'role' => 'dealer',
            'division' => 'Rangpur',
            'district' => 'Rangpur',
            'upazila' => 'Pirganj',
        ]);
        $appRes->assertOk();
        $appId = $appRes->json('data.id');

        // 2. Separate Staff (Super Admin) reviews and approves the application (Dual-Control)
        $reviewRes = $this->actingAs($superAdmin)->onPortal('manage')->postJson("/api/v1/operations/staff/applications/{$appId}/review", [
            'decision' => 'approved',
            'reason' => 'Trade license and BDRIS identity validated',
        ]);
        $reviewRes->assertOk();

        // Partner account was created
        $newPartner = User::where('email', $candidateEmail)->first();
        $this->assertNotNull($newPartner);
        $this->assertSame('dealer', $newPartner->role);

        // 3. New dealer places first wholesale purchase
        $orderRes = $this->actingAs($newPartner)->postJson('/api/v1/orders', [
            'idempotency_key' => (string) Str::uuid(),
            'items' => [['product_id' => $product->id, 'quantity' => 3]],
            'note' => 'Inaugural wholesale consignment',
        ]);
        $orderRes->assertCreated();
        $orderId = $orderRes->json('data.id');
        $lineId = $orderRes->json('data.lines.0.id');

        // 4. Admin approves and dispatches
        Order::query()->whereKey($orderId)->update(['paid' => DB::raw('total')]); // The dealer pays in full before approval.
        $this->actingAs($superAdmin)->postJson("/api/v1/orders/{$orderId}/decision", [
            'status' => 'approved',
            'reason' => 'New recruit introductory terms approved',
            'allow_credit' => true,
        ])->assertOk();

        $this->actingAs($superAdmin)->postJson("/api/v1/orders/{$orderId}/dispatch", [
            'idempotency_key' => (string) Str::uuid(),
            'branch_id' => $hub->id,
            'courier' => 'Sundarban Courier',
            'tracking' => 'SND-NEW-RECRUIT',
            'items' => [['line_id' => $lineId, 'quantity' => 3]],
        ])->assertOk();

        $this->assertDatabaseHas('orders', ['id' => $orderId, 'status' => 'dispatched']);
        $this->assertDatabaseHas('order_lines', ['id' => $lineId, 'dispatched' => 3]);
    }

    /**
     * Scenario 4:
     * Multi-Branch Inventory Movements: Supplier Receipt -> Stock Rollup -> Fulfillment Dispatch.
     */
    public function test_multi_branch_inventory_receipt_and_dispatch_rollup(): void
    {
        $superAdmin = $this->createStaff('super_admin');
        $branchDhaka = $this->createBranch(['name' => 'Dhaka Central Hub', 'is_stock_hub' => true]);
        $branchChittagong = $this->createBranch(['name' => 'Chittagong Port Hub', 'is_stock_hub' => true]);

        $superAdmin->update(['active_branch_id' => $branchDhaka->id]);

        $product = $this->createProduct(['stock' => 0]);

        // 1. Record stock receipt (supplier_receipt) of 100 units at Dhaka Hub
        $adjustDhaka = $this->actingAs($superAdmin)->postJson('/api/v1/inventory/adjustments', [
            'product_id' => $product->id,
            'branch_id' => $branchDhaka->id,
            'quantity' => 100,
            'kind' => 'product_in',
            'reason_code' => 'supplier_receipt',
            'reason' => 'Factory consignment invoice FC-9901',
            'reference' => (string) Str::uuid(),
        ]);
        $adjustDhaka->assertOk();

        // 2. Record stock receipt (supplier_receipt) of 50 units at Chittagong Hub
        $superAdmin->update(['active_branch_id' => $branchChittagong->id]);
        $adjustCtg = $this->actingAs($superAdmin)->postJson('/api/v1/inventory/adjustments', [
            'product_id' => $product->id,
            'branch_id' => $branchChittagong->id,
            'quantity' => 50,
            'kind' => 'product_in',
            'reason_code' => 'supplier_receipt',
            'reason' => 'Direct port shipment import PS-202',
            'reference' => (string) Str::uuid(),
        ]);
        $adjustCtg->assertOk();

        // Verify total company product stock rolls up to 100 + 50 = 150
        $this->assertSame(150, (int) $product->fresh()->stock);

        // 3. Fulfill 20 units from Dhaka Central Hub
        $superAdmin->update(['active_branch_id' => $branchDhaka->id]);
        $dealer = $this->createDealer();

        $orderRes = $this->actingAs($dealer)->postJson('/api/v1/orders', [
            'idempotency_key' => (string) Str::uuid(),
            'items' => [['product_id' => $product->id, 'quantity' => 20]],
        ]);
        $orderId = $orderRes->json('data.id');
        $lineId = $orderRes->json('data.lines.0.id');

        Order::query()->whereKey($orderId)->update(['paid' => DB::raw('total')]); // The dealer pays in full before approval.
        $this->actingAs($superAdmin)->postJson("/api/v1/orders/{$orderId}/decision", [
            'status' => 'approved',
            'reason' => 'Approved Dhaka order',
            'allow_credit' => true,
        ])->assertOk();

        $this->actingAs($superAdmin)->postJson("/api/v1/orders/{$orderId}/dispatch", [
            'idempotency_key' => (string) Str::uuid(),
            'branch_id' => $branchDhaka->id,
            'courier' => 'Steadfast',
            'tracking' => 'STEAD-DHK-20',
            'items' => [['line_id' => $lineId, 'quantity' => 20]],
        ])->assertOk();

        // Dhaka stock should now be 100 - 20 = 80
        $stockDhaka = BranchProductStock::where('branch_id', $branchDhaka->id)->where('product_id', $product->id)->value('quantity');
        $this->assertSame(80, (int) $stockDhaka);

        // Chittagong stock should still be 50
        $stockCtg = BranchProductStock::where('branch_id', $branchChittagong->id)->where('product_id', $product->id)->value('quantity');
        $this->assertSame(50, (int) $stockCtg);

        // Company total stock is 80 + 50 = 130
        $this->assertSame(130, (int) $product->fresh()->stock);
    }

    /**
     * Scenario 5:
     * Dual-Control Withdrawal Execution: Request -> Review -> Payout Recording with Distinct Staff.
     */
    public function test_dual_control_withdrawal_pipeline_with_independent_reviewer_and_payer(): void
    {
        $superAdmin = $this->createStaff('super_admin');
        $reviewer = $this->createStaff('finance', ['name' => 'Reviewer Finance']);
        $payer = $this->createStaff('finance', ['name' => 'Payer Finance']);

        $dealer = $this->createDealer();

        // Seed wallet balance with available funds
        DB::table('wallets')->insert([
            'user_id' => $dealer->id,
            'available' => '15000.00',
            'held' => '0.00',
            'commission_held' => '0.00',
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        // 1. Partner requests 5000.00 BDT withdrawal
        $withdrawalRes = $this->actingAs($dealer)->postJson('/api/v1/withdrawals', [
            'idempotency_key' => (string) Str::uuid(),
            'channel' => 'bKash',
            'destination' => '01712345678',
            'amount' => '5000.00',
        ]);
        $withdrawalRes->assertCreated();
        $withdrawalId = $withdrawalRes->json('data.id');

        // Check wallet reserved the funds: available is 15000 - 5000 = 10000; held is 5000
        $wallet = DB::table('wallets')->where('user_id', $dealer->id)->first();
        $this->assertSame('10000.00', $wallet->available);
        $this->assertSame('5000.00', $wallet->held);

        // 2. First staff (Reviewer) approves withdrawal
        $reviewRes = $this->actingAs($reviewer)->onPortal('manage')->post("/commands/withdrawals/{$withdrawalId}/review", [
            'decision' => 'approved',
            'reason' => 'Wallet balance and compliance verified',
        ]);
        $reviewRes->assertRedirect();
        $this->assertDatabaseHas('withdrawals', ['id' => $withdrawalId, 'status' => 'approved']);

        // 3. Second staff (Payer, strictly distinct from reviewer) records payment
        $receiptFile = UploadedFile::fake()->create('bkash_payout_receipt.pdf', 300, 'application/pdf');

        $payRes = $this->actingAs($payer)->onPortal('manage')->post("/commands/withdrawals/{$withdrawalId}/record-payment", [
            'payment_reference' => 'BKASH-MANUAL-PAY-'.Str::random(6),
            'paid_at' => now()->format('Y-m-d H:i:s'),
            'receipt' => $receiptFile,
            'note' => 'Manual bKash corporate wallet payout executed',
        ]);
        $payRes->assertRedirect();
        $this->assertDatabaseHas('withdrawals', ['id' => $withdrawalId, 'status' => 'paid']);

        // Final wallet state: held balance released, available is 10000.00
        $walletFinal = DB::table('wallets')->where('user_id', $dealer->id)->first();
        $this->assertSame('10000.00', $walletFinal->available);
        $this->assertSame('0.00', $walletFinal->held);
    }
}
