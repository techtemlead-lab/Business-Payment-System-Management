<?php

namespace Tests\Feature;

use App\Domain\Inventory\StockService;
use App\Domain\Orders\OrderInvoice;
use App\Domain\Orders\OrderService;
use App\Domain\Orders\OwedItems;
use App\Domain\Orders\StaffDashboardSummary;
use App\Models\CompanyBranch;
use App\Models\Order;
use App\Models\Product;
use App\Models\ReferralTreePath;
use App\Models\User;
use Database\Seeders\CommissionRuleSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\TestCase;

/**
 * Owed goods (owner's rule, 2026-11-21): a paid line short at dispatch is owed, the source order
 * can finish, and the pieces go out free with the next order or are refunded.
 */
class OwedItemsTest extends TestCase
{
    use RefreshDatabase;

    private User $owner;

    private User $dealer;

    private CompanyBranch $hub;

    private Product $product;

    protected function setUp(): void
    {
        parent::setUp();
        $this->withoutVite();
        Http::fake();
        $this->enableFinancialPosting();
        $this->seed(CommissionRuleSeeder::class);
        $this->hub = CompanyBranch::query()->where('code', 'HO-PIRGANJ')->firstOrFail();
        $this->owner = User::factory()->staff('super_admin')->create(['active_branch_id' => $this->hub->id]);
        $sponsor = User::factory()->dealer()->create(['status' => 'active']);
        ReferralTreePath::attachUser($sponsor->id, null);
        $this->dealer = User::factory()->dealer()->create(['status' => 'active', 'sponsor_id' => $sponsor->id]);
        ReferralTreePath::attachUser($this->dealer->id, $sponsor->id);
        $this->product = Product::factory()->create(['active' => true, 'mrp' => '3000.00', 'dealer_price' => '2000.00', 'stock' => 0]);
        app(StockService::class)->stockIn($this->owner, ['product_id' => $this->product->id, 'quantity' => 100, 'reference' => (string) Str::uuid(),
            'branch_id' => $this->hub->id, 'batch_number' => 'B1', 'expires_on' => now()->addYear()->toDateString()]);
    }

    public function test_a_short_paid_line_is_owed_once_and_the_money_stays(): void
    {
        $order = $this->paidOrder(5);
        $this->send($order, 3);
        $lineId = (int) $order->lines()->value('id');

        foreach ([1, 2] as $click) {
            $this->actingAs($this->owner)->onPortal('sadmin')->post('/commands/orders/'.$order->id.'/owed', ['line_id' => $lineId, 'note' => 'Stock short at dispatch'])
                ->assertSessionHasNoErrors()->assertRedirect();
        }

        $this->assertSame(1, DB::table('owed_items')->count());
        $this->assertDatabaseHas('owed_items', ['source_order_id' => $order->id, 'source_line_id' => $lineId, 'quantity' => 2, 'status' => 'open', 'partner_id' => $this->dealer->id]);
        $this->assertSame(2, (int) $order->lines()->value('closed'));
        $this->assertSame('10000.00', (string) $order->fresh()->paid, 'Nothing is refunded when goods are owed.');
        $this->assertDatabaseCount('order_refunds', 0);
        $this->assertSame('dispatched', $order->fresh()->status, 'Nothing is left to send on the source order.');
        $this->assertDatabaseHas('audit_events', ['action' => 'order.owed_recorded', 'subject' => $order->id]);
        $queue = collect(app(StaffDashboardSummary::class)->for($this->owner)['work_queue'])->keyBy('key');
        $this->assertSame(1, $queue['owed_items_open']['count']);
    }

    public function test_an_unpaid_order_cannot_owe_goods(): void
    {
        $order = $this->paidOrder(5);
        $this->send($order, 3);
        DB::table('orders')->where('id', $order->id)->update(['paid' => '1000.00']);

        $this->actingAs($this->owner)->onPortal('sadmin')->post('/commands/orders/'.$order->id.'/owed', ['line_id' => (int) $order->lines()->value('id'), 'note' => 'Short'])
            ->assertSessionHasErrors('line');
        $this->assertDatabaseCount('owed_items', 0);
    }

    public function test_the_partner_sees_what_the_company_owes(): void
    {
        $order = $this->owedOrder();

        $this->actingAs($this->dealer)->onPortal('dealer')->get('/orders/'.$order->id)->assertOk()
            ->assertInertia(fn (Assert $page) => $page->has('owedItems', 1)->where('owedItems.0.quantity', 2)->where('owedItems.0.source_number', $order->number));
    }

    public function test_staff_see_open_owed_goods_on_the_deliveries_screen(): void
    {
        $this->owedOrder();

        $this->actingAs($this->owner)->onPortal('sadmin')->get('/deliveries')->assertOk()
            ->assertInertia(fn (Assert $page) => $page->has('data.owed_items', 1)->where('data.owed_items.0.quantity', 2));
    }

    public function test_owed_goods_go_with_the_next_order_once_and_stock_drops_once(): void
    {
        $source = $this->owedOrder();
        $next = $this->paidOrder(1);
        $before = $this->stock();

        foreach ([1, 2] as $click) {
            $this->actingAs($this->owner)->onPortal('sadmin')->post('/commands/orders/'.$next->id.'/owed/ship')->assertSessionHasNoErrors();
        }

        $freeLine = $next->lines()->where('is_free', true)->first();
        $this->assertNotNull($freeLine);
        $this->assertSame(2, (int) $freeLine->quantity);
        $this->assertSame('0.00', (string) $freeLine->unit_price);
        $this->assertSame(1, $next->lines()->where('is_free', true)->count());
        $this->assertDatabaseHas('owed_items', ['source_order_id' => $source->id, 'status' => 'shipped', 'shipped_in_order_id' => $next->id, 'shipped_line_id' => $freeLine->id]);
        $this->assertSame((string) $next->total, (string) $next->fresh()->total, 'Owed goods add nothing to the price.');

        app(OrderService::class)->dispatch($this->owner, $next->fresh(), [
            'items' => $next->lines()->get()->map(fn ($line): array => ['line_id' => (int) $line->id, 'quantity' => (int) $line->quantity])->all(),
            'idempotency_key' => (string) Str::uuid(), 'courier' => 'Test courier', 'tracking' => 'TRK-'.Str::random(6), 'branch_id' => $this->hub->id,
        ]);
        $this->assertSame($before - 3, $this->stock());

        $invoiceLine = collect(app(OrderInvoice::class)->payload($next->fresh(), 'none')['lines'])->firstWhere('owed_note', '!=', null);
        $this->assertNotNull($invoiceLine);
        $this->assertSame('0.00', $invoiceLine['total']);
        $this->assertStringContainsString($source->number, (string) $invoiceLine['owed_note']);
    }

    public function test_owed_goods_are_owed_again_when_the_next_order_is_cancelled(): void
    {
        $this->owedOrder();
        $next = app(OrderService::class)->create($this->dealer, ['items' => [['product_id' => $this->product->id, 'quantity' => 1]], 'idempotency_key' => (string) Str::uuid()]);
        app(OwedItems::class)->shipWith($this->owner, $next);
        $this->assertSame([], app(OwedItems::class)->open($this->dealer->id));

        $this->actingAs($this->dealer)->onPortal('dealer')->post('/commands/orders/'.$next->id.'/returns', ['kind' => 'cancel', 'reason' => 'Ordered by mistake'])
            ->assertSessionHasNoErrors();

        $this->assertSame('cancelled', $next->fresh()->status);
        $this->assertCount(1, app(OwedItems::class)->open($this->dealer->id));
        $third = $this->paidOrder(1);
        $this->assertSame(1, app(OwedItems::class)->shipWith($this->owner, $third));
        $this->assertDatabaseHas('owed_items', ['status' => 'shipped', 'shipped_in_order_id' => $third->id]);
    }

    public function test_staff_can_refund_an_owed_item_instead(): void
    {
        $order = $this->owedOrder();
        $owedId = (int) DB::table('owed_items')->value('id');

        $this->actingAs($this->owner)->onPortal('sadmin')->post('/commands/owed-items/'.$owedId.'/refund', ['reason' => 'Partner wants money back'])
            ->assertSessionHasNoErrors();

        $this->assertDatabaseHas('owed_items', ['id' => $owedId, 'status' => 'cancelled', 'closed_by' => $this->owner->id]);
        $this->assertTrue(DB::table('order_return_requests')->where('order_id', $order->id)->exists() || DB::table('order_refunds')->where('order_id', $order->id)->exists());
        $this->assertSame([], app(OwedItems::class)->open($this->dealer->id));
    }

    private function owedOrder(): Order
    {
        $order = $this->paidOrder(5);
        $this->send($order, 3);
        app(OwedItems::class)->record($this->owner, $order->fresh(), (int) $order->lines()->value('id'), 'Stock short');

        return $order->fresh();
    }

    private function paidOrder(int $quantity): Order
    {
        $order = app(OrderService::class)->create($this->dealer, ['items' => [['product_id' => $this->product->id, 'quantity' => $quantity]], 'idempotency_key' => (string) Str::uuid()]);
        $reference = 'TEST-'.Str::random(8);
        $claim = DB::table('payment_claims')->insertGetId(['order_id' => $order->id, 'channel' => 'Bank', 'reference' => $reference, 'amount' => $order->total, 'status' => 'verified', 'created_at' => now(), 'updated_at' => now()]);
        DB::table('receipts')->insert(['order_id' => $order->id, 'claim_id' => $claim, 'channel' => 'Bank', 'reference' => $reference, 'amount' => $order->total, 'created_at' => now(), 'updated_at' => now()]);
        DB::table('orders')->where('id', $order->id)->update(['paid' => $order->total]);
        app(OrderService::class)->decision($this->owner, $order->fresh(), 'approved', 'Paid in full');

        return $order->fresh();
    }

    private function send(Order $order, int $quantity): void
    {
        app(OrderService::class)->dispatch($this->owner, $order->fresh(), [
            'items' => [['line_id' => (int) $order->lines()->value('id'), 'quantity' => $quantity]],
            'idempotency_key' => (string) Str::uuid(), 'courier' => 'Test courier', 'tracking' => 'TRK-'.Str::random(6), 'branch_id' => $this->hub->id,
        ]);
    }

    private function stock(): int
    {
        return (int) DB::table('branch_product_stocks')->where('branch_id', $this->hub->id)->where('product_id', $this->product->id)->value('quantity');
    }
}
