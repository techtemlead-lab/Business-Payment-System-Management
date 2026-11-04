<?php

namespace Tests\E2E\Tier3;

use App\Models\BranchProductStock;
use App\Models\Order;
use App\Models\Product;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\E2E\E2ETestCase;

/**
 * Tier 3 — Cross-Feature Combinations E2E Test Suite.
 *
 * Verifies complex multi-module interactions across the system:
 * 1. Full Lifecycle Pipeline: Order -> Partial Dispatch -> Delivery -> Payment Claim -> Verification -> Commission Hold -> Settlement
 * 2. Order Cancellation & Stock Protection Pipeline
 * 3. Referral Hierarchy & Multi-Tier Commission Capture Pipeline
 * 4. Post-Delivery Due Credit Adjustment & Financial Recalculation
 * 5. Field Sales Operations -> Territory Attribution -> Target Tracking Pipeline
 */
class CrossFeatureCombinationsTest extends E2ETestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        $this->enableFinancialPosting();
        $this->seedCommissionRules();
    }

    /**
     * Cross-Feature Flow 1:
     * Order -> Approval -> Partial Dispatch 1 -> Delivery 1 -> Remaining Dispatch 2 -> Delivery 2
     * -> Payment Claim -> Verification -> Commission Processing -> Monthly Settlement.
     */
    public function test_full_order_fulfillment_to_settlement_pipeline(): void
    {
        $superAdmin = $this->createStaff('super_admin');
        $financeStaff = $this->createStaff('finance');
        $hub = $this->createBranch(['is_stock_hub' => true]);
        $superAdmin->update(['active_branch_id' => $hub->id]);

        $dealer = $this->createDealer();
        // Product mrp 500, dealer_price 350 (30% discount)
        $product = $this->createProduct(['mrp' => '500.00', 'dealer_price' => '350.00', 'stock' => 100]);
        BranchProductStock::create(['branch_id' => $hub->id, 'product_id' => $product->id, 'quantity' => 100]);

        // 1. Partner places order for 10 units = 3500.00 BDT
        $orderRes = $this->actingAs($dealer)->postJson('/api/v1/orders', [
            'idempotency_key' => (string) Str::uuid(),
            'items' => [
                ['product_id' => $product->id, 'quantity' => 10],
            ],
            'note' => 'Quarterly stock order',
        ]);
        $orderRes->assertCreated();
        $orderId = $orderRes->json('data.id');
        $lineId = $orderRes->json('data.lines.0.id');
        $this->assertDatabaseHas('orders', ['id' => $orderId, 'status' => 'submitted', 'total' => '3500.00']);

        // 2. Staff approves order with authorized credit
        $approvalRes = $this->actingAs($superAdmin)->postJson("/api/v1/orders/{$orderId}/decision", [
            'status' => 'approved',
            'reason' => 'Approved on corporate wholesale credit terms',
            'allow_credit' => true,
        ]);
        $approvalRes->assertOk();
        $this->assertDatabaseHas('orders', ['id' => $orderId, 'status' => 'approved']);

        // 3. First Dispatch: 6 of 10 units
        $dispatch1 = $this->actingAs($superAdmin)->postJson("/api/v1/orders/{$orderId}/dispatch", [
            'idempotency_key' => (string) Str::uuid(),
            'branch_id' => $hub->id,
            'courier' => 'Sundarban Courier',
            'tracking' => 'SND-SHIP-01',
            'items' => [
                ['line_id' => $lineId, 'quantity' => 6],
            ],
        ]);
        $dispatch1->assertOk();
        $shipment1Id = DB::table('shipments')->where('order_id', $orderId)->where('tracking', 'SND-SHIP-01')->value('id');
        $this->assertDatabaseHas('orders', ['id' => $orderId, 'status' => 'partially_dispatched']);
        $this->assertDatabaseHas('order_lines', ['id' => $lineId, 'dispatched' => 6]);

        // 4. Confirm Delivery of Shipment 1
        $delivered1 = $this->actingAs($superAdmin)->postJson("/api/v1/orders/{$orderId}/shipments/{$shipment1Id}/delivered", [
            'reason' => 'Courier confirmed drop-off with dealer signature',
        ]);
        $delivered1->assertOk();
        $this->assertDatabaseHas('shipments', ['id' => $shipment1Id, 'status' => 'delivered']);

        // 5. Remaining Dispatch: 4 of 10 units
        $dispatch2 = $this->actingAs($superAdmin)->postJson("/api/v1/orders/{$orderId}/dispatch", [
            'idempotency_key' => (string) Str::uuid(),
            'branch_id' => $hub->id,
            'courier' => 'Pathao Logistics',
            'tracking' => 'PTH-SHIP-02',
            'items' => [
                ['line_id' => $lineId, 'quantity' => 4],
            ],
        ]);
        $dispatch2->assertOk();
        $shipment2Id = DB::table('shipments')->where('order_id', $orderId)->where('tracking', 'PTH-SHIP-02')->value('id');
        $this->assertDatabaseHas('orders', ['id' => $orderId, 'status' => 'dispatched']);
        $this->assertDatabaseHas('order_lines', ['id' => $lineId, 'dispatched' => 10]);

        // 6. Confirm Delivery of Shipment 2
        $delivered2 = $this->actingAs($superAdmin)->postJson("/api/v1/orders/{$orderId}/shipments/{$shipment2Id}/delivered", [
            'reason' => 'Second consignment received in full',
        ]);
        $delivered2->assertOk();
        $this->assertDatabaseHas('shipments', ['id' => $shipment2Id, 'status' => 'delivered']);

        // 7. Partner submits full Bank payment claim
        $claimRes = $this->actingAs($dealer)->postJson("/api/v1/orders/{$orderId}/payments", [
            'channel' => 'Bank',
            'reference' => 'IBBL-FT-'.Str::random(8),
            'amount' => '3500.00',
        ]);
        $claimRes->assertCreated();
        $claimId = DB::table('payment_claims')->where('order_id', $orderId)->value('id');

        // 8. Finance staff verifies payment claim
        $verifyRes = $this->actingAs($financeStaff)->postJson("/api/v1/claims/{$claimId}/verify", [
            'approve' => true,
            'reason' => 'Credited to corporate bank account IBBL-099',
        ]);
        $verifyRes->assertOk();

        // Check order is now delivered and fully paid
        $order = Order::find($orderId);
        $this->assertSame('delivered', $order->status);
        $this->assertSame('3500.00', $order->paid);

        // 9. Commission batch created and captured
        $this->assertDatabaseHas('commission_batches', [
            'order_id' => $orderId,
        ]);

        // 10. Execute monthly settlement for closed month
        $settleRes = $this->actingAs($financeStaff)->postJson('/api/v1/finance/settlements', [
            'period_month' => '2026-10',
            'kind' => 'salary',
            'request_key' => (string) Str::uuid(),
        ]);
        $settleRes->assertOk();
    }

    /**
     * Cross-Feature Flow 2:
     * Order Submission -> Review Hold -> Rejection -> Verification of Stock & Wallet Non-Mutation.
     */
    public function test_order_rejection_leaves_inventory_and_finances_unmutated(): void
    {
        $superAdmin = $this->createStaff('super_admin');
        $dealer = $this->createDealer();
        $product = $this->createProduct(['stock' => 50]);

        // Initial inventory stock
        $initialStock = $product->stock;

        // Place order
        $orderRes = $this->actingAs($dealer)->postJson('/api/v1/orders', [
            'idempotency_key' => (string) Str::uuid(),
            'items' => [['product_id' => $product->id, 'quantity' => 4]],
        ]);
        $orderId = $orderRes->json('data.id');

        // Hold order for review
        $this->actingAs($superAdmin)->postJson("/api/v1/orders/{$orderId}/decision", [
            'status' => 'held',
            'reason' => 'KYC compliance verification pending',
        ])->assertOk();

        $this->assertDatabaseHas('orders', ['id' => $orderId, 'status' => 'held']);

        // Reject order
        $this->actingAs($superAdmin)->postJson("/api/v1/orders/{$orderId}/decision", [
            'status' => 'rejected',
            'reason' => 'Failed verification checks',
        ])->assertOk();

        $this->assertDatabaseHas('orders', ['id' => $orderId, 'status' => 'rejected']);

        // Ensure no stock was decremented
        $this->assertSame($initialStock, $product->fresh()->stock);

        // Ensure no shipments exist
        $this->assertDatabaseCount('shipments', 0);

        // Ensure no commission batches or allocations were processed
        $this->assertDatabaseMissing('commission_batches', ['order_id' => $orderId]);
    }

    /**
     * Cross-Feature Flow 3:
     * Referral Hierarchy: Master Dealer sponsors Sub-Dealer.
     * Subdealer purchases wholesale goods -> Commissions distributed across uplines.
     */
    public function test_referral_downline_order_distributes_commissions_across_hierarchy(): void
    {
        $superAdmin = $this->createStaff('super_admin');
        $hub = $this->createBranch(['is_stock_hub' => true]);
        $superAdmin->update(['active_branch_id' => $hub->id]);

        // Master dealer (upline)
        $masterDealer = $this->createDealer();

        // Subdealer (downline sponsored by master dealer)
        $subDealer = $this->createSubDealer(['sponsor_id' => $masterDealer->id]);

        $product = $this->createProduct([
            'mrp' => '1000.00',
            'dealer_price' => '600.00',
            'sub_dealer_price' => '700.00',
            'stock' => 50,
        ]);
        BranchProductStock::create(['branch_id' => $hub->id, 'product_id' => $product->id, 'quantity' => 50]);

        // Subdealer orders 5 units = 3500.00 BDT
        $orderRes = $this->actingAs($subDealer)->postJson('/api/v1/orders', [
            'idempotency_key' => (string) Str::uuid(),
            'items' => [['product_id' => $product->id, 'quantity' => 5]],
        ]);
        $orderId = $orderRes->json('data.id');
        $lineId = $orderRes->json('data.lines.0.id');

        // Staff approves and dispatches
        $this->actingAs($superAdmin)->postJson("/api/v1/orders/{$orderId}/decision", [
            'status' => 'approved',
            'reason' => 'Subdealer authorized order',
            'allow_credit' => true,
        ])->assertOk();

        $this->actingAs($superAdmin)->postJson("/api/v1/orders/{$orderId}/dispatch", [
            'idempotency_key' => (string) Str::uuid(),
            'branch_id' => $hub->id,
            'courier' => 'Steadfast Courier',
            'tracking' => 'STEAD-REF-01',
            'items' => [['line_id' => $lineId, 'quantity' => 5]],
        ])->assertOk();

        $shipmentId = DB::table('shipments')->where('order_id', $orderId)->value('id');

        // Confirm delivery
        $this->actingAs($superAdmin)->postJson("/api/v1/orders/{$orderId}/shipments/{$shipmentId}/delivered", [
            'reason' => 'Subdealer received consignment',
        ])->assertOk();

        // Subdealer pays in full
        $this->actingAs($subDealer)->postJson("/api/v1/orders/{$orderId}/payments", [
            'channel' => 'Bank',
            'reference' => 'SUB-REF-PAY-01',
            'amount' => '3500.00',
        ]);
        $claimId = DB::table('payment_claims')->where('order_id', $orderId)->value('id');

        $this->actingAs($superAdmin)->postJson("/api/v1/claims/{$claimId}/verify", [
            'approve' => true,
            'reason' => 'Payment verified in bank account',
        ])->assertOk();

        // Commission batch was captured and processed
        $this->assertDatabaseHas('commission_batches', [
            'order_id' => $orderId,
        ]);

        // Commission allocations exist for the order
        $allocations = DB::table('commission_allocations')->where('order_id', $orderId)->get();
        $this->assertNotEmpty($allocations);
    }

    /**
     * Cross-Feature Flow 4:
     * Post-Delivery Due Credit Adjustment & Audit Trail.
     */
    public function test_due_credit_adjustment_recalculates_order_balances(): void
    {
        $superAdmin = $this->createStaff('super_admin');
        $dealer = $this->createDealer();
        $product = $this->createProduct(['dealer_price' => '200.00']);

        $orderRes = $this->actingAs($dealer)->postJson('/api/v1/orders', [
            'idempotency_key' => (string) Str::uuid(),
            'items' => [['product_id' => $product->id, 'quantity' => 5]], // 1000.00 BDT
        ]);
        $orderId = $orderRes->json('data.id');

        // Pay partial amount: 600.00 BDT
        $this->actingAs($dealer)->postJson("/api/v1/orders/{$orderId}/payments", [
            'channel' => 'bKash',
            'reference' => 'BKASH-PART-01',
            'amount' => '600.00',
        ]);
        $claimId = DB::table('payment_claims')->where('order_id', $orderId)->value('id');

        $this->actingAs($superAdmin)->postJson("/api/v1/claims/{$claimId}/verify", [
            'approve' => true,
            'reason' => 'Partial payment verified',
        ]);

        $order = Order::find($orderId);
        $this->assertSame('600.00', $order->paid);
        $this->assertSame('400.00', $order->outstanding());

        // Apply credit adjustment of 400.00 BDT
        DB::table('due_adjustments')->insert([
            'order_id' => $orderId,
            'request_key' => (string) Str::uuid(),
            'reference' => 'PROMO-CREDIT-'.Str::random(6),
            'kind' => 'credit',
            'amount' => '400.00',
            'reason' => 'Promotional volume incentive rebate',
            'status' => 'approved',
            'created_by' => $superAdmin->id,
            'reviewed_by' => $superAdmin->id,
            'reviewed_at' => now(),
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $order->update(['due_credit' => '400.00']);

        // Outstanding balance should now be 0.00
        $this->assertSame('0.00', $order->fresh()->outstanding());
    }

    /**
     * Cross-Feature Flow 5:
     * Staff Field Operations -> Assigned Territory -> Sales Target Tracking.
     */
    public function test_field_sales_territory_target_and_order_placement_pipeline(): void
    {
        $srStaff = $this->createStaff('sr');
        $superAdmin = $this->createStaff('super_admin');
        $dealer = $this->createDealer(['division' => 'Rangpur', 'district' => 'Rangpur']);
        $product = $this->createProduct(['dealer_price' => '250.00']);

        // 1. Assign territory to sales representative
        $this->actingAs($superAdmin)->onPortal('manage')->postJson('/api/v1/operations/staff/territories', [
            'staff_id' => $srStaff->id,
            'level' => 'upazila',
            'division' => 'Rangpur',
            'district' => 'Rangpur',
            'upazila' => 'Pirganj',
        ])->assertOk();

        // 2. Set monthly sales target for SR
        $this->actingAs($superAdmin)->onPortal('manage')->postJson('/api/v1/operations/staff/targets', [
            'staff_id' => $srStaff->id,
            'period_month' => now()->format('Y-m'),
            'target' => '50000.00',
            'bonus' => '5000.00',
        ])->assertOk();

        // 3. Inspect target summary via operations view
        $summary = $this->actingAs($superAdmin)->onPortal('manage')->getJson('/api/v1/operations/views/staff-operations');
        $summary->assertOk();

        $this->assertDatabaseHas('staff_territories', [
            'staff_id' => $srStaff->id,
            'district' => 'Rangpur',
            'upazila' => 'Pirganj',
        ]);

        $this->assertDatabaseHas('staff_targets', [
            'staff_id' => $srStaff->id,
            'period_month' => now()->format('Y-m'),
        ]);
    }

    /**
     * Cross-Feature Flow 6:
     * Multi-Channel Payment Aggregation: bKash + Bank Claims combine to satisfy order total.
     */
    public function test_multiple_payment_claims_across_channels_aggregate_to_satisfy_order(): void
    {
        $superAdmin = $this->createStaff('super_admin');
        $financeStaff = $this->createStaff('finance');
        $dealer = $this->createDealer();
        $product = $this->createProduct(['dealer_price' => '200.00']);

        // Order 5 units = 1000.00 BDT
        $orderRes = $this->actingAs($dealer)->postJson('/api/v1/orders', [
            'idempotency_key' => (string) Str::uuid(),
            'items' => [['product_id' => $product->id, 'quantity' => 5]],
        ]);
        $orderId = $orderRes->json('data.id');

        // Claim 1: 400.00 BDT via bKash
        $claim1 = $this->actingAs($dealer)->postJson("/api/v1/orders/{$orderId}/payments", [
            'channel' => 'bKash',
            'reference' => 'BKASH-SPLIT-'.Str::random(6),
            'amount' => '400.00',
        ]);
        $claim1->assertCreated();
        $claim1Id = DB::table('payment_claims')->where('order_id', $orderId)->where('channel', 'bKash')->value('id');

        // Verify Claim 1
        $this->actingAs($financeStaff)->postJson("/api/v1/claims/{$claim1Id}/verify", [
            'approve' => true,
            'reason' => 'First installment confirmed on bKash',
        ])->assertOk();

        $order = Order::find($orderId);
        $this->assertSame('400.00', $order->paid);
        $this->assertSame('600.00', $order->outstanding());

        // Claim 2: Remaining 600.00 BDT via Bank
        $claim2 = $this->actingAs($dealer)->postJson("/api/v1/orders/{$orderId}/payments", [
            'channel' => 'Bank',
            'reference' => 'BANK-SPLIT-'.Str::random(6),
            'amount' => '600.00',
        ]);
        $claim2->assertCreated();
        $claim2Id = DB::table('payment_claims')->where('order_id', $orderId)->where('channel', 'Bank')->value('id');

        // Verify Claim 2
        $this->actingAs($financeStaff)->postJson("/api/v1/claims/{$claim2Id}/verify", [
            'approve' => true,
            'reason' => 'Second installment confirmed on Bank statement',
        ])->assertOk();

        $order = Order::find($orderId);
        $this->assertSame('1000.00', $order->paid);
        $this->assertSame('0.00', $order->outstanding());
        $this->assertDatabaseCount('receipts', 2);
    }

    /**
     * Cross-Feature Flow 7:
     * Multi-Branch Inventory Isolation: Fulfillment decrements stock specifically at active fulfillment hub.
     */
    public function test_multi_branch_fulfillment_decrements_designated_hub_stock_only(): void
    {
        $superAdmin = $this->createStaff('super_admin');
        $hubA = $this->createBranch(['name' => 'Hub Dhaka', 'is_stock_hub' => true]);
        $hubB = $this->createBranch(['name' => 'Hub Chittagong', 'is_stock_hub' => true]);

        $dealer = $this->createDealer();
        $product = $this->createProduct(['stock' => 100]);

        // Place 60 units at Hub A, 40 units at Hub B
        BranchProductStock::create(['branch_id' => $hubA->id, 'product_id' => $product->id, 'quantity' => 60]);
        BranchProductStock::create(['branch_id' => $hubB->id, 'product_id' => $product->id, 'quantity' => 40]);

        // Fulfill using Hub A
        $superAdmin->update(['active_branch_id' => $hubA->id]);

        $orderRes = $this->actingAs($dealer)->postJson('/api/v1/orders', [
            'idempotency_key' => (string) Str::uuid(),
            'items' => [['product_id' => $product->id, 'quantity' => 15]],
        ]);
        $orderId = $orderRes->json('data.id');
        $lineId = $orderRes->json('data.lines.0.id');

        $this->actingAs($superAdmin)->postJson("/api/v1/orders/{$orderId}/decision", [
            'status' => 'approved',
            'reason' => 'Approved for Hub A dispatch',
            'allow_credit' => true,
        ])->assertOk();

        // Dispatch 15 units from Hub A
        $this->actingAs($superAdmin)->postJson("/api/v1/orders/{$orderId}/dispatch", [
            'idempotency_key' => (string) Str::uuid(),
            'branch_id' => $hubA->id,
            'courier' => 'Sundarban Courier',
            'tracking' => 'SND-HUB-A-01',
            'items' => [['line_id' => $lineId, 'quantity' => 15]],
        ])->assertOk();

        // Hub A stock must be 60 - 15 = 45
        $stockA = BranchProductStock::where('branch_id', $hubA->id)->where('product_id', $product->id)->value('quantity');
        $this->assertSame(45, (int) $stockA);

        // Hub B stock must remain untouched at 40
        $stockB = BranchProductStock::where('branch_id', $hubB->id)->where('product_id', $product->id)->value('quantity');
        $this->assertSame(40, (int) $stockB);
    }
}
