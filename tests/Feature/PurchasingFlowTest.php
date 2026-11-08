<?php

namespace Tests\Feature;

use App\Models\BranchProductStock;
use App\Models\CompanyBranch;
use App\Models\Product;
use App\Models\PurchaseOrder;
use App\Models\PurchaseOrderLine;
use App\Models\Supplier;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Testing\TestResponse;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\TestCase;

class PurchasingFlowTest extends TestCase
{
    use RefreshDatabase;

    private CompanyBranch $branch;

    private User $storekeeper;

    protected function setUp(): void
    {
        parent::setUp();
        $this->withoutVite();
        $this->branch = CompanyBranch::factory()->create();
        $this->storekeeper = User::factory()->staff('inventory')->create();
    }

    /**
     * @param  array<int, int>  $quantities  Quantity ordered by product id.
     */
    private function sentOrder(array $quantities, ?CompanyBranch $branch = null, string $status = PurchaseOrder::StatusSent): PurchaseOrder
    {
        $order = PurchaseOrder::query()->create([
            'supplier_id' => Supplier::factory()->create(['created_by' => $this->storekeeper->id])->id,
            'branch_id' => ($branch ?? $this->branch)->id,
            'status' => $status,
            'created_by' => $this->storekeeper->id,
        ]);
        foreach ($quantities as $productId => $quantity) {
            $order->lines()->create(['product_id' => $productId, 'quantity_ordered' => $quantity]);
        }

        return $order;
    }

    private function lineFor(PurchaseOrder $order, Product $product): PurchaseOrderLine
    {
        return PurchaseOrderLine::query()
            ->where('purchase_order_id', $order->id)
            ->where('product_id', $product->id)
            ->firstOrFail();
    }

    /**
     * @return array{purchase_order_line_id:int,quantity:int,batch_number:string,expires_on:string,unit_cost:string}
     */
    private function receiptRow(PurchaseOrderLine $line, int $quantity, string $batch = 'B-100'): array
    {
        return [
            'purchase_order_line_id' => $line->id,
            'quantity' => $quantity,
            'batch_number' => $batch,
            'expires_on' => now('Asia/Dhaka')->addYear()->toDateString(),
            'unit_cost' => '12.50',
        ];
    }

    /**
     * @param  list<array<string, mixed>>  $rows
     */
    private function receive(PurchaseOrder $order, array $rows, ?string $reference = null, ?User $actor = null): TestResponse
    {
        return $this->actingAs($actor ?? $this->storekeeper)->onPortal('manage')
            ->post("/commands/purchasing/orders/{$order->id}/receipts", [
                'reference' => $reference ?? (string) Str::uuid(),
                'lines' => $rows,
            ]);
    }

    private function branchStock(Product $product, ?CompanyBranch $branch = null): int
    {
        return (int) BranchProductStock::query()
            ->where('branch_id', ($branch ?? $this->branch)->id)
            ->where('product_id', $product->id)
            ->value('quantity');
    }

    public function test_partial_receipts_add_stock_and_move_the_order_to_received(): void
    {
        $first = Product::factory()->create(['stock' => 0]);
        $second = Product::factory()->create(['stock' => 0]);
        $order = $this->sentOrder([$first->id => 10, $second->id => 5]);
        $firstLine = $this->lineFor($order, $first);
        $secondLine = $this->lineFor($order, $second);

        $this->receive($order, [$this->receiptRow($firstLine, 4)])->assertSessionHasNoErrors();

        $this->assertSame(PurchaseOrder::StatusPartlyReceived, $order->fresh()->status);
        $this->assertSame(4, $firstLine->fresh()->quantity_received);
        $this->assertSame(6, $firstLine->fresh()->quantityDue());
        $this->assertSame(4, $this->branchStock($first));
        $this->assertSame(4, $first->fresh()->stock);

        $this->receive($order, [$this->receiptRow($firstLine, 6, 'B-101'), $this->receiptRow($secondLine, 5, 'B-102')])
            ->assertSessionHasNoErrors();

        $this->assertSame(PurchaseOrder::StatusReceived, $order->fresh()->status);
        $this->assertSame(10, $first->fresh()->stock);
        $this->assertSame(5, $second->fresh()->stock);
        $this->assertDatabaseCount('goods_receipts', 2);
        $this->assertDatabaseCount('goods_receipt_lines', 3);
        $this->assertDatabaseHas('goods_receipt_lines', [
            'product_id' => $second->id,
            'quantity' => 5,
            'batch_number' => 'B-102',
            'unit_cost' => '12.50',
        ]);
    }

    public function test_received_goods_go_through_the_stock_path_with_movement_and_audit(): void
    {
        $product = Product::factory()->create(['stock' => 0]);
        $order = $this->sentOrder([$product->id => 3]);

        $this->receive($order, [$this->receiptRow($this->lineFor($order, $product), 3)])->assertSessionHasNoErrors();

        $this->assertDatabaseHas('stock_movements', [
            'product_id' => $product->id,
            'branch_id' => $this->branch->id,
            'quantity' => 3,
            'kind' => 'product_in',
            'reason' => 'supplier_receipt: '.$order->displayNumber(),
            'actor_id' => $this->storekeeper->id,
        ]);
        $this->assertDatabaseHas('audit_events', ['action' => 'stock.adjusted', 'subject' => (string) $product->id]);
        $this->assertDatabaseHas('audit_events', ['action' => 'purchase_order.received', 'subject' => (string) $order->id]);
    }

    public function test_receiving_more_than_is_due_is_refused_and_changes_nothing(): void
    {
        $product = Product::factory()->create(['stock' => 0]);
        $order = $this->sentOrder([$product->id => 10]);
        $line = $this->lineFor($order, $product);
        $this->receive($order, [$this->receiptRow($line, 4)])->assertSessionHasNoErrors();

        $this->receive($order, [$this->receiptRow($line, 7)])->assertSessionHasErrors('lines');

        $this->assertSame(4, $line->fresh()->quantity_received);
        $this->assertSame(4, $this->branchStock($product));
        $this->assertSame(4, $product->fresh()->stock);
        $this->assertDatabaseCount('goods_receipts', 1);
        $this->assertDatabaseCount('stock_movements', 1);
    }

    public function test_batches_of_one_line_are_added_up_before_the_over_receipt_check(): void
    {
        $product = Product::factory()->create(['stock' => 0]);
        $order = $this->sentOrder([$product->id => 6]);
        $line = $this->lineFor($order, $product);

        $this->receive($order, [$this->receiptRow($line, 4, 'B-1'), $this->receiptRow($line, 3, 'B-2')])
            ->assertSessionHasErrors('lines');

        $this->assertSame(0, $line->fresh()->quantity_received);
        $this->assertDatabaseCount('stock_movements', 0);
    }

    public function test_a_line_from_another_order_is_refused(): void
    {
        $product = Product::factory()->create(['stock' => 0]);
        $order = $this->sentOrder([$product->id => 6]);
        $other = $this->sentOrder([$product->id => 6]);

        $this->receive($order, [$this->receiptRow($this->lineFor($other, $product), 1)])->assertSessionHasErrors('lines');

        $this->assertDatabaseCount('stock_movements', 0);
    }

    public function test_totals_stay_correct_when_the_same_product_arrives_at_two_branches(): void
    {
        $other = CompanyBranch::factory()->create();
        $product = Product::factory()->create(['stock' => 0]);
        $orderHere = $this->sentOrder([$product->id => 8]);
        $orderThere = $this->sentOrder([$product->id => 2], $other);

        $this->receive($orderHere, [$this->receiptRow($this->lineFor($orderHere, $product), 8)])->assertSessionHasNoErrors();
        $this->receive($orderThere, [$this->receiptRow($this->lineFor($orderThere, $product), 2)])->assertSessionHasNoErrors();

        $this->assertSame(8, $this->branchStock($product));
        $this->assertSame(2, $this->branchStock($product, $other));
        $this->assertSame(10, $product->fresh()->stock);
    }

    public function test_the_same_receipt_sent_twice_is_booked_once(): void
    {
        $product = Product::factory()->create(['stock' => 0]);
        $order = $this->sentOrder([$product->id => 10]);
        $line = $this->lineFor($order, $product);
        $reference = (string) Str::uuid();

        $this->receive($order, [$this->receiptRow($line, 4)], $reference)->assertSessionHasNoErrors();
        $this->receive($order, [$this->receiptRow($line, 4)], $reference)->assertSessionHasNoErrors();

        $this->assertSame(4, $product->fresh()->stock);
        $this->assertDatabaseCount('goods_receipts', 1);
    }

    public function test_goods_are_only_accepted_while_the_order_is_awaiting_them(): void
    {
        $product = Product::factory()->create(['stock' => 0]);
        foreach ([PurchaseOrder::StatusDraft, PurchaseOrder::StatusCancelled, PurchaseOrder::StatusReceived] as $status) {
            $order = $this->sentOrder([$product->id => 2], null, $status);

            $this->receive($order, [$this->receiptRow($this->lineFor($order, $product), 1)])->assertSessionHasErrors('order');
        }

        $this->assertSame(0, $product->fresh()->stock);
    }

    public function test_a_receipt_needs_batch_cost_and_an_expiry_that_has_not_passed(): void
    {
        $product = Product::factory()->create(['stock' => 0]);
        $order = $this->sentOrder([$product->id => 2]);
        $row = $this->receiptRow($this->lineFor($order, $product), 1);

        $this->receive($order, [[...$row, 'batch_number' => '']])->assertSessionHasErrors('lines.0.batch_number');
        $this->receive($order, [[...$row, 'unit_cost' => '']])->assertSessionHasErrors('lines.0.unit_cost');
        $this->receive($order, [[...$row, 'expires_on' => '']])->assertSessionHasErrors('lines.0.expires_on');
        $this->receive($order, [[...$row, 'expires_on' => now('Asia/Dhaka')->subDay()->toDateString()]])
            ->assertSessionHasErrors('lines.0.expires_on');

        $this->assertDatabaseCount('stock_movements', 0);
    }

    public function test_an_order_is_created_as_a_draft_then_sent(): void
    {
        $supplier = Supplier::factory()->create(['created_by' => $this->storekeeper->id]);
        $product = Product::factory()->create();

        $this->actingAs($this->storekeeper)->onPortal('manage')->post('/commands/purchasing/orders', [
            'supplier_id' => $supplier->id,
            'branch_id' => $this->branch->id,
            'lines' => [['product_id' => $product->id, 'quantity' => 9]],
        ])->assertSessionHasNoErrors();

        $order = PurchaseOrder::query()->firstOrFail();
        $this->assertSame(PurchaseOrder::StatusDraft, $order->status);

        $this->onPortal('manage')->post("/commands/purchasing/orders/{$order->id}/send")->assertSessionHasNoErrors();

        $this->assertSame(PurchaseOrder::StatusSent, $order->fresh()->status);
        $this->assertNotNull($order->fresh()->sent_at);
        $this->assertDatabaseHas('audit_events', ['action' => 'purchase_order.created', 'subject' => (string) $order->id]);
        $this->assertDatabaseHas('audit_events', ['action' => 'purchase_order.sent', 'subject' => (string) $order->id]);
    }

    public function test_an_order_cannot_list_the_same_product_twice(): void
    {
        $supplier = Supplier::factory()->create(['created_by' => $this->storekeeper->id]);
        $product = Product::factory()->create();

        $this->actingAs($this->storekeeper)->onPortal('manage')->post('/commands/purchasing/orders', [
            'supplier_id' => $supplier->id,
            'branch_id' => $this->branch->id,
            'lines' => [['product_id' => $product->id, 'quantity' => 1], ['product_id' => $product->id, 'quantity' => 2]],
        ])->assertSessionHasErrors('lines.0.product_id');

        $this->assertDatabaseCount('purchase_orders', 0);
    }

    public function test_an_order_cannot_be_raised_for_a_branch_that_holds_no_stock(): void
    {
        $office = CompanyBranch::factory()->create(['is_stock_hub' => false]);
        $supplier = Supplier::factory()->create(['created_by' => $this->storekeeper->id]);
        $product = Product::factory()->create();

        $this->actingAs($this->storekeeper)->onPortal('manage')->post('/commands/purchasing/orders', [
            'supplier_id' => $supplier->id,
            'branch_id' => $office->id,
            'lines' => [['product_id' => $product->id, 'quantity' => 1]],
        ])->assertStatus(422);

        $this->assertDatabaseCount('purchase_orders', 0);
    }

    public function test_cancelling_a_part_received_order_keeps_the_stock_and_clears_what_is_due(): void
    {
        $product = Product::factory()->create(['stock' => 0]);
        $order = $this->sentOrder([$product->id => 10]);
        $this->receive($order, [$this->receiptRow($this->lineFor($order, $product), 4)])->assertSessionHasNoErrors();

        $this->onPortal('manage')->post("/commands/purchasing/orders/{$order->id}/cancel", ['reason' => 'Supplier closed'])
            ->assertSessionHasNoErrors();

        $this->assertSame(PurchaseOrder::StatusCancelled, $order->fresh()->status);
        $this->assertSame(4, $product->fresh()->stock);
        $this->assertDatabaseHas('audit_events', ['action' => 'purchase_order.cancelled', 'subject' => (string) $order->id]);
        $this->onPortal('manage')->get('/purchasing')->assertInertia(fn (Assert $page) => $page
            ->where('data.purchasing.orders.data.0.received', 4)
            ->where('data.purchasing.orders.data.0.due', 0));
    }

    public function test_a_fully_received_order_cannot_be_cancelled(): void
    {
        $product = Product::factory()->create(['stock' => 0]);
        $order = $this->sentOrder([$product->id => 2]);
        $this->receive($order, [$this->receiptRow($this->lineFor($order, $product), 2)])->assertSessionHasNoErrors();

        $this->onPortal('manage')->post("/commands/purchasing/orders/{$order->id}/cancel", ['reason' => 'Changed mind'])
            ->assertSessionHasErrors('order');

        $this->assertSame(PurchaseOrder::StatusReceived, $order->fresh()->status);
    }

    public function test_the_page_shows_ordered_received_and_due(): void
    {
        $product = Product::factory()->create(['stock' => 0]);
        $order = $this->sentOrder([$product->id => 10]);
        $this->receive($order, [$this->receiptRow($this->lineFor($order, $product), 4)])->assertSessionHasNoErrors();

        $this->actingAs($this->storekeeper)->onPortal('manage')->get('/purchasing')
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->where('section', 'purchasing')
                ->where('data.purchasing.orders.data.0.number', $order->displayNumber())
                ->where('data.purchasing.orders.data.0.lines.0.ordered', 10)
                ->where('data.purchasing.orders.data.0.lines.0.received', 4)
                ->where('data.purchasing.orders.data.0.lines.0.due', 6)
                ->where('data.purchasing.orders.data.0.receipts.0.lines.0.batch_number', 'B-100'));
    }

    public function test_cost_is_hidden_from_staff_who_may_not_read_cost(): void
    {
        $product = Product::factory()->create(['stock' => 0]);
        $order = $this->sentOrder([$product->id => 2]);
        $this->receive($order, [$this->receiptRow($this->lineFor($order, $product), 1)])->assertSessionHasNoErrors();
        $owner = User::factory()->create(['role' => 'super_admin', 'status' => 'active']);

        $this->actingAs($this->storekeeper)->onPortal('manage')->get('/purchasing')
            ->assertInertia(fn (Assert $page) => $page
                ->where('data.purchasing.show_cost', false)
                ->missing('data.purchasing.orders.data.0.receipts.0.lines.0.unit_cost'));

        $this->actingAs($owner)->onPortal('sadmin')->get('/purchasing')
            ->assertInertia(fn (Assert $page) => $page
                ->where('data.purchasing.show_cost', true)
                ->where('data.purchasing.orders.data.0.receipts.0.lines.0.unit_cost', '12.50'));
    }

    public function test_the_order_list_follows_the_branch_being_viewed(): void
    {
        $other = CompanyBranch::factory()->create();
        $product = Product::factory()->create();
        $here = $this->sentOrder([$product->id => 1]);
        $this->sentOrder([$product->id => 1], $other);
        $owner = User::factory()->create(['role' => 'super_admin', 'status' => 'active']);
        $branchStaff = User::factory()->staff('inventory')->create(['active_branch_id' => $this->branch->id]);

        $this->actingAs($owner)->onPortal('sadmin')->get('/purchasing?branch_id='.$this->branch->id)
            ->assertInertia(fn (Assert $page) => $page
                ->has('data.purchasing.orders.data', 1)
                ->where('data.purchasing.orders.data.0.id', $here->id)
                ->where('data.purchasing.branch_scope.code', $this->branch->code));

        $this->actingAs($branchStaff)->onPortal('manage')->get('/purchasing')
            ->assertInertia(fn (Assert $page) => $page
                ->has('data.purchasing.orders.data', 1)
                ->where('data.purchasing.orders.data.0.id', $here->id));

        $this->actingAs($owner)->onPortal('sadmin')->get('/purchasing?all_branches=1')
            ->assertInertia(fn (Assert $page) => $page->has('data.purchasing.orders.data', 2));
    }

    public function test_staff_without_the_inventory_permission_are_refused(): void
    {
        $finance = User::factory()->staff('finance')->create();
        $product = Product::factory()->create(['stock' => 0]);
        $order = $this->sentOrder([$product->id => 2]);

        $this->actingAs($finance)->onPortal('manage')->get('/purchasing')->assertForbidden();
        $this->receive($order, [$this->receiptRow($this->lineFor($order, $product), 1)], null, $finance)->assertForbidden();
        $this->actingAs($finance)->onPortal('manage')->post('/commands/purchasing/suppliers', ['name' => 'X'])->assertForbidden();
        $this->actingAs($finance)->onPortal('manage')->post("/commands/purchasing/orders/{$order->id}/send")->assertForbidden();

        $this->assertSame(0, $product->fresh()->stock);
        $this->assertDatabaseCount('suppliers', 1);
    }

    public function test_view_only_inventory_staff_can_open_the_page_but_change_nothing(): void
    {
        $viewer = User::factory()->staff('inventory')->create(['profile' => ['action_permissions' => [
            'inventory' => ['view' => true, 'create' => false, 'edit' => false, 'approve' => false],
        ]]]);
        $product = Product::factory()->create(['stock' => 0]);
        $order = $this->sentOrder([$product->id => 2]);

        $this->actingAs($viewer)->onPortal('manage')->get('/purchasing')->assertOk()
            ->assertInertia(fn (Assert $page) => $page->where('data.purchasing.can.create', false));
        $this->receive($order, [$this->receiptRow($this->lineFor($order, $product), 1)], null, $viewer)->assertForbidden();
        $this->actingAs($viewer)->onPortal('manage')->post("/commands/purchasing/orders/{$order->id}/cancel", ['reason' => 'x'])->assertForbidden();

        $this->assertSame(0, $product->fresh()->stock);
        $this->assertSame(PurchaseOrder::StatusSent, $order->fresh()->status);
    }

    public function test_dealers_cannot_use_purchasing(): void
    {
        $dealer = User::factory()->create(['role' => 'dealer', 'status' => 'active']);
        $product = Product::factory()->create(['stock' => 0]);
        $order = $this->sentOrder([$product->id => 2]);

        $this->actingAs($dealer)->onPortal('dealer')
            ->post("/commands/purchasing/orders/{$order->id}/receipts", [
                'reference' => (string) Str::uuid(),
                'lines' => [$this->receiptRow($this->lineFor($order, $product), 1)],
            ])->assertForbidden();

        $this->assertSame(0, (int) DB::table('stock_movements')->count());
    }

    public function test_a_supplier_can_be_added_and_changed(): void
    {
        $this->actingAs($this->storekeeper)->onPortal('manage')->post('/commands/purchasing/suppliers', [
            'name' => 'Acme Pharma', 'contact' => '01700000000', 'notes' => 'Weekly delivery',
        ])->assertSessionHasNoErrors();

        $supplier = Supplier::query()->where('name', 'Acme Pharma')->firstOrFail();
        $this->onPortal('manage')->patch("/commands/purchasing/suppliers/{$supplier->id}", ['name' => 'Acme Pharma Ltd'])
            ->assertSessionHasNoErrors();

        $this->assertSame('Acme Pharma Ltd', $supplier->fresh()->name);
        $this->assertDatabaseHas('audit_events', ['action' => 'supplier.saved', 'subject' => (string) $supplier->id]);
    }
}
