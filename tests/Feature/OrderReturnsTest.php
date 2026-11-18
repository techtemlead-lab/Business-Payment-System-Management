<?php

namespace Tests\Feature;

use App\Domain\Finance\FinancialReadiness;
use App\Domain\Inventory\StockService;
use App\Domain\Orders\OrderReturns;
use App\Domain\Orders\OrderService;
use App\Models\BranchStaffAssignment;
use App\Models\CompanyBranch;
use App\Models\Order;
use App\Models\Product;
use App\Models\ReferralTreePath;
use App\Models\User;
use App\Support\Money;
use Database\Seeders\CommissionRuleSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\TestCase;

/**
 * Cancel, return and refund (OS-01, OS-02, MA-16). Worked example: 5 units at ৳2,000 (MRP
 * ৳3,000), ৳10,000 paid in full.
 */
class OrderReturnsTest extends TestCase
{
    use RefreshDatabase;

    private User $owner;

    private User $finance;

    private User $secondFinance;

    private User $sponsor;

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
        $this->finance = User::factory()->staff('finance')->create(['name' => 'First Finance']);
        $this->secondFinance = User::factory()->staff('finance')->create(['name' => 'Second Finance']);
        $this->sponsor = User::factory()->dealer()->create(['status' => 'active']);
        ReferralTreePath::attachUser($this->sponsor->id, null);
        $this->dealer = User::factory()->dealer()->create(['status' => 'active', 'sponsor_id' => $this->sponsor->id]);
        ReferralTreePath::attachUser($this->dealer->id, $this->sponsor->id);
        $this->product = Product::factory()->create(['active' => true, 'mrp' => '3000.00', 'dealer_price' => '2000.00', 'stock' => 0]);
        app(StockService::class)->stockIn($this->owner, ['product_id' => $this->product->id, 'quantity' => 100, 'reference' => (string) Str::uuid(),
            'branch_id' => $this->hub->id, 'batch_number' => 'B1', 'expires_on' => now()->addYear()->toDateString()]);
    }

    public function test_a_partner_cancels_an_unpaid_order_at_once_and_it_stays_visible(): void
    {
        $order = app(OrderService::class)->create($this->dealer, ['items' => [['product_id' => $this->product->id, 'quantity' => 5]], 'idempotency_key' => (string) Str::uuid()]);

        $this->actingAs($this->dealer)->onPortal('dealer')->post('/commands/orders/'.$order->id.'/returns', ['kind' => 'cancel', 'reason' => 'Ordered by mistake'])
            ->assertSessionHasNoErrors();

        $this->assertSame('cancelled', $order->fresh()->status);
        $this->assertSame(5, (int) $order->lines()->value('closed'));
        $this->assertDatabaseHas('order_events', ['order_id' => $order->id, 'action' => OrderReturns::ActionPartnerCancelled]);
        $this->assertDatabaseCount('order_refunds', 0);
        $this->actingAs($this->dealer)->onPortal('dealer')->get('/orders/'.$order->id)->assertOk();
    }

    public function test_a_partner_cannot_cancel_someone_elses_order(): void
    {
        $order = app(OrderService::class)->create($this->dealer, ['items' => [['product_id' => $this->product->id, 'quantity' => 1]], 'idempotency_key' => (string) Str::uuid()]);

        $this->actingAs($this->sponsor)->onPortal('dealer')->post('/commands/orders/'.$order->id.'/returns', ['kind' => 'cancel', 'reason' => 'Not mine'])->assertNotFound();
        $this->assertSame('submitted', $order->fresh()->status);
    }

    public function test_a_paid_order_cancel_needs_two_people_when_finance_approves_and_money_moves_once(): void
    {
        $order = $this->approvedOrder();
        $this->actingAs($this->dealer)->onPortal('dealer')->post('/commands/orders/'.$order->id.'/returns', ['kind' => 'cancel', 'reason' => 'No longer needed'])
            ->assertSessionHasNoErrors();
        $request = DB::table('order_return_requests')->where('order_id', $order->id)->first();
        $this->assertSame('requested', $request->status);
        $this->assertSame('approved', $order->fresh()->status, 'Nothing moves on the partner\'s word.');

        $this->actingAs($this->finance)->onPortal('manage')->post('/commands/order-returns/'.$request->id.'/approve', [
            'refund_amount' => '10000.00', 'refund_method' => 'cash', 'reason' => 'Stock ran out', 'confirmed' => '1',
        ])->assertSessionHasNoErrors();
        $this->assertSame('awaiting_second', DB::table('order_return_requests')->where('id', $request->id)->value('status'));
        $this->assertDatabaseCount('order_refunds', 0);

        $this->actingAs($this->finance)->onPortal('manage')->post('/commands/order-returns/'.$request->id.'/confirm', ['reason' => 'Me again', 'confirmed' => '1'])
            ->assertSessionHasErrors('request');
        $this->assertDatabaseCount('order_refunds', 0);

        $this->actingAs($this->secondFinance)->onPortal('manage')->post('/commands/order-returns/'.$request->id.'/confirm', ['reason' => 'Checked', 'confirmed' => '1'])
            ->assertSessionHasNoErrors();
        $this->assertDatabaseHas('order_refunds', ['order_id' => $order->id, 'amount' => '10000.00', 'to_account' => '0.00', 'cash_to_account' => '0.00',
            'refunded_by' => $this->finance->id, 'approved_by' => $this->secondFinance->id, 'kind' => 'cancel', 'request_id' => $request->id]);
        $this->assertSame('cancelled', $order->fresh()->status);
        $this->assertSame(5, (int) $order->lines()->value('closed'));
        $this->assertSame('done', DB::table('order_return_requests')->where('id', $request->id)->value('status'));
        $this->assertDatabaseHas('audit_events', ['action' => 'order.refunded', 'subject' => $order->id, 'actor_id' => $this->secondFinance->id]);

        $this->actingAs($this->secondFinance)->onPortal('manage')->post('/commands/order-returns/'.$request->id.'/confirm', ['reason' => 'Twice', 'confirmed' => '1'])
            ->assertSessionHasErrors('request');
        $this->assertDatabaseCount('order_refunds', 1);
    }

    public function test_the_owner_carries_out_a_refund_alone_and_the_amount_must_be_confirmed(): void
    {
        $order = $this->approvedOrder();
        $lines = [['line_id' => (int) $order->lines()->value('id'), 'quantity' => 5]];

        $this->actingAs($this->owner)->onPortal('sadmin')->post('/commands/orders/'.$order->id.'/returns/start', [
            'kind' => 'cancel', 'lines' => $lines, 'refund_amount' => '10000', 'refund_method' => 'cash', 'reason' => 'Out of stock',
        ])->assertSessionHasErrors('confirmed');
        $this->assertDatabaseCount('order_return_requests', 0);

        $this->actingAs($this->owner)->onPortal('sadmin')->post('/commands/orders/'.$order->id.'/returns/start', [
            'kind' => 'cancel', 'lines' => $lines, 'refund_amount' => '10000', 'refund_method' => 'cash', 'reason' => 'Out of stock', 'confirmed' => '1',
        ])->assertSessionHasNoErrors();

        $this->assertDatabaseHas('order_refunds', ['order_id' => $order->id, 'amount' => '10000.00', 'refunded_by' => $this->owner->id, 'approved_by' => null]);
        $this->assertSame('cancelled', $order->fresh()->status);
    }

    public function test_a_refund_never_pays_back_more_than_was_paid(): void
    {
        $order = $this->approvedOrder();
        $lines = [['line_id' => (int) $order->lines()->value('id'), 'quantity' => 5]];

        $this->actingAs($this->owner)->onPortal('sadmin')->post('/commands/orders/'.$order->id.'/returns/start', [
            'kind' => 'cancel', 'lines' => $lines, 'refund_amount' => '10000.01', 'refund_method' => 'cash', 'reason' => 'Too much', 'confirmed' => '1',
        ])->assertSessionHasErrors('refund_amount');

        $this->assertDatabaseCount('order_refunds', 0);
        $this->assertDatabaseCount('order_return_requests', 0);
        $this->assertSame('approved', $order->fresh()->status);
    }

    public function test_closing_the_rest_of_a_back_order_refunds_only_the_unsent_goods_and_posts_the_delivered_part_once(): void
    {
        $order = $this->approvedOrder();
        $parcel = $this->send($order, 3);
        app(OrderService::class)->deliver($this->owner, $order->fresh(), $parcel, 'Dealer signed');
        $this->assertSame('partially_dispatched', $order->fresh()->status);
        $this->assertDatabaseCount('commission_batches', 0);
        $stockBefore = (int) $this->product->fresh()->stock;
        $lines = [['line_id' => (int) $order->lines()->value('id'), 'quantity' => 2]];

        $this->actingAs($this->owner)->onPortal('sadmin')->post('/commands/orders/'.$order->id.'/returns/start', [
            'kind' => 'cancel', 'lines' => $lines, 'refund_amount' => '4000.01', 'refund_method' => 'cash', 'reason' => 'Close the rest', 'confirmed' => '1',
        ])->assertSessionHasErrors('refund_amount');
        $this->actingAs($this->owner)->onPortal('sadmin')->post('/commands/orders/'.$order->id.'/returns/start', [
            'kind' => 'cancel', 'lines' => $lines, 'refund_amount' => '4000', 'refund_method' => 'cash', 'reason' => 'Close the rest', 'confirmed' => '1',
        ])->assertSessionHasNoErrors();

        $this->assertSame('partly_delivered', $order->fresh()->status);
        $this->assertSame('6000.00', (string) DB::table('commission_batches')->where('order_id', $order->id)->value('cash_basis'));
        $this->assertSame('0.00', (string) DB::table('commission_batches')->where('order_id', $order->id)->value('refunded_amount'));
        $this->assertSame($stockBefore, (int) $this->product->fresh()->stock, 'Closed goods never left the shelf.');
        $this->assertDatabaseHas('order_refunds', ['order_id' => $order->id, 'amount' => '4000.00']);
        $this->assertTrue(app(FinancialReadiness::class)->booksMatch(), json_encode(app(FinancialReadiness::class)->checks()));
    }

    public function test_closing_the_rest_while_a_parcel_is_on_the_road_posts_the_delivered_part_at_the_last_delivery(): void
    {
        $order = $this->approvedOrder();
        $parcel = $this->send($order, 3);
        $lines = [['line_id' => (int) $order->lines()->value('id'), 'quantity' => 2]];
        $this->actingAs($this->owner)->onPortal('sadmin')->post('/commands/orders/'.$order->id.'/returns/start', [
            'kind' => 'cancel', 'lines' => $lines, 'refund_amount' => '4000', 'refund_method' => 'cash', 'reason' => 'Close the rest', 'confirmed' => '1',
        ])->assertSessionHasNoErrors();
        $this->assertSame('dispatched', $order->fresh()->status);
        $this->assertDatabaseCount('commission_batches', 0);

        app(OrderService::class)->deliver($this->owner, $order->fresh(), $parcel, 'Dealer signed');

        $this->assertSame('partly_delivered', $order->fresh()->status);
        $this->assertSame('6000.00', (string) DB::table('commission_batches')->where('order_id', $order->id)->value('cash_basis'));
    }

    public function test_returned_goods_go_back_to_stock_commission_comes_back_and_money_goes_to_the_account(): void
    {
        $order = $this->approvedOrder();
        $parcel = $this->send($order, 5);
        app(OrderService::class)->deliver($this->owner, $order->fresh(), $parcel, 'Dealer signed');
        $this->assertSame('delivered', $order->fresh()->status);
        $sponsorBefore = $this->available($this->sponsor);
        $dealerBefore = $this->available($this->dealer);
        $this->assertGreaterThan(0, $sponsorBefore);
        $stockBefore = (int) $this->product->fresh()->stock;
        $line = (int) $order->lines()->value('id');

        $this->actingAs($this->dealer)->onPortal('dealer')->post('/commands/orders/'.$order->id.'/returns', [
            'kind' => 'return', 'lines' => [['line_id' => $line, 'quantity' => 6]], 'reason' => 'Damaged',
        ])->assertSessionHasErrors('lines');
        $this->actingAs($this->dealer)->onPortal('dealer')->post('/commands/orders/'.$order->id.'/returns', [
            'kind' => 'return', 'lines' => [['line_id' => $line, 'quantity' => 2]], 'reason' => 'Two boxes damaged',
        ])->assertSessionHasNoErrors();
        $request = DB::table('order_return_requests')->where('order_id', $order->id)->first();

        $this->actingAs($this->owner)->onPortal('sadmin')->post('/commands/order-returns/'.$request->id.'/approve', [
            'refund_amount' => '4000', 'refund_method' => 'account', 'branch_id' => $this->hub->id, 'reason' => 'Boxes checked', 'confirmed' => '1',
        ])->assertSessionHasNoErrors();

        $this->assertSame($stockBefore + 2, (int) $this->product->fresh()->stock);
        $this->assertSame(2, (int) $order->lines()->value('returned'));
        $this->assertSame('delivered', $order->fresh()->status);
        $this->assertSame('4000.00', (string) DB::table('commission_batches')->where('order_id', $order->id)->value('refunded_amount'));
        $this->assertSame(intdiv($sponsorBefore * 3, 5), $this->available($this->sponsor), 'Commission on 2 of 5 units came back.');
        $this->assertDatabaseHas('order_refunds', ['order_id' => $order->id, 'amount' => '4000.00', 'cash_to_account' => '4000.00', 'kind' => 'return']);
        $this->assertSame($dealerBefore - intdiv($dealerBefore * 2, 5) + 400000, $this->available($this->dealer));
        $this->assertDatabaseHas('stock_movements', ['reference' => 'order-return:'.$request->id.':'.$line, 'quantity' => 2, 'branch_id' => $this->hub->id]);
        $this->assertTrue(app(FinancialReadiness::class)->booksMatch(), json_encode(app(FinancialReadiness::class)->checks()));
    }

    public function test_when_every_delivered_unit_comes_back_the_order_is_returned(): void
    {
        $order = $this->approvedOrder();
        $parcel = $this->send($order, 5);
        app(OrderService::class)->deliver($this->owner, $order->fresh(), $parcel, 'Dealer signed');

        $this->actingAs($this->owner)->onPortal('sadmin')->post('/commands/orders/'.$order->id.'/returns/start', [
            'kind' => 'return', 'lines' => [['line_id' => (int) $order->lines()->value('id'), 'quantity' => 5]],
            'refund_amount' => '10000', 'refund_method' => 'cash', 'reason' => 'All back', 'confirmed' => '1',
        ])->assertSessionHasNoErrors();

        $this->assertSame(OrderReturns::OrderReturned, $order->fresh()->status);
        $this->assertSame('10000.00', (string) DB::table('commission_batches')->where('order_id', $order->id)->value('refunded_amount'));
    }

    public function test_money_paid_from_the_account_goes_back_to_the_account_first(): void
    {
        DB::table('wallets')->insert(['user_id' => $this->dealer->id, 'available' => '10000.00', 'created_at' => now(), 'updated_at' => now()]);
        $order = app(OrderService::class)->create($this->dealer, ['items' => [['product_id' => $this->product->id, 'quantity' => 5]], 'idempotency_key' => (string) Str::uuid(), 'channel' => 'Account']);
        $this->assertSame('10000.00', (string) $order->fresh()->paid);
        $this->assertSame(0, $this->available($this->dealer));

        $this->actingAs($this->owner)->onPortal('sadmin')->post('/commands/orders/'.$order->id.'/returns/start', [
            'kind' => 'cancel', 'lines' => [['line_id' => (int) $order->lines()->value('id'), 'quantity' => 5]],
            'refund_amount' => '10000', 'refund_method' => 'cash', 'reason' => 'Changed mind', 'confirmed' => '1',
        ])->assertSessionHasNoErrors();

        $this->assertSame(1000000, $this->available($this->dealer));
        $this->assertDatabaseHas('order_refunds', ['order_id' => $order->id, 'to_account' => '10000.00', 'cash_to_account' => '0.00']);
        $this->assertTrue(app(FinancialReadiness::class)->checks()['account_order_money']);
    }

    public function test_money_for_goods_the_courier_brought_back_can_be_refunded_once(): void
    {
        $order = $this->approvedOrder();
        $parcel = $this->send($order, 5);
        DB::table('shipments')->where('id', $parcel)->update(['courier_status' => 'returned']);
        app(OrderService::class)->receiveReturnedParcel($this->owner, $order->fresh(), $parcel, 'Back at the hub');
        $this->assertSame('cancelled', $order->fresh()->status);

        $this->actingAs($this->owner)->onPortal('sadmin')->post('/commands/orders/'.$order->id.'/returns/start', [
            'kind' => 'refund', 'refund_amount' => '10000', 'refund_method' => 'cash', 'reason' => 'Courier returned it', 'confirmed' => '1',
        ])->assertSessionHasNoErrors();
        $this->actingAs($this->owner)->onPortal('sadmin')->post('/commands/orders/'.$order->id.'/returns/start', [
            'kind' => 'refund', 'refund_amount' => '1', 'refund_method' => 'cash', 'reason' => 'Again', 'confirmed' => '1',
        ])->assertSessionHasErrors('refund_amount');

        $this->assertSame(1, DB::table('order_refunds')->where('order_id', $order->id)->count());
    }

    public function test_staff_without_the_rights_or_from_another_branch_cannot_handle_requests(): void
    {
        $order = $this->approvedOrder();
        $other = CompanyBranch::factory()->create();
        $order->update(['fulfillment_branch_id' => $other->id]);
        $inventory = User::factory()->staff('inventory')->create();
        $branchDesk = User::factory()->staff('employee')->create(['status' => 'active', 'profile' => ['permissions' => ['orders', 'order_review']]]);
        BranchStaffAssignment::query()->create(['branch_id' => $this->hub->id, 'user_id' => $branchDesk->id, 'duty' => 'storekeeper', 'is_primary' => true]);
        $lines = [['line_id' => (int) $order->lines()->value('id'), 'quantity' => 5]];
        $payload = ['kind' => 'cancel', 'lines' => $lines, 'refund_amount' => '10000', 'refund_method' => 'cash', 'reason' => 'x', 'confirmed' => '1'];

        $this->actingAs($inventory)->onPortal('manage')->post('/commands/orders/'.$order->id.'/returns/start', $payload)->assertForbidden();
        $this->actingAs($this->dealer)->onPortal('dealer')->post('/commands/orders/'.$order->id.'/returns/start', $payload)->assertForbidden();
        $this->actingAs($branchDesk)->onPortal('manage')->post('/commands/orders/'.$order->id.'/returns/start', $payload)->assertForbidden();
        $this->assertDatabaseCount('order_return_requests', 0);
    }

    public function test_the_order_page_offers_the_right_actions_and_never_fills_in_the_amount(): void
    {
        $order = $this->approvedOrder();

        $this->actingAs($this->dealer)->onPortal('dealer')->get('/orders/'.$order->id)->assertOk()->assertInertia(fn (Assert $page) => $page
            ->where('returns.partner_can_cancel', true)->where('returns.staff_can_start', false)->where('returns.open', null));
        $this->actingAs($this->finance)->onPortal('manage')->get('/orders/'.$order->id)->assertOk()->assertInertia(fn (Assert $page) => $page
            ->where('returns.staff_can_start', true)->where('returns.paid_left', '10000.00')->where('returns.refundable_now', '0.00')->where('returns.acts_alone', false)
            ->where('returns.partner_can_cancel', false));
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

    private function available(User $user): int
    {
        return Money::cents((string) (DB::table('wallets')->where('user_id', $user->id)->value('available') ?? '0'));
    }
}
