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
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Tests\TestCase;

/**
 * No credit buying (owner's rule): every taka paid on an order that is cancelled or comes back
 * goes back to the partner. A cancel, return or refund is carried out only when it gives back
 * exactly what is owed, and an order is not closed while a payment on it waits to be checked.
 * Worked example: 5 units at ৳2,000, ৳10,000 paid in full.
 */
class PaidOrderRefundGuardTest extends TestCase
{
    use RefreshDatabase;

    private User $owner;

    private User $finance;

    private User $secondFinance;

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
        $sponsor = User::factory()->dealer()->create(['status' => 'active']);
        ReferralTreePath::attachUser($sponsor->id, null);
        $this->dealer = User::factory()->dealer()->create(['status' => 'active', 'sponsor_id' => $sponsor->id]);
        ReferralTreePath::attachUser($this->dealer->id, $sponsor->id);
        $this->product = Product::factory()->create(['active' => true, 'mrp' => '3000.00', 'dealer_price' => '2000.00', 'stock' => 0]);
        app(StockService::class)->stockIn($this->owner, ['product_id' => $this->product->id, 'quantity' => 100, 'reference' => (string) Str::uuid(),
            'branch_id' => $this->hub->id, 'batch_number' => 'B1', 'expires_on' => now()->addYear()->toDateString()]);
    }

    public function test_a_paid_cancel_with_no_or_part_of_the_money_back_is_refused(): void
    {
        $order = $this->approvedOrder();

        foreach (['', '0', '6000'] as $amount) {
            $this->actingAs($this->owner)->onPortal('sadmin')->post('/commands/orders/'.$order->id.'/returns/start', [
                'kind' => 'cancel', 'lines' => $this->allLines($order, 5), 'refund_amount' => $amount, 'refund_method' => 'cash', 'reason' => 'Out of stock', 'confirmed' => '1',
            ])->assertSessionHasErrors('refund_amount');
        }

        $this->assertDatabaseCount('order_return_requests', 0);
        $this->assertDatabaseCount('order_refunds', 0);
        $this->assertSame('approved', $order->fresh()->status);
        $this->assertSame(0, (int) $order->lines()->value('closed'));
    }

    public function test_a_partner_cancel_of_a_paid_order_cannot_be_approved_without_the_money_back(): void
    {
        $order = $this->approvedOrder();
        $this->actingAs($this->dealer)->onPortal('dealer')->post('/commands/orders/'.$order->id.'/returns', ['kind' => 'cancel', 'reason' => 'No longer needed'])
            ->assertSessionHasNoErrors();
        $request = DB::table('order_return_requests')->where('order_id', $order->id)->sole();

        $this->actingAs($this->finance)->onPortal('manage')->post('/commands/order-returns/'.$request->id.'/approve', [
            'refund_amount' => '0', 'refund_method' => 'cash', 'reason' => 'Stock ran out', 'confirmed' => '1',
        ])->assertSessionHasErrors('refund_amount');
        $this->assertSame(OrderReturns::StatusRequested, DB::table('order_return_requests')->where('id', $request->id)->value('status'));

        // Someone with the order review right but no refund right cannot close it without the money either.
        $desk = User::factory()->staff('employee')->create(['status' => 'active', 'profile' => ['permissions' => ['orders', 'order_review']]]);
        BranchStaffAssignment::query()->create(['branch_id' => $this->hub->id, 'user_id' => $desk->id, 'duty' => 'storekeeper', 'is_primary' => true]);
        $this->actingAs($desk)->onPortal('manage')->post('/commands/order-returns/'.$request->id.'/approve', [
            'refund_amount' => '', 'refund_method' => 'cash', 'reason' => 'Stock ran out', 'confirmed' => '1',
        ])->assertForbidden();
        $this->assertSame(OrderReturns::StatusRequested, DB::table('order_return_requests')->where('id', $request->id)->value('status'));
        $this->assertSame('approved', $order->fresh()->status);
    }

    public function test_a_full_refund_closes_the_order_with_nothing_left_and_the_money_check_passes(): void
    {
        $order = $this->approvedOrder();

        $this->actingAs($this->owner)->onPortal('sadmin')->post('/commands/orders/'.$order->id.'/returns/start', [
            'kind' => 'cancel', 'lines' => $this->allLines($order, 5), 'refund_amount' => '10000', 'refund_method' => 'cash', 'reason' => 'Out of stock', 'confirmed' => '1',
        ])->assertSessionHasNoErrors();

        $this->assertSame('cancelled', $order->fresh()->status);
        $this->assertDatabaseHas('order_refunds', ['order_id' => $order->id, 'amount' => '10000.00']);
        $this->assertSame(0, app(OrderReturns::class)->paidLeftCents($order->fresh()));
        $this->assertSame(['count' => 0, 'amount' => '0.00'], OrderReturns::moneyToGiveBack());
        $this->assertTrue(app(FinancialReadiness::class)->booksMatch(), json_encode(app(FinancialReadiness::class)->checks()));
    }

    public function test_a_return_of_part_of_the_goods_gives_back_exactly_their_value(): void
    {
        $order = $this->approvedOrder();
        $parcel = $this->send($order, 5);
        app(OrderService::class)->deliver($this->owner, $order->fresh(), $parcel, 'Dealer signed');
        $payload = ['kind' => 'return', 'lines' => $this->allLines($order, 2), 'refund_method' => 'cash', 'branch_id' => $this->hub->id, 'reason' => 'Two boxes damaged', 'confirmed' => '1'];

        $this->actingAs($this->owner)->onPortal('sadmin')->post('/commands/orders/'.$order->id.'/returns/start', [...$payload, 'refund_amount' => '3000'])
            ->assertSessionHasErrors('refund_amount');
        $this->actingAs($this->owner)->onPortal('sadmin')->post('/commands/orders/'.$order->id.'/returns/start', [...$payload, 'refund_amount' => '4000.01'])
            ->assertSessionHasErrors('refund_amount');
        $this->assertDatabaseCount('order_refunds', 0);

        $this->actingAs($this->owner)->onPortal('sadmin')->post('/commands/orders/'.$order->id.'/returns/start', [...$payload, 'refund_amount' => '4000'])
            ->assertSessionHasNoErrors();

        $this->assertSame('delivered', $order->fresh()->status);
        $this->assertSame(2, (int) $order->lines()->value('returned'));
        $this->assertDatabaseHas('order_refunds', ['order_id' => $order->id, 'amount' => '4000.00', 'kind' => 'return']);
        $this->assertSame(0, app(OrderReturns::class)->refundableCents($order->fresh()), 'The 3 boxes kept are paid for; nothing else is owed.');
        $this->assertSame(0, OrderReturns::moneyToGiveBack()['count']);
        $this->assertTrue(app(FinancialReadiness::class)->booksMatch(), json_encode(app(FinancialReadiness::class)->checks()));
    }

    public function test_closing_the_rest_of_a_back_order_must_give_back_the_unsent_goods_money_as_no_deduction_is_allowed(): void
    {
        $order = $this->approvedOrder();
        $parcel = $this->send($order, 3);
        app(OrderService::class)->deliver($this->owner, $order->fresh(), $parcel, 'Dealer signed');
        $payload = ['kind' => 'cancel', 'lines' => $this->allLines($order, 2), 'refund_method' => 'cash', 'reason' => 'Close the rest', 'confirmed' => '1'];

        foreach (['0', '3500'] as $amount) {
            $this->actingAs($this->owner)->onPortal('sadmin')->post('/commands/orders/'.$order->id.'/returns/start', [...$payload, 'refund_amount' => $amount])
                ->assertSessionHasErrors('refund_amount');
        }
        $this->assertSame('partially_dispatched', $order->fresh()->status);

        $this->actingAs($this->owner)->onPortal('sadmin')->post('/commands/orders/'.$order->id.'/returns/start', [...$payload, 'refund_amount' => '4000'])
            ->assertSessionHasNoErrors();
        $this->assertSame('partly_delivered', $order->fresh()->status);
        $this->assertSame(0, OrderReturns::moneyToGiveBack()['count']);
    }

    public function test_money_paid_from_the_wallet_goes_back_to_the_wallet_through_the_two_person_steps(): void
    {
        DB::table('wallets')->insert(['user_id' => $this->dealer->id, 'available' => '10000.00', 'created_at' => now(), 'updated_at' => now()]);
        $order = app(OrderService::class)->create($this->dealer, ['items' => [['product_id' => $this->product->id, 'quantity' => 5]], 'idempotency_key' => (string) Str::uuid(), 'channel' => 'Account']);
        $this->assertSame(0, $this->available($this->dealer));
        $this->actingAs($this->dealer)->onPortal('dealer')->post('/commands/orders/'.$order->id.'/returns', ['kind' => 'cancel', 'reason' => 'Changed mind'])
            ->assertSessionHasNoErrors();
        $request = DB::table('order_return_requests')->where('order_id', $order->id)->sole();

        $this->actingAs($this->finance)->onPortal('manage')->post('/commands/order-returns/'.$request->id.'/approve', [
            'refund_amount' => '10000', 'refund_method' => 'cash', 'reason' => 'Partner asked', 'confirmed' => '1',
        ])->assertSessionHasNoErrors();
        $this->actingAs($this->secondFinance)->onPortal('manage')->post('/commands/order-returns/'.$request->id.'/confirm', ['reason' => 'Checked', 'confirmed' => '1'])
            ->assertSessionHasNoErrors();

        $this->assertSame(1000000, $this->available($this->dealer));
        $this->assertDatabaseHas('order_refunds', ['order_id' => $order->id, 'amount' => '10000.00', 'to_account' => '10000.00', 'cash_to_account' => '0.00']);
        $this->assertSame('cancelled', $order->fresh()->status);
        // The wallet above was seeded without its journal, so only the order money checks are read here.
        $checks = app(FinancialReadiness::class)->checks();
        $this->assertTrue($checks['account_order_money']);
        $this->assertSame(0, OrderReturns::moneyToGiveBack()['count']);
    }

    public function test_a_second_submit_of_the_same_cancel_moves_no_money_twice(): void
    {
        $order = $this->approvedOrder();
        $payload = ['kind' => 'cancel', 'lines' => $this->allLines($order, 5), 'refund_amount' => '10000', 'refund_method' => 'account', 'reason' => 'Out of stock', 'confirmed' => '1'];

        $this->actingAs($this->owner)->onPortal('sadmin')->post('/commands/orders/'.$order->id.'/returns/start', $payload)->assertSessionHasNoErrors();
        $this->actingAs($this->owner)->onPortal('sadmin')->post('/commands/orders/'.$order->id.'/returns/start', $payload)->assertSessionHasErrors();
        $this->actingAs($this->owner)->onPortal('sadmin')->post('/commands/orders/'.$order->id.'/returns/start', [...$payload, 'kind' => 'refund', 'lines' => []])
            ->assertSessionHasErrors('refund_amount');

        $this->assertSame(1, DB::table('order_refunds')->where('order_id', $order->id)->count());
        $this->assertSame(1000000, $this->available($this->dealer));
        $this->assertTrue(app(FinancialReadiness::class)->booksMatch(), json_encode(app(FinancialReadiness::class)->checks()));
    }

    public function test_an_order_with_a_mobile_payment_waiting_is_not_turned_down_or_cancelled_until_the_payment_is_decided(): void
    {
        Storage::fake('local');
        config(['madina.full_payment_when_ordering' => true]);
        DB::table('wallets')->insert(['user_id' => $this->dealer->id, 'available' => '500.00', 'created_at' => now(), 'updated_at' => now()]);
        DB::table('journal_entries')->insert(['id' => $entry = (string) Str::uuid(), 'event_key' => 'test.opening.'.$this->dealer->id, 'description' => 'Test opening balance', 'created_at' => now()]);
        DB::table('journal_lines')->insert([
            ['entry_id' => $entry, 'user_id' => $this->dealer->id, 'account' => 'wallet_liability', 'debit' => '0.00', 'credit' => '500.00'],
            ['entry_id' => $entry, 'user_id' => null, 'account' => 'commission_expense', 'debit' => '500.00', 'credit' => '0.00'],
        ]);
        // ৳500 from the account and the other ৳1,500 by bKash, still waiting to be checked.
        $this->actingAs($this->dealer)->onPortal('dealer')->post('/commands/orders', [
            'items' => [['product_id' => $this->product->id, 'quantity' => 1]], 'idempotency_key' => (string) Str::uuid(), 'channel' => 'bKash', 'use_account' => true,
            'reference' => 'BKASH-WAIT-1', 'amount' => '1500.00', 'proof' => UploadedFile::fake()->image('proof.png'),
        ])->assertSessionHasNoErrors();
        $order = Order::query()->where('user_id', $this->dealer->id)->sole();
        $this->assertSame('500.00', (string) $order->paid);
        $claim = (int) DB::table('payment_claims')->where('order_id', $order->id)->where('channel', 'bKash')->where('status', 'pending')->value('id');

        $this->actingAs($this->owner)->onPortal('sadmin')->post('/commands/orders/'.$order->id.'/decision', ['status' => 'rejected', 'reason' => 'Out of stock'])
            ->assertSessionHasErrors('status');
        $this->assertSame('submitted', $order->fresh()->status);

        $this->actingAs($this->dealer)->onPortal('dealer')->post('/commands/orders/'.$order->id.'/returns', ['kind' => 'cancel', 'reason' => 'No longer needed'])
            ->assertSessionHasNoErrors();
        $request = DB::table('order_return_requests')->where('order_id', $order->id)->sole();
        $approve = ['refund_method' => 'cash', 'reason' => 'Partner asked', 'confirmed' => '1'];
        $this->actingAs($this->owner)->onPortal('sadmin')->post('/commands/order-returns/'.$request->id.'/approve', [...$approve, 'refund_amount' => '500'])
            ->assertSessionHasErrors('request');
        $this->assertSame('pending', DB::table('payment_claims')->where('id', $claim)->value('status'), 'The waiting payment is not closed quietly.');

        app(OrderService::class)->verify($this->finance, $claim, true, 'Money seen in bKash');
        $this->assertSame('2000.00', (string) $order->fresh()->paid);
        $this->actingAs($this->owner)->onPortal('sadmin')->post('/commands/order-returns/'.$request->id.'/approve', [...$approve, 'refund_amount' => '500'])
            ->assertSessionHasErrors('refund_amount');
        $this->actingAs($this->owner)->onPortal('sadmin')->post('/commands/order-returns/'.$request->id.'/approve', [...$approve, 'refund_amount' => '2000'])
            ->assertSessionHasNoErrors();

        $this->assertSame('cancelled', $order->fresh()->status);
        $this->assertDatabaseHas('order_refunds', ['order_id' => $order->id, 'amount' => '2000.00', 'to_account' => '500.00', 'cash_to_account' => '0.00']);
        $this->assertSame(50000, $this->available($this->dealer), 'The account part went back to the account; the bKash part is handed back.');
        $this->assertSame([], FinancialReadiness::failedNames(app(FinancialReadiness::class)->checks()));
    }

    /** @return list<array{line_id: int, quantity: int}> */
    private function allLines(Order $order, int $quantity): array
    {
        return [['line_id' => (int) $order->lines()->value('id'), 'quantity' => $quantity]];
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
