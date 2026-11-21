<?php

namespace Tests\Feature;

use App\Domain\Finance\FinancialReadiness;
use App\Domain\Orders\OrderReturns;
use App\Domain\Orders\WalletPartRelease;
use App\Models\Order;
use App\Models\Product;
use App\Models\User;
use Database\Seeders\CommissionRuleSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Tests\TestCase;

/**
 * "Wallet + another method": the wallet part leaves the wallet when the order is placed. If the rest
 * never comes, the partner can cancel alone at once and get the wallet part back, and after the set
 * days (default 3) the order is cancelled by itself the same way. Nothing is ever returned twice.
 */
class OrderWalletPartReleaseTest extends TestCase
{
    use RefreshDatabase;

    private User $finance;

    protected function setUp(): void
    {
        parent::setUp();
        $this->withoutVite();
        Storage::fake('local');
        config(['madina.full_payment_when_ordering' => true, 'madina.wallet_part_unpaid_days' => 3]);
        $this->enableFinancialPosting();
        $this->seed(CommissionRuleSeeder::class);
        $this->finance = User::factory()->staff('finance')->create();
    }

    public function test_after_the_other_payment_is_turned_down_the_partner_is_told_and_can_cancel_at_once(): void
    {
        $dealer = $this->dealerWithBalance('500.00');
        $order = $this->walletPartOrder($dealer, 'Rocket');
        $this->assertSame('350.00', $this->usable($dealer));

        $this->rejectOtherClaim($order);
        $this->assertDatabaseHas('account_notices', ['user_id' => $dealer->id, 'title' => __('order_payment.notice_wallet_rest_title', ['number' => $order->number], $dealer->preferredLocale())]);
        $this->assertDatabaseHas('order_events', ['order_id' => $order->id, 'action' => WalletPartRelease::ActionReminded]);

        $asked = app(OrderReturns::class)->ask($dealer, $order->fresh(), OrderReturns::KindCancel, [], 'Cannot pay the rest');

        $this->assertTrue($asked['cancelled_now']);
        $this->assertSame('cancelled', $order->fresh()->status);
        $this->assertSame('500.00', $this->usable($dealer));
        $this->assertDatabaseHas('order_refunds', ['order_id' => $order->id, 'to_account' => '150.00', 'amount' => '150.00']);
        $this->assertSame(1, DB::table('journal_entries')->where('event_key', 'like', 'order.account_return.%')->count());
        $this->assertDatabaseHas('order_events', ['order_id' => $order->id, 'action' => WalletPartRelease::ActionCancelled]);
        $this->assertDatabaseHas('audit_events', ['subject' => $order->id, 'action' => WalletPartRelease::ActionCancelled]);
        $this->assertDatabaseCount('order_return_requests', 0);
        $this->assertBooksAgree();

        // A second cancel finds a cancelled order: nothing more comes back.
        try {
            app(OrderReturns::class)->ask($dealer, $order->fresh(), OrderReturns::KindCancel, [], 'Again');
            $this->fail('A cancelled order was cancelled again.');
        } catch (ValidationException) {
        }
        $this->assertSame('500.00', $this->usable($dealer));
        $this->assertDatabaseCount('order_refunds', 1);
    }

    public function test_while_a_bank_or_mobile_payment_waits_the_cancel_goes_to_the_office(): void
    {
        $dealer = $this->dealerWithBalance('500.00');
        $order = $this->walletPartOrder($dealer, 'Rocket');

        $asked = app(OrderReturns::class)->ask($dealer, $order, OrderReturns::KindCancel, [], 'Changed my mind');

        $this->assertFalse($asked['cancelled_now']);
        $this->assertSame('submitted', $order->fresh()->status);
        $this->assertSame('350.00', $this->usable($dealer));
    }

    public function test_cash_promised_at_the_office_does_not_stop_the_cancel(): void
    {
        $dealer = $this->dealerWithBalance('500.00');
        $order = $this->walletPartOrder($dealer, 'Office');

        $asked = app(OrderReturns::class)->ask($dealer, $order, OrderReturns::KindCancel, [], 'Will not come to the office');

        $this->assertTrue($asked['cancelled_now']);
        $this->assertSame('500.00', $this->usable($dealer));
        $this->assertDatabaseHas('payment_claims', ['order_id' => $order->id, 'channel' => 'Office', 'status' => 'rejected']);
        $this->assertBooksAgree();
    }

    public function test_an_order_paid_in_full_from_the_wallet_still_goes_to_the_office_to_cancel(): void
    {
        // Fully paid orders keep the normal cancel request: the office decides, as before.
        $dealer = $this->dealerWithBalance('500.00');
        $this->actingAs($dealer)->post('/commands/orders', [
            'items' => [['product_id' => $this->product()->id, 'quantity' => 1]],
            'idempotency_key' => (string) Str::uuid(),
            'channel' => 'Account',
        ])->assertSessionHasNoErrors();
        $order = Order::where('user_id', $dealer->id)->sole();

        $this->assertFalse(app(WalletPartRelease::class)->cancelsAtOnce($order));
        $this->assertFalse(app(OrderReturns::class)->ask($dealer, $order, OrderReturns::KindCancel, [], 'No longer needed')['cancelled_now']);
    }

    public function test_the_order_is_cancelled_by_itself_after_three_days_and_only_once(): void
    {
        $dealer = $this->dealerWithBalance('500.00');
        $order = $this->walletPartOrder($dealer, 'Rocket');
        $this->rejectOtherClaim($order);

        $this->travel(2)->days();
        $this->artisan('madina:release-unpaid-wallet-orders')->assertSuccessful();
        $this->assertSame('submitted', $order->fresh()->status);
        $this->assertSame('350.00', $this->usable($dealer));

        $this->travel(1)->days();
        $this->travel(1)->minutes();
        $this->artisan('madina:release-unpaid-wallet-orders', ['--dry-run' => true])->assertSuccessful();
        $this->assertSame('submitted', $order->fresh()->status);

        $this->artisan('madina:release-unpaid-wallet-orders')->assertSuccessful();
        $this->artisan('madina:release-unpaid-wallet-orders')->assertSuccessful();

        $this->assertSame('cancelled', $order->fresh()->status);
        $this->assertSame('500.00', $this->usable($dealer));
        $this->assertDatabaseCount('order_refunds', 1);
        $event = DB::table('order_events')->where('order_id', $order->id)->where('action', WalletPartRelease::ActionCancelled)->sole();
        $this->assertTrue(json_decode($event->metadata, true)['automatic']);
        $this->assertBooksAgree();
    }

    public function test_a_payment_waiting_to_be_checked_keeps_the_order(): void
    {
        $dealer = $this->dealerWithBalance('500.00');
        $order = $this->walletPartOrder($dealer, 'Rocket');

        $this->travel(10)->days();
        $this->artisan('madina:release-unpaid-wallet-orders')->assertSuccessful();

        $this->assertSame('submitted', $order->fresh()->status);
        $this->assertSame('350.00', $this->usable($dealer));
    }

    public function test_a_rest_that_was_never_sent_also_expires(): void
    {
        config(['madina.full_payment_when_ordering' => false]);
        $dealer = $this->dealerWithBalance('500.00');
        // Wallet part with bank chosen for the rest, but no payment details sent with the order.
        $this->actingAs($dealer)->post('/commands/orders', [
            'items' => [['product_id' => $this->product()->id, 'quantity' => 1]],
            'idempotency_key' => (string) Str::uuid(),
            'channel' => 'Bank',
            'use_account' => true,
            'account_amount' => '150.00',
            'amount' => '50.00',
        ])->assertSessionHasNoErrors();
        $order = Order::where('user_id', $dealer->id)->sole();
        $this->assertSame('150.00', (string) $order->paid);
        $this->assertDatabaseMissing('payment_claims', ['order_id' => $order->id, 'channel' => 'Bank']);

        $this->travel(3)->days();
        $this->travel(1)->minutes();
        $this->artisan('madina:release-unpaid-wallet-orders')->assertSuccessful();

        $this->assertSame('cancelled', $order->fresh()->status);
        $this->assertSame('500.00', $this->usable($dealer));
    }

    public function test_an_approved_or_fully_paid_order_is_never_touched(): void
    {
        $dealer = $this->dealerWithBalance('500.00');
        $order = $this->walletPartOrder($dealer, 'Rocket');
        $claimId = (int) DB::table('payment_claims')->where('order_id', $order->id)->where('channel', 'Rocket')->value('id');
        $this->actingAs($this->finance)->onPortal('manage')->post('/commands/claims/'.$claimId.'/verify', ['approve' => '1', 'reason' => 'Money seen in Rocket'])->assertSessionHasNoErrors();

        $this->travel(10)->days();
        $this->artisan('madina:release-unpaid-wallet-orders')->assertSuccessful();

        $this->assertSame('submitted', $order->fresh()->status);
        $this->assertSame('200.00', (string) $order->fresh()->paid);
        $this->assertSame('350.00', $this->usable($dealer));
    }

    private function walletPartOrder(User $dealer, string $restChannel): Order
    {
        $this->actingAs($dealer)->post('/commands/orders', [
            'items' => [['product_id' => $this->product()->id, 'quantity' => 1]],
            'idempotency_key' => (string) Str::uuid(),
            'channel' => $restChannel,
            'use_account' => true,
            'account_amount' => '150.00',
            ...($restChannel === 'Office' ? [] : ['reference' => 'RK-'.Str::upper(Str::random(8)), 'amount' => '50.00', 'proof' => UploadedFile::fake()->image('proof.png')]),
        ])->assertSessionHasNoErrors();

        return Order::where('user_id', $dealer->id)->latest('created_at')->firstOrFail();
    }

    private function rejectOtherClaim(Order $order): void
    {
        $claimId = (int) DB::table('payment_claims')->where('order_id', $order->id)->where('channel', '<>', 'Account')->value('id');
        $this->actingAs($this->finance)->onPortal('manage')->post('/commands/claims/'.$claimId.'/verify', ['approve' => '0', 'reason' => 'No such Rocket payment'])->assertSessionHasNoErrors();
    }

    private function product(): Product
    {
        return Product::factory()->create(['mrp' => '400.00', 'dealer_price' => '200.00', 'stock' => 500]);
    }

    private function dealerWithBalance(string $available): User
    {
        $dealer = User::factory()->dealer()->create();
        DB::table('wallets')->insert(['user_id' => $dealer->id, 'available' => $available, 'held' => '0.00', 'commission_held' => '0.00', 'created_at' => now(), 'updated_at' => now()]);
        DB::table('journal_entries')->insert(['id' => $entry = (string) Str::uuid(), 'event_key' => 'test.opening.'.$dealer->id, 'description' => 'Test opening balance', 'created_at' => now()]);
        DB::table('journal_lines')->insert([
            ['entry_id' => $entry, 'user_id' => $dealer->id, 'account' => 'wallet_liability', 'debit' => '0.00', 'credit' => $available],
            ['entry_id' => $entry, 'user_id' => null, 'account' => 'commission_expense', 'debit' => $available, 'credit' => '0.00'],
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
