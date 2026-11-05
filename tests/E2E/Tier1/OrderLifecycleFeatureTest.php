<?php

namespace Tests\E2E\Tier1;

use App\Models\Order;
use App\Models\Product;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\E2E\E2ETestCase;

/**
 * Tier 1 — Order Lifecycle Feature Tests.
 *
 * Covers:
 * - F04: Live Order Lifecycle Tracking
 * - F05: Payment Claim Submission
 * - F06: Payment Claim Verification
 * - F07: Order Decision Workflow
 * - F08: Multi-Branch Stock Dispatch
 * - F09: Delivery Confirmation
 */
class OrderLifecycleFeatureTest extends E2ETestCase
{
    /**
     * Helper to create a submitted order for testing.
     */
    private function createSubmittedOrder(User $dealer, ?Product $product = null, int $quantity = 2): Order
    {
        $product ??= $this->createProduct(['mrp' => '400.00', 'dealer_price' => '250.00', 'stock' => 50]);

        $response = $this->actingAs($dealer)->postJson('/api/v1/orders', [
            'items' => [['product_id' => $product->id, 'quantity' => $quantity]],
            'idempotency_key' => (string) Str::uuid(),
        ]);

        return Order::findOrFail($response->json('data.id'));
    }

    // ==========================================
    // F04: Live Order Lifecycle Tracking
    // ==========================================

    /**
     * F04: Order show endpoint returns full order details, lines, claims, and shipments.
     */
    public function test_order_show_returns_comprehensive_order_details(): void
    {
        $dealer = $this->createDealer();
        $order = $this->createSubmittedOrder($dealer);

        $response = $this->actingAs($dealer)->getJson('/api/v1/orders/'.$order->id);

        $response->assertOk();
        $response->assertJsonPath('data.id', $order->id);
        $response->assertJsonPath('data.status', 'submitted');
        $response->assertJsonPath('data.total', '500.00');
        $this->assertNotEmpty($response->json('data.lines'));
        $this->assertIsArray($response->json('data.payment_claims'));
        $this->assertIsArray($response->json('data.shipments'));
    }

    /**
     * F04: Order index endpoint supports pagination, filtering, and query search.
     */
    public function test_order_index_filters_by_status_and_search(): void
    {
        $dealer = $this->createDealer();
        $o1 = $this->createSubmittedOrder($dealer);
        $o2 = $this->createSubmittedOrder($dealer);

        // Filter by status
        $responseStatus = $this->actingAs($dealer)->getJson('/api/v1/orders?status=submitted');
        $responseStatus->assertOk();
        $this->assertCount(2, $responseStatus->json('data'));

        // Search by order number
        $responseSearch = $this->actingAs($dealer)->getJson('/api/v1/orders?q='.$o1->number);
        $responseSearch->assertOk();
        $this->assertCount(1, $responseSearch->json('data'));
        $this->assertSame($o1->id, $responseSearch->json('data.0.id'));
    }

    /**
     * F04: Partner order visibility is strictly isolated to own orders.
     */
    public function test_partner_cannot_view_another_partners_order(): void
    {
        $dealer1 = $this->createDealer();
        $dealer2 = $this->createDealer();
        $order1 = $this->createSubmittedOrder($dealer1);

        $response = $this->actingAs($dealer2)->getJson('/api/v1/orders/'.$order1->id);

        $response->assertNotFound();
    }

    /**
     * F04: Staff with orders capability can inspect any partner order.
     */
    public function test_staff_can_view_any_order(): void
    {
        $staff = $this->createStaff('super_admin');
        $dealer = $this->createDealer();
        $order = $this->createSubmittedOrder($dealer);

        $response = $this->actingAs($staff)->getJson('/api/v1/orders/'.$order->id);

        $response->assertOk();
        $response->assertJsonPath('data.id', $order->id);
    }

    /**
     * F04: Order line items retain snapshot of unit price and total.
     */
    public function test_order_lines_preserve_price_snapshots(): void
    {
        $dealer = $this->createDealer();
        $product = $this->createProduct(['mrp' => '600.00', 'dealer_price' => '360.00', 'stock' => 10]);
        $order = $this->createSubmittedOrder($dealer, $product, 3);

        $line = $order->lines()->first();
        $this->assertNotNull($line);
        $this->assertSame('360.00', $line->unit_price);
        $this->assertSame('1080.00', $line->total);
        $this->assertSame(3, (int) $line->quantity);
    }

    // ==========================================
    // F05: Payment Claim Submission
    // ==========================================

    /**
     * F05: Partner submits a Bank payment claim with transaction reference.
     */
    public function test_partner_submits_bank_payment_claim(): void
    {
        $dealer = $this->createDealer();
        $order = $this->createSubmittedOrder($dealer);

        $response = $this->actingAs($dealer)->postJson('/api/v1/orders/'.$order->id.'/payments', [
            'channel' => 'Bank',
            'reference' => 'TXN-BANK-1001',
            'amount' => '300.00',
        ]);

        $response->assertCreated();
        $this->assertDatabaseHas('payment_claims', [
            'order_id' => $order->id,
            'channel' => 'Bank',
            'reference' => 'TXN-BANK-1001',
            'amount' => '300.00',
            'status' => 'pending',
        ]);
    }

    /**
     * F05: Partner submits Mobile Financial Services payment claim (bKash/Nagad).
     */
    public function test_partner_submits_mobile_financial_claim(): void
    {
        $dealer = $this->createDealer();
        $order = $this->createSubmittedOrder($dealer);

        $response = $this->actingAs($dealer)->postJson('/api/v1/orders/'.$order->id.'/payments', [
            'channel' => 'bKash',
            'reference' => 'BKASH-776655',
            'amount' => '200.00',
        ]);

        $response->assertCreated();
        $this->assertDatabaseHas('payment_claims', [
            'order_id' => $order->id,
            'channel' => 'bKash',
            'reference' => 'BKASH-776655',
            'amount' => '200.00',
        ]);
    }

    /**
     * F05: Multiple partial payment claims can be submitted on the same order.
     */
    public function test_multiple_partial_claims_on_same_order(): void
    {
        $dealer = $this->createDealer();
        $order = $this->createSubmittedOrder($dealer); // Total = 500.00

        // Claim 1: 300.00
        $this->actingAs($dealer)->postJson('/api/v1/orders/'.$order->id.'/payments', [
            'channel' => 'Bank',
            'reference' => 'PARTIAL-001',
            'amount' => '300.00',
        ])->assertCreated();

        // Claim 2: 200.00
        $this->actingAs($dealer)->postJson('/api/v1/orders/'.$order->id.'/payments', [
            'channel' => 'Nagad',
            'reference' => 'PARTIAL-002',
            'amount' => '200.00',
        ])->assertCreated();

        $this->assertSame(2, DB::table('payment_claims')->where('order_id', $order->id)->count());
    }

    /**
     * F05: Payment claim cannot be submitted on another partner's order.
     */
    public function test_partner_cannot_claim_payment_on_another_partners_order(): void
    {
        $dealer1 = $this->createDealer();
        $dealer2 = $this->createDealer();
        $order1 = $this->createSubmittedOrder($dealer1);

        $response = $this->actingAs($dealer2)->postJson('/api/v1/orders/'.$order1->id.'/payments', [
            'channel' => 'Bank',
            'reference' => 'UNAUTHORIZED-TXN',
            'amount' => '100.00',
        ]);

        $response->assertNotFound();
    }

    /**
     * F05: Payment claim with empty channel or reference fails validation.
     */
    public function test_payment_claim_validates_required_fields(): void
    {
        $dealer = $this->createDealer();
        $order = $this->createSubmittedOrder($dealer);

        $response = $this->actingAs($dealer)->postJson('/api/v1/orders/'.$order->id.'/payments', [
            'channel' => '',
            'reference' => '',
            'amount' => '0.00',
        ]);

        $response->assertUnprocessable();
    }

    // ==========================================
    // F06: Payment Claim Verification
    // ==========================================

    /**
     * F06: Staff approves payment claim, updating order paid amount and creating receipt.
     */
    public function test_staff_approves_payment_claim(): void
    {
        $staff = $this->createStaff('super_admin');
        $dealer = $this->createDealer();
        $order = $this->createSubmittedOrder($dealer);

        $this->actingAs($dealer)->postJson('/api/v1/orders/'.$order->id.'/payments', [
            'channel' => 'Bank',
            'reference' => 'VERIFY-OK-01',
            'amount' => '500.00',
        ]);
        $claimId = DB::table('payment_claims')->where('order_id', $order->id)->value('id');

        $response = $this->actingAs($staff)->postJson('/api/v1/claims/'.$claimId.'/verify', [
            'approve' => true,
            'reason' => 'Matched bank credit advice',
        ]);

        $response->assertOk();
        $this->assertDatabaseHas('payment_claims', ['id' => $claimId, 'status' => 'verified']);
        $this->assertDatabaseHas('orders', ['id' => $order->id, 'paid' => '500.00']);
        $this->assertDatabaseHas('receipts', ['claim_id' => $claimId, 'amount' => '500.00']);
    }

    /**
     * F06: Staff rejects payment claim with reason, leaving order paid balance unchanged.
     */
    public function test_staff_rejects_payment_claim(): void
    {
        $staff = $this->createStaff('super_admin');
        $dealer = $this->createDealer();
        $order = $this->createSubmittedOrder($dealer);

        $this->actingAs($dealer)->postJson('/api/v1/orders/'.$order->id.'/payments', [
            'channel' => 'bKash',
            'reference' => 'FAKE-TXN-999',
            'amount' => '500.00',
        ]);
        $claimId = DB::table('payment_claims')->where('order_id', $order->id)->value('id');

        $response = $this->actingAs($staff)->postJson('/api/v1/claims/'.$claimId.'/verify', [
            'approve' => false,
            'reason' => 'Transaction ID not found on bKash merchant portal',
        ]);

        $response->assertOk();
        $this->assertDatabaseHas('payment_claims', ['id' => $claimId, 'status' => 'rejected']);
        $this->assertDatabaseHas('orders', ['id' => $order->id, 'paid' => '0.00']);
        $this->assertDatabaseCount('receipts', 0);
    }

    /**
     * F06: Re-verifying an already reviewed claim fails with validation error.
     */
    public function test_cannot_re_verify_already_reviewed_claim(): void
    {
        $staff = $this->createStaff('super_admin');
        $dealer = $this->createDealer();
        $order = $this->createSubmittedOrder($dealer);

        $this->actingAs($dealer)->postJson('/api/v1/orders/'.$order->id.'/payments', [
            'channel' => 'Bank',
            'reference' => 'ONCE-ONLY',
            'amount' => '500.00',
        ]);
        $claimId = DB::table('payment_claims')->where('order_id', $order->id)->value('id');

        $this->actingAs($staff)->postJson('/api/v1/claims/'.$claimId.'/verify', [
            'approve' => true,
            'reason' => 'First review approved',
        ])->assertOk();

        // Attempt second review
        $response = $this->actingAs($staff)->postJson('/api/v1/claims/'.$claimId.'/verify', [
            'approve' => true,
            'reason' => 'Duplicate review attempt',
        ]);

        $response->assertUnprocessable();
    }

    /**
     * F06: Overpaying beyond order total is rejected.
     */
    public function test_payment_claim_overpay_is_rejected(): void
    {
        $dealer = $this->createDealer();
        $order = $this->createSubmittedOrder($dealer); // Total = 500.00

        // Submitting claim exceeding outstanding order total is rejected with 422
        $response = $this->actingAs($dealer)->postJson('/api/v1/orders/'.$order->id.'/payments', [
            'channel' => 'Bank',
            'reference' => 'OVERPAY-TXN',
            'amount' => '600.00', // Exceeds 500.00
        ]);

        $response->assertUnprocessable();
        $response->assertJsonValidationErrors('amount');
    }

    /**
     * F06: Non-staff user cannot verify claims.
     */
    public function test_dealer_cannot_verify_payment_claim(): void
    {
        $dealer = $this->createDealer();
        $order = $this->createSubmittedOrder($dealer);
        $this->actingAs($dealer)->postJson('/api/v1/orders/'.$order->id.'/payments', [
            'channel' => 'Bank',
            'reference' => 'PEER-VERIFY',
            'amount' => '500.00',
        ]);
        $claimId = DB::table('payment_claims')->where('order_id', $order->id)->value('id');

        $otherDealer = $this->createDealer();
        $response = $this->actingAs($otherDealer)->postJson('/api/v1/claims/'.$claimId.'/verify', [
            'approve' => true,
            'reason' => 'Unauthorized partner verification',
        ]);

        $response->assertForbidden();
    }

    // ==========================================
    // F07: Order Decision Workflow
    // ==========================================

    /**
     * F07: Staff approves fully paid order successfully.
     */
    public function test_staff_approves_fully_paid_order(): void
    {
        $staff = $this->createStaff('super_admin');
        $dealer = $this->createDealer();
        $order = $this->createSubmittedOrder($dealer); // 500.00

        // Pay in full
        $order->update(['paid' => '500.00']);

        $response = $this->actingAs($staff)->postJson('/api/v1/orders/'.$order->id.'/decision', [
            'status' => 'approved',
            'reason' => 'Payment confirmed in full',
        ]);

        $response->assertOk();
        $response->assertJsonPath('data.status', 'approved');
        $this->assertDatabaseHas('orders', ['id' => $order->id, 'status' => 'approved']);
    }

    /**
     * F07: Staff approves unpaid order when finance credit authorization is granted.
     */
    public function test_credit_is_retired_an_unpaid_order_cannot_be_approved_even_with_the_credit_flag(): void
    {
        $staff = $this->createStaff('super_admin');
        $dealer = $this->createDealer();
        $order = $this->createSubmittedOrder($dealer); // Unpaid 500.00

        $this->actingAs($staff)->postJson('/api/v1/orders/'.$order->id.'/decision', [
            'status' => 'approved',
            'reason' => 'Trying the old 7-day credit agreement',
            'allow_credit' => true,
        ])->assertUnprocessable()->assertJsonValidationErrors(['status']);
        $this->assertDatabaseHas('orders', ['id' => $order->id, 'status' => 'submitted', 'credit_authorized_by' => null]);

        // Once the dealer has paid in full, the same order can be approved.
        $order->forceFill(['paid' => $order->fresh()->total])->save();
        $this->actingAs($staff)->postJson('/api/v1/orders/'.$order->id.'/decision', [
            'status' => 'approved',
            'reason' => 'Approved after full payment',
        ])->assertOk();
        $this->assertDatabaseHas('orders', ['id' => $order->id, 'status' => 'approved']);
    }

    /**
     * F07: Approving unpaid order without credit authorization fails.
     */
    public function test_approving_unpaid_order_without_credit_fails(): void
    {
        $staff = $this->createStaff('super_admin');
        $dealer = $this->createDealer();
        $order = $this->createSubmittedOrder($dealer);

        $response = $this->actingAs($staff)->postJson('/api/v1/orders/'.$order->id.'/decision', [
            'status' => 'approved',
            'reason' => 'Attempt approval without credit',
            'allow_credit' => false,
        ]);

        $response->assertUnprocessable();
    }

    /**
     * F07: Staff places order on held status with reason.
     */
    public function test_staff_places_order_on_hold(): void
    {
        $staff = $this->createStaff('super_admin');
        $dealer = $this->createDealer();
        $order = $this->createSubmittedOrder($dealer);

        $response = $this->actingAs($staff)->postJson('/api/v1/orders/'.$order->id.'/decision', [
            'status' => 'held',
            'reason' => 'Awaiting warehouse stock replenishment',
        ]);

        $response->assertOk();
        $this->assertDatabaseHas('orders', ['id' => $order->id, 'status' => 'held']);
    }

    /**
     * F07: Staff rejects unpaid order.
     */
    public function test_staff_rejects_unpaid_order(): void
    {
        $staff = $this->createStaff('super_admin');
        $dealer = $this->createDealer();
        $order = $this->createSubmittedOrder($dealer);

        $response = $this->actingAs($staff)->postJson('/api/v1/orders/'.$order->id.'/decision', [
            'status' => 'rejected',
            'reason' => 'Requested cancellation by dealer',
        ]);

        $response->assertOk();
        $this->assertDatabaseHas('orders', ['id' => $order->id, 'status' => 'rejected']);
    }

    // ==========================================
    // F08: Multi-Branch Stock Dispatch
    // ==========================================

    /**
     * F08: Staff dispatches order line items from branch stock.
     */
    public function test_staff_dispatches_order_line_items(): void
    {
        $staff = $this->createStaff('super_admin');
        $branch = $this->createBranch(['is_stock_hub' => true]);
        $dealer = $this->createDealer();
        $product = $this->createProduct(['stock' => 100]);
        $order = $this->createSubmittedOrder($dealer, $product, 5);
        $order->update(['status' => 'approved', 'paid' => $order->total]);

        $lineId = $order->lines()->value('id');

        $response = $this->actingAs($staff)->postJson('/api/v1/orders/'.$order->id.'/dispatch', [
            'courier' => 'Sundarban Courier',
            'tracking' => 'SND-998877',
            'branch_id' => $branch->id,
            'idempotency_key' => (string) Str::uuid(),
            'items' => [
                ['line_id' => $lineId, 'quantity' => 5],
            ],
        ]);

        $response->assertOk();
        $this->assertDatabaseHas('shipments', [
            'order_id' => $order->id,
            'courier' => 'Sundarban Courier',
            'tracking' => 'SND-998877',
            'status' => 'dispatched',
        ]);
        $this->assertDatabaseHas('orders', ['id' => $order->id, 'status' => 'dispatched']);
        $this->assertDatabaseHas('order_lines', ['id' => $lineId, 'dispatched' => 5]);
    }

    /**
     * F08: Partial line item dispatch transitions status to partially_dispatched.
     */
    public function test_partial_dispatch_sets_partially_dispatched_status(): void
    {
        $staff = $this->createStaff('super_admin');
        $branch = $this->createBranch(['is_stock_hub' => true]);
        $dealer = $this->createDealer();
        $product = $this->createProduct(['stock' => 50]);
        $order = $this->createSubmittedOrder($dealer, $product, 4);
        $order->update(['status' => 'approved', 'paid' => $order->total]);

        $lineId = $order->lines()->value('id');

        $response = $this->actingAs($staff)->postJson('/api/v1/orders/'.$order->id.'/dispatch', [
            'courier' => 'SA Paribahan',
            'tracking' => 'SAP-112233',
            'branch_id' => $branch->id,
            'idempotency_key' => (string) Str::uuid(),
            'items' => [
                ['line_id' => $lineId, 'quantity' => 2], // 2 of 4 dispatched
            ],
        ]);

        $response->assertOk();
        $this->assertDatabaseHas('orders', ['id' => $order->id, 'status' => 'partially_dispatched']);
        $this->assertDatabaseHas('order_lines', ['id' => $lineId, 'dispatched' => 2]);
    }

    /**
     * F08: Dispatching on an unapproved order fails with validation error.
     */
    public function test_dispatch_fails_on_unapproved_order(): void
    {
        $staff = $this->createStaff('super_admin');
        $branch = $this->createBranch(['is_stock_hub' => true]);
        $dealer = $this->createDealer();
        $order = $this->createSubmittedOrder($dealer); // status = submitted

        $lineId = $order->lines()->value('id');

        $response = $this->actingAs($staff)->postJson('/api/v1/orders/'.$order->id.'/dispatch', [
            'courier' => 'Karatoa Courier',
            'tracking' => 'KRT-001',
            'branch_id' => $branch->id,
            'idempotency_key' => (string) Str::uuid(),
            'items' => [['line_id' => $lineId, 'quantity' => 1]],
        ]);

        $response->assertUnprocessable();
    }

    /**
     * F08: Dispatching quantity exceeding remaining line quantity fails.
     */
    public function test_dispatch_quantity_cannot_exceed_order_quantity(): void
    {
        $staff = $this->createStaff('super_admin');
        $branch = $this->createBranch(['is_stock_hub' => true]);
        $dealer = $this->createDealer();
        $order = $this->createSubmittedOrder($dealer, null, 2);
        $order->update(['status' => 'approved', 'paid' => '500.00']);

        $lineId = $order->lines()->value('id');

        $response = $this->actingAs($staff)->postJson('/api/v1/orders/'.$order->id.'/dispatch', [
            'courier' => 'Sundarban Courier',
            'tracking' => 'SND-EXCEED',
            'branch_id' => $branch->id,
            'idempotency_key' => (string) Str::uuid(),
            'items' => [['line_id' => $lineId, 'quantity' => 10]], // Only 2 ordered
        ]);

        $response->assertUnprocessable();
    }

    /**
     * F08: Dispatch idempotency key prevents duplicate shipments.
     */
    public function test_dispatch_idempotency_prevents_duplicate_shipments(): void
    {
        $staff = $this->createStaff('super_admin');
        $branch = $this->createBranch(['is_stock_hub' => true]);
        $dealer = $this->createDealer();
        $order = $this->createSubmittedOrder($dealer, null, 2);
        $order->update(['status' => 'approved', 'paid' => '500.00']);

        $lineId = $order->lines()->value('id');
        $idemKey = (string) Str::uuid();

        $payload = [
            'courier' => 'Sundarban Courier',
            'tracking' => 'SND-IDEM-01',
            'branch_id' => $branch->id,
            'idempotency_key' => $idemKey,
            'items' => [['line_id' => $lineId, 'quantity' => 1]],
        ];

        $this->actingAs($staff)->postJson('/api/v1/orders/'.$order->id.'/dispatch', $payload)->assertOk();
        $this->assertSame(1, DB::table('shipments')->where('order_id', $order->id)->count());

        // Repeated post with same idempotency key and payload
        $this->actingAs($staff)->postJson('/api/v1/orders/'.$order->id.'/dispatch', $payload)->assertOk();
        $this->assertSame(1, DB::table('shipments')->where('order_id', $order->id)->count());
    }

    // ==========================================
    // F09: Delivery Confirmation
    // ==========================================

    /**
     * F09: Staff confirms shipment delivery and sets delivered_at timestamp.
     */
    public function test_staff_confirms_shipment_delivery(): void
    {
        $staff = $this->createStaff('super_admin');
        $branch = $this->createBranch(['is_stock_hub' => true]);
        $dealer = $this->createDealer();
        $order = $this->createSubmittedOrder($dealer, null, 2);
        $order->update(['status' => 'approved', 'paid' => '500.00']);

        $lineId = $order->lines()->value('id');
        $this->actingAs($staff)->postJson('/api/v1/orders/'.$order->id.'/dispatch', [
            'courier' => 'Sundarban Courier',
            'tracking' => 'SND-DELIVERY-01',
            'branch_id' => $branch->id,
            'idempotency_key' => (string) Str::uuid(),
            'items' => [['line_id' => $lineId, 'quantity' => 2]],
        ])->assertOk();

        $shipmentId = DB::table('shipments')->where('order_id', $order->id)->value('id');

        $response = $this->actingAs($staff)->postJson('/api/v1/orders/'.$order->id.'/shipments/'.$shipmentId.'/delivered', [
            'reason' => 'Signed POD receipt verified from courier portal',
        ]);

        $response->assertOk();
        $this->assertDatabaseHas('shipments', [
            'id' => $shipmentId,
            'status' => 'delivered',
            'delivered_by' => $staff->id,
        ]);
        $this->assertDatabaseHas('orders', [
            'id' => $order->id,
            'status' => 'delivered',
        ]);
    }

    /**
     * F09: Delivery confirmation is idempotent.
     */
    public function test_delivery_confirmation_is_idempotent(): void
    {
        $staff = $this->createStaff('super_admin');
        $branch = $this->createBranch(['is_stock_hub' => true]);
        $dealer = $this->createDealer();
        $order = $this->createSubmittedOrder($dealer, null, 1);
        $order->update(['status' => 'approved', 'paid' => '250.00']);

        $lineId = $order->lines()->value('id');
        $this->actingAs($staff)->postJson('/api/v1/orders/'.$order->id.'/dispatch', [
            'courier' => 'Sundarban Courier',
            'tracking' => 'SND-IDEM-DELIVER',
            'branch_id' => $branch->id,
            'idempotency_key' => (string) Str::uuid(),
            'items' => [['line_id' => $lineId, 'quantity' => 1]],
        ])->assertOk();

        $shipmentId = DB::table('shipments')->where('order_id', $order->id)->value('id');

        // First delivery confirmation
        $this->actingAs($staff)->postJson('/api/v1/orders/'.$order->id.'/shipments/'.$shipmentId.'/delivered', [
            'reason' => 'First delivery confirmation',
        ])->assertOk();

        // Second delivery confirmation (idempotent no-op)
        $this->actingAs($staff)->postJson('/api/v1/orders/'.$order->id.'/shipments/'.$shipmentId.'/delivered', [
            'reason' => 'Repeated delivery confirmation',
        ])->assertOk();

        $this->assertDatabaseHas('shipments', ['id' => $shipmentId, 'status' => 'delivered']);
    }

    /**
     * F09: Non-staff user cannot confirm shipment delivery.
     */
    public function test_dealer_cannot_confirm_delivery(): void
    {
        $staff = $this->createStaff('super_admin');
        $branch = $this->createBranch(['is_stock_hub' => true]);
        $dealer = $this->createDealer();
        $order = $this->createSubmittedOrder($dealer, null, 1);
        $order->update(['status' => 'approved', 'paid' => '250.00']);

        $lineId = $order->lines()->value('id');
        $this->actingAs($staff)->postJson('/api/v1/orders/'.$order->id.'/dispatch', [
            'courier' => 'Sundarban Courier',
            'tracking' => 'SND-SECURITY-DEL',
            'branch_id' => $branch->id,
            'idempotency_key' => (string) Str::uuid(),
            'items' => [['line_id' => $lineId, 'quantity' => 1]],
        ])->assertOk();

        $shipmentId = DB::table('shipments')->where('order_id', $order->id)->value('id');

        $response = $this->actingAs($dealer)->postJson('/api/v1/orders/'.$order->id.'/shipments/'.$shipmentId.'/delivered', [
            'reason' => 'Unauthorized partner delivery confirmation attempt',
        ]);

        $response->assertForbidden();
    }
}
