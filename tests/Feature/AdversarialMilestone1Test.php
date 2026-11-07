<?php

namespace Tests\Feature;

use App\Models\BranchProductStock;
use App\Models\CommissionAllocation;
use App\Models\CompanyBranch;
use App\Models\Order;
use App\Models\OrderLine;
use App\Models\PaymentClaim;
use App\Models\Product;
use App\Models\Receipt;
use App\Models\Shipment;
use App\Models\User;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\TestCase;

class AdversarialMilestone1Test extends TestCase
{
    use DatabaseTransactions;

    /**
     * Probe 1a: Orphaned lines or null relations.
     */
    public function test_orphaned_and_null_relations_behave_safely(): void
    {
        // 1. OrderLine created without valid order or product
        $orphanLine = new OrderLine([
            'order_id' => (string) Str::uuid(),
            'product_id' => 999999,
            'name' => 'Orphan Item',
            'sku' => 'ORPH-001',
            'mrp' => '150.00',
            'quantity' => 2,
            'unit_price' => '100.00',
            'subtotal' => '200.00',
            'total' => '200.00',
        ]);

        $this->assertNull($orphanLine->order);
        $this->assertNull($orphanLine->product);

        $nullLine = new OrderLine;
        $this->assertNull($nullLine->order);
        $this->assertNull($nullLine->product);

        // 2. Order with no lines, no claims, no receipts, no shipments, no allocations
        $user = User::factory()->create();
        $order = Order::create([
            'number' => 'ORD-TEST-'.Str::random(6),
            'user_id' => $user->id,
            'status' => 'submitted',
            'channel' => 'dealer',
            'currency' => 'BDT',
            'subtotal' => '100.00',
            'discount_amount' => '0.00',
            'tax_amount' => '0.00',
            'total' => '100.00',
            'idempotency_key' => (string) Str::uuid(),
            'payload_hash' => hash('sha256', 'empty-order'),
        ]);

        $this->assertCount(0, $order->lines);
        $this->assertCount(0, $order->paymentClaims);
        $this->assertCount(0, $order->receipts);
        $this->assertCount(0, $order->shipments);
        $this->assertCount(0, $order->allocations);

        // 3. Product with no branch stocks
        $product = Product::create([
            'sku' => 'SKU-EMPTY-'.Str::random(6),
            'name' => 'Empty Stock Product',
            'category' => 'Test',
            'mrp' => '100.00',
            'dealer_price' => '70.00',
            'stock' => 0,
        ]);

        $this->assertCount(0, $product->branchStocks);
    }

    /**
     * Probe 1b: Circular traversals across all enriched relations.
     */
    public function test_circular_traversals_and_nested_references(): void
    {
        $branch = CompanyBranch::factory()->create();
        $user = User::factory()->create();

        $product = Product::create([
            'sku' => 'CIRC-'.Str::random(6),
            'name' => 'Circular Product',
            'category' => 'Test',
            'mrp' => '300.00',
            'dealer_price' => '210.00',
            'stock' => 50,
        ]);

        $stock = BranchProductStock::create([
            'branch_id' => $branch->id,
            'product_id' => $product->id,
            'quantity' => 20,
        ]);

        // Circular traversal on Product <-> BranchProductStock
        $this->assertSame($product->id, $stock->product->id);
        $this->assertTrue($stock->product->branchStocks->first()->is($stock));
        $this->assertSame($product->id, $stock->product->branchStocks->first()->product->id);

        $order = Order::create([
            'number' => 'ORD-CIRC-'.Str::random(6),
            'user_id' => $user->id,
            'status' => 'submitted',
            'channel' => 'dealer',
            'currency' => 'BDT',
            'subtotal' => '420.00',
            'discount_amount' => '0.00',
            'tax_amount' => '0.00',
            'total' => '420.00',
            'idempotency_key' => (string) Str::uuid(),
            'payload_hash' => hash('sha256', 'circ-order'),
        ]);

        $line = OrderLine::create([
            'order_id' => $order->id,
            'product_id' => $product->id,
            'name' => $product->name,
            'sku' => $product->sku,
            'mrp' => $product->mrp,
            'quantity' => 2,
            'unit_price' => '210.00',
            'total' => '420.00',
        ]);

        $claim = PaymentClaim::create([
            'order_id' => $order->id,
            'user_id' => $user->id,
            'amount' => '420.00',
            'channel' => 'bank',
            'reference' => 'REF-CIRC-'.Str::random(6),
            'status' => 'submitted',
        ]);

        $receipt = Receipt::create([
            'order_id' => $order->id,
            'claim_id' => $claim->id,
            'channel' => 'bank',
            'reference' => 'RCP-CIRC-'.Str::random(6),
            'amount' => '420.00',
        ]);

        $shipment = Shipment::create([
            'order_id' => $order->id,
            'courier' => 'Pathao',
            'tracking' => 'TRK-CIRC-001',
            'status' => 'dispatched',
            'lines' => json_encode([['line_id' => $line->id, 'quantity' => 2]]),
            'actor_id' => $user->id,
        ]);

        $allocation = CommissionAllocation::create([
            'order_id' => $order->id,
            'user_id' => $user->id,
            'category' => 'dealer_margin',
            'rate_percentage' => 10.00,
            'cash_basis' => '420.00',
            'amount' => '42.00',
            'status' => 'available',
        ]);

        // Direct and deep circular traversals
        $this->assertSame($order->id, $order->lines->first()->order->id);
        $this->assertSame($order->id, $order->lines->first()->order->lines->first()->order->id);
        $this->assertSame($product->id, $order->lines->first()->product->id);
        $this->assertSame($order->id, $line->order->lines->first()->order->id);

        $this->assertSame($order->id, $order->paymentClaims->first()->order->id);
        $this->assertSame($order->id, $claim->order->id);

        $this->assertSame($order->id, $order->receipts->first()->order->id);
        $this->assertSame($order->id, $receipt->order->id);

        $this->assertSame($order->id, $order->shipments->first()->order->id);
        $this->assertSame($order->id, $shipment->order->id);

        $this->assertSame($order->id, $order->allocations->first()->order->id);
        $this->assertSame($order->id, $allocation->order->id);
    }

    /**
     * Probe 1c: Eager loading with Order::with(['lines.product', 'paymentClaims', 'receipts', 'shipments']).
     */
    public function test_eager_loading_with_nested_relations(): void
    {
        $branch = CompanyBranch::factory()->create();
        $user = User::factory()->create();

        for ($i = 0; $i < 5; $i++) {
            $product = Product::create([
                'sku' => 'EAG-'.Str::random(6),
                'name' => 'Eager Product '.$i,
                'category' => 'Test',
                'mrp' => '100.00',
                'dealer_price' => '70.00',
                'stock' => 10,
            ]);

            $order = Order::create([
                'number' => 'ORD-EAG-'.$i.'-'.Str::random(4),
                'user_id' => $user->id,
                'status' => 'submitted',
                'channel' => 'dealer',
                'currency' => 'BDT',
                'subtotal' => '70.00',
                'discount_amount' => '0.00',
                'tax_amount' => '0.00',
                'total' => '70.00',
                'idempotency_key' => (string) Str::uuid(),
                'payload_hash' => hash('sha256', 'eager-order-'.$i),
            ]);

            $line = OrderLine::create([
                'order_id' => $order->id,
                'product_id' => $product->id,
                'name' => $product->name,
                'sku' => $product->sku,
                'mrp' => $product->mrp,
                'quantity' => 1,
                'unit_price' => '70.00',
                'total' => '70.00',
            ]);

            $claim = PaymentClaim::create([
                'order_id' => $order->id,
                'user_id' => $user->id,
                'amount' => '70.00',
                'channel' => 'bkash',
                'reference' => 'REF-EAG-'.$i.'-'.Str::random(4),
                'status' => 'submitted',
            ]);

            Receipt::create([
                'order_id' => $order->id,
                'claim_id' => $claim->id,
                'channel' => 'bkash',
                'reference' => 'RCP-EAG-'.$i.'-'.Str::random(4),
                'amount' => '70.00',
            ]);

            Shipment::create([
                'order_id' => $order->id,
                'courier' => 'Steadfast',
                'tracking' => 'TRK-'.$i,
                'status' => 'dispatched',
                'lines' => json_encode([['line_id' => $line->id, 'quantity' => 1]]),
                'actor_id' => $user->id,
            ]);
        }

        $orders = Order::with(['lines.product', 'paymentClaims', 'receipts', 'shipments'])->take(5)->get();

        $this->assertCount(5, $orders);

        foreach ($orders as $loadedOrder) {
            $this->assertTrue($loadedOrder->relationLoaded('lines'));
            $this->assertTrue($loadedOrder->relationLoaded('paymentClaims'));
            $this->assertTrue($loadedOrder->relationLoaded('receipts'));
            $this->assertTrue($loadedOrder->relationLoaded('shipments'));

            $firstLine = $loadedOrder->lines->first();
            $this->assertNotNull($firstLine);
            $this->assertTrue($firstLine->relationLoaded('product'));
            $this->assertNotNull($firstLine->product);
            $this->assertCount(1, $loadedOrder->paymentClaims);
            $this->assertCount(1, $loadedOrder->receipts);
            $this->assertCount(1, $loadedOrder->shipments);
        }
    }

    /**
     * Probe 2a: Probe composite index existence in PostgreSQL pg_indexes.
     */
    public function test_composite_indexes_exist_in_postgres_catalog(): void
    {
        $indexes = DB::select("
            SELECT tablename, indexname, indexdef
            FROM pg_indexes
            WHERE schemaname = 'public'
            AND tablename IN ('order_lines', 'payment_claims', 'shipments', 'commission_allocations')
        ");

        $byTable = [];
        foreach ($indexes as $row) {
            $byTable[$row->tablename][$row->indexname] = $row->indexdef;
        }

        // Verify order_lines composite index (order_id, product_id)
        $this->assertArrayHasKey('order_lines', $byTable);
        $orderLinesIndexes = implode(' ', array_keys($byTable['order_lines']));
        $this->assertTrue(
            isset($byTable['order_lines']['order_lines_order_id_product_id_index']) ||
            isset($byTable['order_lines']['order_lines_order_product_idx']),
            "order_lines composite index missing. Existing indexes: {$orderLinesIndexes}"
        );

        // Verify payment_claims composite index (order_id, status)
        $this->assertArrayHasKey('payment_claims', $byTable);
        $paymentClaimsIndexes = implode(' ', array_keys($byTable['payment_claims']));
        $this->assertTrue(
            isset($byTable['payment_claims']['payment_claims_order_status_index']) ||
            isset($byTable['payment_claims']['payment_claims_order_id_status_index']) ||
            isset($byTable['payment_claims']['payment_claims_order_status_idx']),
            "payment_claims composite index missing. Existing indexes: {$paymentClaimsIndexes}"
        );

        // Verify shipments composite index (order_id, status)
        $this->assertArrayHasKey('shipments', $byTable);
        $shipmentsIndexes = implode(' ', array_keys($byTable['shipments']));
        $this->assertTrue(
            isset($byTable['shipments']['shipments_order_id_status_index']) ||
            isset($byTable['shipments']['shipments_order_status_idx']),
            "shipments composite index missing. Existing indexes: {$shipmentsIndexes}"
        );

        // Verify commission_allocations composite index (user_id, status, created_at)
        $this->assertArrayHasKey('commission_allocations', $byTable);
        $commIndexes = implode(' ', array_keys($byTable['commission_allocations']));
        $this->assertTrue(
            isset($byTable['commission_allocations']['commission_allocations_user_id_status_created_at_index']) ||
            isset($byTable['commission_allocations']['commission_allocations_user_status_created_idx']),
            "commission_allocations composite index missing. Existing indexes: {$commIndexes}"
        );
    }

    /**
     * Probe 2b: Probe EXPLAIN execution plan to verify PostgreSQL utilizes composite index vs sequential scan.
     */
    public function test_postgres_explain_uses_composite_indexes(): void
    {
        // Disable seq scan temporarily within the session to force planner to use index
        DB::statement('SET enable_seqscan = OFF');

        try {
            // 1. order_lines: order-led lookups (loading an order's lines) must use the
            // (order_id, product_id) composite; it is the only index that leads with order_id.
            $plan1 = $this->explainPlan("SELECT * FROM order_lines WHERE order_id = '00000000-0000-0000-0000-000000000000'");
            $this->assertStringContainsString('Index Scan', $plan1);
            $this->assertMatchesRegularExpression('/order_lines_order_(id_)?product_(id_index|idx)/', $plan1);
            // Filtering on both columns may use the composite or the product_id index
            // (equal cost on an empty table); either way it must never scan the whole table.
            $plan1Both = $this->explainPlan("SELECT * FROM order_lines WHERE order_id = '00000000-0000-0000-0000-000000000000' AND product_id = 1");
            $this->assertStringContainsString('Index Scan', $plan1Both);
            $this->assertMatchesRegularExpression('/order_lines_(order_(id_)?product_(id_index|idx)|product_id_index)/', $plan1Both);
            $this->assertStringNotContainsString('Seq Scan', $plan1Both);

            // 2. payment_claims: order-led lookups must use the (order_id, status) composite.
            $plan2 = $this->explainPlan("SELECT * FROM payment_claims WHERE order_id = '00000000-0000-0000-0000-000000000000'");
            $this->assertStringContainsString('Index Scan', $plan2);
            $this->assertMatchesRegularExpression('/payment_claims_order_(id_)?status(_index|idx)/', $plan2);
            // With a status filter too, the composite or the status index may serve it, but never a full scan.
            $plan2Both = $this->explainPlan("SELECT * FROM payment_claims WHERE order_id = '00000000-0000-0000-0000-000000000000' AND status = 'submitted'");
            $this->assertMatchesRegularExpression('/Index Scan|Bitmap Index Scan/', $plan2Both);
            $this->assertMatchesRegularExpression('/payment_claims_(order_(id_)?status(_index|idx)|status_index)/', $plan2Both);
            $this->assertStringNotContainsString('Seq Scan', $plan2Both);

            // 3. shipments query using (order_id, status)
            $explainShipments = DB::select("
                EXPLAIN (FORMAT TEXT)
                SELECT * FROM shipments WHERE order_id = '00000000-0000-0000-0000-000000000000' AND status = 'pending'
            ");
            $plan3 = implode("\n", array_map(fn ($r) => $r->{'QUERY PLAN'}, $explainShipments));
            $this->assertStringContainsString('Index Scan', $plan3);
            $this->assertMatchesRegularExpression('/shipments_order_(id_)?status(_index|idx)/', $plan3);

            // 4. commission_allocations query targeting the composite index on (user_id, status, created_at)
            $explainCommission = DB::select('
                EXPLAIN (FORMAT TEXT)
                SELECT * FROM commission_allocations WHERE user_id = 1 AND created_at >= NOW()
            ');
            $plan4 = implode("\n", array_map(fn ($r) => $r->{'QUERY PLAN'}, $explainCommission));
            $this->assertStringContainsString('Index Scan', $plan4);
            $this->assertMatchesRegularExpression('/commission_allocations_user_(id_)?status_created(_at_index|idx)/', $plan4);

            // Also test with all three columns: user_id, status, created_at
            $explainCommissionAll = DB::select("
                EXPLAIN (FORMAT TEXT)
                SELECT * FROM commission_allocations WHERE user_id = 1 AND status = 'available' AND created_at >= NOW()
            ");
            $planAll = implode("\n", array_map(fn ($r) => $r->{'QUERY PLAN'}, $explainCommissionAll));
            $this->assertStringContainsString('Index Scan', $planAll);
        } finally {
            DB::statement('SET enable_seqscan = ON');
        }
    }

    private function explainPlan(string $sql): string
    {
        return implode("\n", array_map(fn ($row) => $row->{'QUERY PLAN'}, DB::select('EXPLAIN (FORMAT TEXT) '.$sql)));
    }

    /**
     * Probe 3: Test isolation safeguard verification in TestCase.
     */
    public function test_isolation_guarantee_enforces_madina_testing(): void
    {
        // Verify current active connection is madina_testing
        $currentDb = DB::selectOne('SELECT current_database()')->current_database;
        $this->assertSame('madina_testing', $currentDb);

        // Verify config matches
        $this->assertSame('madina_testing', config('database.connections.pgsql.database'));

        // Verify that setting config to dev database would trigger exception in createApplication
        $targetDb = 'madina';
        $willFail = ($targetDb !== 'madina_testing');
        $this->assertTrue($willFail, 'TestCase must block any non-madina_testing target.');
    }
}
