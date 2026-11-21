<?php

namespace Tests\Feature;

use App\Domain\Finance\FinancialReadiness;
use App\Domain\Inventory\StockService;
use App\Domain\Orders\OrderReturns;
use App\Domain\Orders\OrderService;
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
 * Paid money left on a finished order (cancelled, turned down, returned or delivered) beyond the
 * goods the partner keeps: staff see it on the orders list and the money tasks, and the money
 * check fails until it goes back. Worked example: 5 units at ৳2,000, ৳10,000 paid in full.
 */
class ClosedOrderMoneyTest extends TestCase
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

    public function test_a_parcel_received_back_leaves_money_to_give_back_on_the_list_and_fails_the_check_until_it_is_refunded(): void
    {
        $order = $this->approvedOrder();
        $parcel = $this->send($order, 5);
        DB::table('shipments')->where('id', $parcel)->update(['courier_status' => 'returned']);
        app(OrderService::class)->receiveReturnedParcel($this->owner, $order->fresh(), $parcel, 'Back at the hub');
        $this->assertSame('cancelled', $order->fresh()->status);

        $this->assertFalse(app(FinancialReadiness::class)->checks()['paid_left_on_closed_orders']);
        $this->assertSame(['count' => 1, 'amount' => '10000.00'], OrderReturns::moneyToGiveBack());
        $this->assertSame(1000000, app(OrderReturns::class)->refundableCents($order->fresh()), 'The list and the order page agree.');
        $this->actingAs($this->owner)->onPortal('sadmin')->get('/orders?status='.OrderReturns::MoneyBackFilter)->assertOk()
            ->assertInertia(fn (Assert $page) => $page->where('data.money_back_count', 1)->where('data.orders.data.0.id', $order->id)->count('data.orders.data', 1));
        $this->actingAs($this->owner)->onPortal('sadmin')->get('/treasury/work')->assertOk()
            ->assertInertia(function (Assert $page): void {
                $tiles = collect($page->toArray()['props']['tiles'])->keyBy('key');
                $this->assertSame(1, $tiles['money_back']['count']);
                $this->assertSame('10000.00', $tiles['money_back']['amount']);
                $this->assertSame('/orders?status='.OrderReturns::MoneyBackFilter, $tiles['money_back']['href']);
            });

        $refund = ['kind' => 'refund', 'refund_method' => 'cash', 'reason' => 'Courier returned it', 'confirmed' => '1'];
        $this->actingAs($this->owner)->onPortal('sadmin')->post('/commands/orders/'.$order->id.'/returns/start', [...$refund, 'refund_amount' => '9000'])
            ->assertSessionHasErrors('refund_amount');
        $this->actingAs($this->owner)->onPortal('sadmin')->post('/commands/orders/'.$order->id.'/returns/start', [...$refund, 'refund_amount' => '10000'])
            ->assertSessionHasNoErrors();

        $this->assertTrue(app(FinancialReadiness::class)->checks()['paid_left_on_closed_orders']);
        $this->assertSame(['count' => 0, 'amount' => '0.00'], OrderReturns::moneyToGiveBack());
        $this->actingAs($this->owner)->onPortal('sadmin')->get('/orders?status='.OrderReturns::MoneyBackFilter)->assertOk()
            ->assertInertia(fn (Assert $page) => $page->where('data.money_back_count', 0)->count('data.orders.data', 0));
        $this->actingAs($this->owner)->onPortal('sadmin')->get('/treasury/work')->assertOk()
            ->assertInertia(fn (Assert $page) => $this->assertNotContains('money_back', array_column($page->toArray()['props']['tiles'], 'key')));
    }

    public function test_a_closed_order_from_before_the_rule_with_part_of_its_money_left_is_found(): void
    {
        $order = $this->approvedOrder();
        // An older cancel that gave back only ৳4,000 of the ৳10,000 paid.
        DB::table('order_lines')->where('order_id', $order->id)->update(['closed' => 5]);
        DB::table('orders')->where('id', $order->id)->update(['status' => 'cancelled']);
        DB::table('order_refunds')->insert(['id' => (string) Str::uuid(), 'order_id' => $order->id, 'user_id' => $order->user_id, 'kind' => 'cancel', 'amount' => '4000.00',
            'paid_before' => '10000.00', 'to_account' => '0.00', 'cash_to_account' => '0.00', 'reason' => 'Old cancel', 'refunded_by' => $this->owner->id, 'created_at' => now(), 'updated_at' => now()]);

        $this->assertSame(['count' => 1, 'amount' => '6000.00'], OrderReturns::moneyToGiveBack());
        $this->assertSame(600000, app(OrderReturns::class)->refundableCents($order->fresh()));
        $this->assertContains('paid_left_on_closed_orders', FinancialReadiness::failedNames(app(FinancialReadiness::class)->checks()));
    }

    public function test_orders_still_running_or_fully_kept_are_not_on_the_list(): void
    {
        $approved = $this->approvedOrder();
        $delivered = $this->approvedOrder();
        app(OrderService::class)->deliver($this->owner, $delivered->fresh(), $this->send($delivered, 5), 'Dealer signed');

        $this->assertSame('approved', $approved->fresh()->status);
        $this->assertSame('delivered', $delivered->fresh()->status);
        $this->assertSame(['count' => 0, 'amount' => '0.00'], OrderReturns::moneyToGiveBack());
        $this->assertTrue(app(FinancialReadiness::class)->checks()['paid_left_on_closed_orders']);
    }

    public function test_money_on_its_way_back_through_the_approval_steps_does_not_fail_the_check(): void
    {
        $order = $this->approvedOrder();
        $parcel = $this->send($order, 5);
        DB::table('shipments')->where('id', $parcel)->update(['courier_status' => 'returned']);
        app(OrderService::class)->receiveReturnedParcel($this->owner, $order->fresh(), $parcel, 'Back at the hub');
        $finance = User::factory()->staff('finance')->create();

        $this->actingAs($finance)->onPortal('manage')->post('/commands/orders/'.$order->id.'/returns/start', [
            'kind' => 'refund', 'refund_amount' => '10000', 'refund_method' => 'cash', 'reason' => 'Courier returned it', 'confirmed' => '1',
        ])->assertSessionHasNoErrors();

        $this->assertSame(OrderReturns::StatusAwaitingSecond, DB::table('order_return_requests')->where('order_id', $order->id)->value('status'));
        $this->assertSame(1, OrderReturns::moneyToGiveBack()['count'], 'Still on the list until the money moves.');
        $this->assertTrue(app(FinancialReadiness::class)->checks()['paid_left_on_closed_orders']);
    }

    private function approvedOrder(): Order
    {
        $order = app(OrderService::class)->create($this->dealer, ['items' => [['product_id' => $this->product->id, 'quantity' => 5]], 'idempotency_key' => (string) Str::uuid()]);
        $reference = 'TEST-'.Str::random(8);
        $claim = DB::table('payment_claims')->insertGetId(['order_id' => $order->id, 'channel' => 'Bank', 'reference' => $reference, 'amount' => $order->total, 'status' => 'verified', 'created_at' => now(), 'updated_at' => now()]);
        DB::table('receipts')->insert(['order_id' => $order->id, 'claim_id' => $claim, 'channel' => 'Bank', 'reference' => $reference, 'amount' => $order->total, 'created_at' => now(), 'updated_at' => now()]);
        DB::table('orders')->where('id', $order->id)->update(['paid' => $order->total]);
        app(OrderService::class)->decision($this->owner, $order->fresh(), 'approved', 'Paid in full');

        return $order->fresh();
    }

    private function send(Order $order, int $quantity): int
    {
        app(OrderService::class)->dispatch($this->owner, $order->fresh(), [
            'items' => [['line_id' => (int) $order->lines()->value('id'), 'quantity' => $quantity]],
            'idempotency_key' => (string) Str::uuid(), 'courier' => 'Test courier', 'tracking' => 'TRK-'.Str::random(6), 'branch_id' => $this->hub->id,
        ]);

        return (int) DB::table('shipments')->where('order_id', $order->id)->orderByDesc('id')->value('id');
    }
}
