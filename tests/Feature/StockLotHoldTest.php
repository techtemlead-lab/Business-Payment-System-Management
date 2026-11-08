<?php

namespace Tests\Feature;

use App\Domain\Inventory\BranchOperations;
use App\Domain\Inventory\StockLotHolds;
use App\Domain\Inventory\StockLots;
use App\Domain\Inventory\StockService;
use App\Domain\Inventory\StockTransfers;
use App\Domain\Orders\OrderService;
use App\Models\BranchProductStock;
use App\Models\CompanyBranch;
use App\Models\Order;
use App\Models\Product;
use App\Models\StockLot;
use App\Models\StockTransfer;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Inertia\Testing\AssertableInertia as Assert;
use Symfony\Component\HttpKernel\Exception\HttpException;
use Tests\TestCase;

class StockLotHoldTest extends TestCase
{
    use RefreshDatabase;

    private CompanyBranch $hub;

    private CompanyBranch $otherBranch;

    private User $owner;

    private User $dealer;

    protected function setUp(): void
    {
        parent::setUp();
        $this->withoutVite();
        $this->hub = CompanyBranch::query()->where('code', 'HO-PIRGANJ')->firstOrFail();
        $this->otherBranch = CompanyBranch::query()->create(['code' => 'BR-TWO', 'name' => 'Branch Two', 'is_active' => true, 'is_stock_hub' => true, 'is_delivery_point' => true]);
        $this->owner = User::factory()->staff('super_admin')->create(['active_branch_id' => $this->hub->id]);
        $this->dealer = User::factory()->dealer()->create(['status' => 'active']);
    }

    public function test_a_held_lot_cannot_be_dispatched_and_the_message_says_why(): void
    {
        $product = $this->product();
        $lot = $this->stockIn($product, 5, 'SUSPECT', 60);
        $order = $this->approvedOrder($product, 5);
        $this->hold($lot);

        try {
            $this->dispatchOrder($order, 5);
            $this->fail('A held lot must not be sent.');
        } catch (ValidationException $exception) {
            $this->assertSame(__('stock_lots.err_on_hold_refused', ['product' => $product->name, 'held' => 5, 'batches' => 'SUSPECT', 'usable' => 0]), $exception->errors()['shipment'][0]);
        }

        $this->assertSame(5, $lot->refresh()->quantity);
        $this->assertDatabaseCount('shipments', 0);
    }

    public function test_dispatch_skips_a_held_lot_and_uses_the_next_good_one(): void
    {
        $product = $this->product();
        $soonest = $this->stockIn($product, 5, 'SOONEST', 30);
        $later = $this->stockIn($product, 5, 'LATER', 120);
        $order = $this->approvedOrder($product, 3);
        $this->hold($soonest);

        $this->dispatchOrder($order, 3);

        $this->assertSame(5, $soonest->refresh()->quantity);
        $this->assertSame(2, $later->refresh()->quantity);
    }

    public function test_held_stock_is_not_usable_and_is_not_named_as_the_next_batch(): void
    {
        $product = $this->product();
        $held = $this->stockIn($product, 5, 'HELD', 30);
        $this->stockIn($product, 4, 'GOOD', 90);
        $this->hold($held);

        $stock = BranchProductStock::query()->where('branch_id', $this->hub->id)->where('product_id', $product->id)->firstOrFail();
        $this->assertSame(4, app(StockLots::class)->usableQuantity($stock, $product->fresh()));
        $this->assertSame('GOOD', app(StockLots::class)->nextBatchSummary($product->fresh())['batch_number']);
    }

    public function test_a_held_lot_cannot_be_moved_to_another_branch_or_taken_by_an_ordinary_stock_out(): void
    {
        $product = $this->product();
        $this->hold($this->stockIn($product, 5, 'HELD', 60));
        $transfer = app(StockTransfers::class)->request($this->owner, ['product_id' => $product->id, 'quantity' => 2, 'source_branch_id' => $this->hub->id, 'destination_branch_id' => $this->otherBranch->id]);

        try {
            app(StockTransfers::class)->send($this->owner, $transfer);
            $this->fail('A held lot must not leave the branch.');
        } catch (ValidationException) {
            $this->assertSame(StockTransfer::StatusRequested, $transfer->fresh()->status);
        }

        $this->expectException(ValidationException::class);
        $this->adjust($product, 1, 'unofficial_out');
    }

    public function test_a_general_write_off_uses_other_stock_before_touching_a_held_lot(): void
    {
        $product = $this->product();
        $held = $this->stockIn($product, 5, 'HELD', 30);
        $other = $this->stockIn($product, 5, 'OTHER', 90);
        $this->hold($held);

        $this->adjust($product, 3, 'write_off');

        $this->assertSame(5, $held->refresh()->quantity);
        $this->assertSame(2, $other->refresh()->quantity);
    }

    public function test_releasing_a_lot_makes_it_available_again_and_is_audited(): void
    {
        $product = $this->product();
        $lot = $this->stockIn($product, 5, 'CLEARED', 60);
        $order = $this->approvedOrder($product, 5);
        $this->hold($lot, 'Label looks wrong');

        $this->actingAs($this->owner)->onPortal('sadmin')
            ->post('/commands/stock-lots/'.$lot->id.'/release', ['reason' => 'Checked with the maker'])
            ->assertRedirect()->assertSessionHasNoErrors();

        $this->assertNull($lot->refresh()->held_at);
        $this->assertNull($lot->hold_reason);
        $event = DB::table('audit_events')->where('action', 'stock.lot_released')->where('subject', (string) $lot->id)->first();
        $this->assertNotNull($event);
        $this->assertStringContainsString('Checked with the maker', (string) $event->metadata);
        $this->assertStringContainsString('Label looks wrong', (string) $event->metadata);

        $this->dispatchOrder($order->fresh(), 5);
        $this->assertSame(0, $lot->refresh()->quantity);
    }

    public function test_writing_off_a_held_lot_removes_only_that_lot_and_keeps_the_books_straight(): void
    {
        $product = $this->product();
        $lot = $this->stockIn($product, 5, 'BAD', 30);
        $good = $this->stockIn($product, 7, 'GOOD', 90);
        $this->hold($lot);

        $this->actingAs($this->owner)->onPortal('sadmin')
            ->post('/commands/stock-lots/'.$lot->id.'/write-off', ['reason' => 'Damaged by water'])
            ->assertRedirect()->assertSessionHasNoErrors();

        $this->assertSame(0, $lot->refresh()->quantity);
        $this->assertSame(7, $good->refresh()->quantity);
        $this->assertSame(7, $product->fresh()->stock);
        $this->assertSame(7, (int) BranchProductStock::query()->where('branch_id', $this->hub->id)->where('product_id', $product->id)->value('quantity'));
        $this->assertDatabaseHas('stock_lot_movements', ['lot_id' => $lot->id, 'quantity' => -5]);
        $this->assertDatabaseHas('audit_events', ['action' => 'stock.lot_written_off', 'subject' => (string) $lot->id]);
        $this->assertDatabaseHas('audit_events', ['action' => 'stock.adjusted', 'subject' => (string) $product->id]);
        $this->assertTrue(DB::table('stock_movements')->where('product_id', $product->id)->where('quantity', -5)->where('reason', 'like', 'write_off%')->exists());
    }

    public function test_only_a_held_lot_can_be_written_off_or_released_and_a_lot_cannot_be_held_twice(): void
    {
        $lot = $this->stockIn($this->product(), 5, 'PLAIN', 60);
        $client = $this->actingAs($this->owner)->onPortal('sadmin');

        $client->post('/commands/stock-lots/'.$lot->id.'/write-off', ['reason' => 'Should be refused'])->assertSessionHasErrors('lot');
        $client->post('/commands/stock-lots/'.$lot->id.'/release', ['reason' => 'Should be refused'])->assertSessionHasErrors('lot');
        $this->assertSame(5, $lot->refresh()->quantity);

        $this->hold($lot);
        $client->post('/commands/stock-lots/'.$lot->id.'/hold', ['reason' => 'Held a second time'])->assertSessionHasErrors('lot');
    }

    public function test_a_reason_is_required(): void
    {
        $lot = $this->stockIn($this->product(), 5, 'PLAIN', 60);

        $this->actingAs($this->owner)->onPortal('sadmin')
            ->post('/commands/stock-lots/'.$lot->id.'/hold', ['reason' => ''])
            ->assertSessionHasErrors('reason');

        $this->assertNull($lot->refresh()->held_at);
    }

    public function test_staff_without_stock_rights_and_partners_are_refused(): void
    {
        $lot = $this->stockIn($this->product(), 5, 'PLAIN', 60);
        $accountsStaff = User::factory()->staff('finance')->create(['active_branch_id' => $this->hub->id]);

        $this->assertFalse($accountsStaff->allows('inventory'));
        $this->actingAs($accountsStaff)->onPortal('manage')
            ->post('/commands/stock-lots/'.$lot->id.'/hold', ['reason' => 'Not my job at all'])
            ->assertForbidden();
        $this->expectException(HttpException::class);
        app(StockLotHolds::class)->hold($this->dealer, $lot, 'A partner cannot do this');
    }

    public function test_the_hold_tab_lists_held_lots_and_searches_for_lots_to_hold_at_the_chosen_branch(): void
    {
        $product = Product::factory()->create(['stock' => 0, 'name' => 'Hold Search Syrup']);
        $held = $this->stockIn($product, 5, 'ONHOLD', 60);
        $this->stockIn($product, 3, 'FINDME', 60);
        $this->stockIn(Product::factory()->create(['stock' => 0, 'name' => 'Other Balm']), 3, 'NOTME', 60);
        $this->hold($held, 'Label looks wrong');

        $this->actingAs($this->owner)->onPortal('sadmin')->get('/stock-lots?tab=hold&all_branches=1&q=FIND')
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->where('data.stock_lots.tab', 'hold')
                ->count('data.stock_lots.held', 1)
                ->where('data.stock_lots.held.0.batch_number', 'ONHOLD')
                ->where('data.stock_lots.held.0.hold_reason', 'Label looks wrong')
                ->where('data.stock_lots.held.0.held_by', $this->owner->name)
                ->count('data.stock_lots.hold_candidates', 1)
                ->where('data.stock_lots.hold_candidates.0.batch_number', 'FINDME'));

        $this->actingAs($this->owner)->onPortal('sadmin')->get('/stock-lots?tab=hold&all_branches=1&q=Hold%20Search')
            ->assertInertia(fn (Assert $page) => $page->count('data.stock_lots.hold_candidates', 1)->where('data.stock_lots.hold_candidates.0.on_hold', false));
    }

    private function product(): Product
    {
        return Product::factory()->create(['stock' => 0, 'low_stock_at' => 1]);
    }

    private function stockIn(Product $product, int $quantity, string $batch, int $days): StockLot
    {
        return app(StockService::class)->stockIn($this->owner, [
            'product_id' => $product->id, 'quantity' => $quantity, 'reference' => (string) Str::uuid(),
            'branch_id' => $this->hub->id, 'batch_number' => $batch, 'expires_on' => now()->addDays($days)->toDateString(),
        ]);
    }

    private function hold(StockLot $lot, string $reason = 'Checking this batch'): void
    {
        app(StockLotHolds::class)->hold($this->owner, $lot, $reason);
    }

    private function adjust(Product $product, int $quantity, string $reasonCode): void
    {
        app(StockService::class)->adjust($this->owner, [
            'product_id' => $product->id, 'quantity' => $quantity, 'kind' => BranchOperations::KindProductOut, 'reason_code' => $reasonCode,
            'reason' => $reasonCode, 'reference' => (string) Str::uuid(), 'branch_id' => $this->hub->id,
        ]);
    }

    private function approvedOrder(Product $product, int $quantity): Order
    {
        $order = app(OrderService::class)->create($this->dealer, ['items' => [['product_id' => $product->id, 'quantity' => $quantity]], 'idempotency_key' => (string) Str::uuid()]);
        DB::table('orders')->where('id', $order->id)->update(['paid' => $order->total]);
        app(OrderService::class)->decision($this->owner, $order->fresh(), 'approved', 'Paid in full');

        return $order->fresh();
    }

    private function dispatchOrder(Order $order, int $quantity): void
    {
        app(OrderService::class)->dispatch($this->owner, $order, [
            'items' => [['line_id' => (int) $order->lines()->value('id'), 'quantity' => $quantity]],
            'idempotency_key' => (string) Str::uuid(), 'courier' => 'Test courier', 'tracking' => 'TRK-1', 'branch_id' => $this->hub->id,
        ]);
    }
}
