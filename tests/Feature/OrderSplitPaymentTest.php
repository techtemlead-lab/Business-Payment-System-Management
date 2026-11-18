<?php

namespace Tests\Feature;

use App\Domain\Finance\FinancialReadiness;
use App\Domain\Orders\OrderRefund;
use App\Domain\Orders\OrderReturns;
use App\Domain\Orders\OrderService;
use App\Models\Order;
use App\Models\Product;
use App\Models\User;
use Database\Seeders\CommissionRuleSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Illuminate\Testing\TestResponse;
use Tests\TestCase;

/**
 * "Account balance + another way": the usable balance pays part of an order and a second
 * method (a mobile or bank payment, or cash at the office) pays the rest. Only the money a
 * partner can use now is ever taken; the form's idea of the balance never decides anything.
 */
class OrderSplitPaymentTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->withoutVite();
        Storage::fake('local');
        config(['madina.full_payment_when_ordering' => true]);
        $this->enableFinancialPosting();
        $this->seed(CommissionRuleSeeder::class);
    }

    public function test_a_short_balance_pays_its_part_and_a_mobile_payment_pays_the_rest(): void
    {
        $dealer = $this->dealerWithBalance('150.00');
        $finance = User::factory()->staff('finance')->create();
        $reviewer = User::factory()->staff('super_admin')->create();

        $this->splitOrder($dealer, 2, 'bKash', ['reference' => 'BKASH12345', 'amount' => '50.00'])->assertSessionHasNoErrors()->assertRedirect();

        $order = Order::where('user_id', $dealer->id)->sole();
        $this->assertSame('150.00', (string) $order->paid);
        $this->assertSame('0.00', $this->usable($dealer));
        $this->assertDatabaseHas('receipts', ['order_id' => $order->id, 'channel' => 'Account', 'amount' => '150.00']);
        $this->assertDatabaseHas('payment_claims', ['order_id' => $order->id, 'channel' => 'bKash', 'amount' => '50.00', 'status' => 'pending']);
        $this->assertDatabaseHas('order_events', ['order_id' => $order->id, 'action' => 'payment.account_paid']);

        // Not approvable while the rest is only claimed.
        $this->actingAs($reviewer)->onPortal('sadmin')->post('/commands/orders/'.$order->id.'/decision', ['status' => 'approved', 'reason' => 'Looks fine'])->assertSessionHasErrors('status');

        $claim = DB::table('payment_claims')->where('order_id', $order->id)->where('channel', 'bKash')->value('id');
        app(OrderService::class)->verify($finance, (int) $claim, true, 'Money seen in bKash');
        $this->assertSame('200.00', (string) $order->fresh()->paid);
        $this->actingAs($reviewer)->onPortal('sadmin')->post('/commands/orders/'.$order->id.'/decision', ['status' => 'approved', 'reason' => 'Paid in full'])->assertSessionHasNoErrors();
        $this->assertSame('approved', $order->fresh()->status);
        $this->assertBooksAgree();
    }

    public function test_a_wrong_rest_amount_is_refused_and_nothing_is_taken(): void
    {
        $dealer = $this->dealerWithBalance('150.00');

        $this->splitOrder($dealer, 2, 'bKash', ['reference' => 'BKASH12345', 'amount' => '75.00'])->assertSessionHasErrors('amount');

        $this->assertDatabaseCount('orders', 0);
        $this->assertDatabaseCount('receipts', 0);
        $this->assertSame('150.00', $this->usable($dealer));
    }

    public function test_a_short_balance_with_cash_at_the_office_waits_for_the_cash_receipt(): void
    {
        $dealer = $this->dealerWithBalance('120.00');
        $finance = User::factory()->staff('finance')->create();
        $reviewer = User::factory()->staff('super_admin')->create();

        $this->splitOrder($dealer, 2, 'Office')->assertSessionHasNoErrors();

        $order = Order::where('user_id', $dealer->id)->sole();
        $this->assertSame('120.00', (string) $order->paid);
        $claim = DB::table('payment_claims')->where('order_id', $order->id)->where('channel', 'Office')->sole();
        $this->assertSame('80.00', (string) $claim->amount);
        $this->assertSame('pending', $claim->status);

        $this->actingAs($reviewer)->onPortal('sadmin')->post('/commands/orders/'.$order->id.'/decision', ['status' => 'approved', 'reason' => 'Looks fine'])->assertSessionHasErrors('status');

        $this->actingAs($finance)->onPortal('manage')->post('/commands/claims/'.$claim->id.'/verify', ['approve' => '1', 'reason' => 'Cash received at the counter'])->assertSessionHasNoErrors();
        $this->assertDatabaseHas('receipts', ['order_id' => $order->id, 'channel' => 'Office', 'reference' => 'OFC-'.$order->number, 'amount' => '80.00']);
        $this->actingAs($reviewer)->onPortal('sadmin')->post('/commands/orders/'.$order->id.'/decision', ['status' => 'approved', 'reason' => 'Cash confirmed'])->assertSessionHasNoErrors();
        $this->assertSame('approved', $order->fresh()->status);
        $this->assertBooksAgree();
    }

    public function test_a_balance_that_covers_the_order_pays_all_of_it(): void
    {
        $dealer = $this->dealerWithBalance('500.00');

        $this->splitOrder($dealer, 2, 'bKash', ['reference' => 'BKASH12345', 'amount' => '50.00'])->assertSessionHasNoErrors();

        $order = Order::where('user_id', $dealer->id)->sole();
        $this->assertSame('200.00', (string) $order->paid);
        $this->assertSame('300.00', $this->usable($dealer));
        $this->assertDatabaseMissing('payment_claims', ['order_id' => $order->id, 'channel' => 'bKash']);
        $this->assertBooksAgree();
    }

    public function test_an_empty_account_cannot_be_used_for_a_split(): void
    {
        $dealer = $this->dealerWithBalance('0.00');

        $this->splitOrder($dealer, 2, 'Office')->assertSessionHasErrors(['channel' => __('order_split.err_account_empty')]);

        $this->assertDatabaseCount('orders', 0);
    }

    public function test_waiting_and_held_money_is_never_taken(): void
    {
        $dealer = $this->dealerWithBalance('50.00', held: '900.00', commissionHeld: '600.00');

        $this->splitOrder($dealer, 2, 'Office')->assertSessionHasNoErrors();

        $order = Order::where('user_id', $dealer->id)->sole();
        $this->assertSame('50.00', (string) $order->paid);
        $this->assertDatabaseHas('wallets', ['user_id' => $dealer->id, 'available' => '0.00', 'held' => '900.00', 'commission_held' => '600.00']);
    }

    public function test_a_second_order_cannot_spend_the_money_the_first_one_took(): void
    {
        $dealer = $this->dealerWithBalance('150.00');

        $this->splitOrder($dealer, 2, 'Office')->assertSessionHasNoErrors();
        $this->splitOrder($dealer, 2, 'Office')->assertSessionHasErrors(['channel' => __('order_split.err_account_empty')]);

        $this->assertDatabaseCount('orders', 1);
        $this->assertSame('0.00', $this->usable($dealer));
        $this->assertSame(1, DB::table('journal_entries')->where('event_key', 'like', 'order.account_pay.%')->count());
        $this->assertBooksAgree();
    }

    public function test_the_same_key_sent_twice_takes_the_money_once(): void
    {
        $dealer = $this->dealerWithBalance('150.00');
        $key = (string) Str::uuid();

        $this->splitOrder($dealer, 2, 'Office', [], $key)->assertSessionHasNoErrors();
        $this->splitOrder($dealer, 2, 'Office', [], $key)->assertSessionHasNoErrors();

        $this->assertDatabaseCount('orders', 1);
        $this->assertDatabaseCount('receipts', 1);
        $this->assertSame('0.00', $this->usable($dealer));
    }

    public function test_the_account_cannot_be_charged_twice_for_one_order(): void
    {
        $dealer = $this->dealerWithBalance('150.00');
        $this->splitOrder($dealer, 2, 'Office')->assertSessionHasNoErrors();
        $order = Order::where('user_id', $dealer->id)->sole();
        DB::table('wallets')->where('user_id', $dealer->id)->update(['available' => '500.00']);
        DB::table('payment_claims')->where('order_id', $order->id)->update(['status' => 'rejected']);

        $this->actingAs($dealer)->onPortal('dealer')->post('/commands/orders/'.$order->id.'/pay-from-account', [])
            ->assertSessionHasErrors(['channel' => __('order_split.err_account_twice')]);

        $this->assertSame('500.00', $this->usable($dealer));
    }

    public function test_rejecting_an_order_with_only_the_account_part_paid_returns_it(): void
    {
        $dealer = $this->dealerWithBalance('150.00');
        $reviewer = User::factory()->staff('super_admin')->create();
        $this->splitOrder($dealer, 2, 'Office')->assertSessionHasNoErrors();
        $order = Order::where('user_id', $dealer->id)->sole();

        $this->actingAs($reviewer)->onPortal('sadmin')->post('/commands/orders/'.$order->id.'/decision', ['status' => 'rejected', 'reason' => 'Out of stock'])->assertSessionHasNoErrors();

        $this->assertSame('150.00', $this->usable($dealer));
        $this->assertDatabaseHas('order_refunds', ['order_id' => $order->id, 'to_account' => '150.00']);
        $this->assertBooksAgree();
    }

    public function test_a_refund_of_a_split_order_returns_the_account_part_to_the_account(): void
    {
        $dealer = $this->dealerWithBalance('150.00');
        $finance = User::factory()->staff('finance')->create();
        $this->splitOrder($dealer, 2, 'Office')->assertSessionHasNoErrors();
        $order = Order::where('user_id', $dealer->id)->sole();
        app(OrderService::class)->verify($finance, (int) DB::table('payment_claims')->where('order_id', $order->id)->where('channel', 'Office')->value('id'), true, 'Cash taken');

        $request = app(OrderRefund::class)->refund($finance, $order->fresh(), '200.00', 'Partner changed their mind');
        // A refund by anyone but the owner waits for a second person (MA-16).
        $this->assertSame('0.00', $this->usable($dealer));
        app(OrderReturns::class)->confirm(User::factory()->staff('finance')->create(), $request['id'], 'Second check');

        $this->assertSame('150.00', $this->usable($dealer));
        $this->assertDatabaseHas('order_refunds', ['order_id' => $order->id, 'amount' => '200.00', 'to_account' => '150.00']);
        $this->assertBooksAgree();
    }

    public function test_when_money_movement_is_off_a_split_is_refused(): void
    {
        $dealer = $this->dealerWithBalance('150.00');
        config(['madina.financial_activation' => false]);

        $this->splitOrder($dealer, 2, 'Office')->assertSessionHasErrors(['channel' => __('order_payment.err_account_off')]);

        $this->assertDatabaseCount('orders', 0);
    }

    public function test_office_staff_cannot_place_a_split_order_for_a_partner(): void
    {
        $this->actingAs(User::factory()->staff('finance')->create())->onPortal('manage')->post('/commands/orders', [
            'items' => [['product_id' => $this->product()->id, 'quantity' => 1]], 'idempotency_key' => (string) Str::uuid(), 'channel' => 'Office', 'use_account' => true,
        ])->assertForbidden();
    }

    public function test_the_account_channel_cannot_also_ask_for_a_split(): void
    {
        $dealer = $this->dealerWithBalance('500.00');

        $this->splitOrder($dealer, 1, 'Account')->assertSessionHasErrors('use_account');

        $this->assertDatabaseCount('orders', 0);
    }

    /** @param array<string, mixed> $extra */
    private function splitOrder(User $dealer, int $quantity, string $channel, array $extra = [], ?string $key = null): TestResponse
    {
        return $this->actingAs($dealer)->post('/commands/orders', [
            'items' => [['product_id' => $this->product()->id, 'quantity' => $quantity]],
            'idempotency_key' => $key ?? (string) Str::uuid(),
            'channel' => $channel,
            'use_account' => true,
            ...($extra === [] ? [] : [...$extra, 'proof' => UploadedFile::fake()->image('proof.png')]),
        ]);
    }

    private function product(): Product
    {
        return Product::where('sku', 'SPLIT-1')->first() ?? Product::factory()->create(['sku' => 'SPLIT-1', 'mrp' => '200.00', 'dealer_price' => '100.00', 'stock' => 500]);
    }

    private function dealerWithBalance(string $available, string $held = '0.00', string $commissionHeld = '0.00'): User
    {
        $dealer = User::factory()->dealer()->create();
        DB::table('wallets')->insert(['user_id' => $dealer->id, 'available' => $available, 'held' => $held, 'commission_held' => $commissionHeld, 'created_at' => now(), 'updated_at' => now()]);
        DB::table('journal_entries')->insert(['id' => $entry = (string) Str::uuid(), 'event_key' => 'test.opening.'.$dealer->id, 'description' => 'Test opening balance', 'created_at' => now()]);
        DB::table('journal_lines')->insert([
            ['entry_id' => $entry, 'user_id' => $dealer->id, 'account' => 'wallet_liability', 'debit' => '0.00', 'credit' => bcadd($available, bcsub($held, $commissionHeld, 2), 2)],
            ['entry_id' => $entry, 'user_id' => $dealer->id, 'account' => 'commission_held_liability', 'debit' => '0.00', 'credit' => $commissionHeld],
            ['entry_id' => $entry, 'user_id' => null, 'account' => 'commission_expense', 'debit' => bcadd($available, $held, 2), 'credit' => '0.00'],
        ]);

        return $dealer;
    }

    private function usable(User $partner): string
    {
        return (string) DB::table('wallets')->where('user_id', $partner->id)->value('available');
    }

    private function assertBooksAgree(): void
    {
        $checks = app(FinancialReadiness::class)->checks();
        $this->assertSame([], array_keys(array_filter($checks, fn (bool $passed): bool => ! $passed)));
    }
}
