<?php

namespace Tests\Feature;

use App\Domain\Finance\FinancialReadiness;
use App\Domain\Orders\OrderInvoice;
use App\Domain\Orders\OrderReturns;
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
use Inertia\Testing\AssertableInertia as Assert;
use Tests\TestCase;

/**
 * Order payment methods: Bank, bKash, Nagad, Rocket, By Office and Account balance. Rocket is paid
 * like bKash and Nagad. Account balance pays from the usable wallet money: the whole order when the
 * balance covers it, otherwise its part with a second method for the rest.
 */
class OrderRocketAndAccountBalanceTest extends TestCase
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

    public function test_the_two_new_method_names_read_plainly_in_both_languages(): void
    {
        $this->assertSame('Rocket', __('portal.pay_channel_rocket', [], 'en'));
        $this->assertSame('রকেট', __('portal.pay_channel_rocket', [], 'bn'));
        $this->assertSame('Wallet / account balance', __('portal.pay_channel_account', [], 'en'));
        $this->assertSame('ওয়ালেট / অ্যাকাউন্ট ব্যালেন্স', __('portal.pay_channel_account', [], 'bn'));
        foreach (['en', 'bn'] as $locale) {
            foreach (['split_message', 'rest_method', 'method_not_ready'] as $key) {
                $this->assertNotSame('order_split.'.$key, __('order_split.'.$key, [], $locale));
            }
        }
        $this->assertStringContainsString('৳', __('order_split.split_message', ['balance' => '৳150', 'rest' => '৳50'], 'bn'));
        $this->assertSame(
            'আপনার অ্যাকাউন্টে ৳150 আছে। অ্যাকাউন্ট থেকে ৳150 নেওয়া হবে, বাকি ৳50 কীভাবে দেবেন?',
            __('order_split.split_message', ['balance' => '৳150', 'rest' => '৳50'], 'bn'),
        );
    }

    public function test_a_rocket_payment_comes_with_the_order_and_waits_for_checking(): void
    {
        $dealer = User::factory()->dealer()->create();

        $this->actingAs($dealer)->post('/commands/orders', [
            'items' => [['product_id' => $this->product()->id, 'quantity' => 1]],
            'idempotency_key' => (string) Str::uuid(),
            'channel' => 'Rocket',
            'reference' => 'ROCKET12345',
            'amount' => '100.00',
            'proof' => UploadedFile::fake()->image('proof.png'),
        ])->assertSessionHasNoErrors();

        $order = Order::where('user_id', $dealer->id)->sole();
        $this->assertDatabaseHas('payment_claims', ['order_id' => $order->id, 'channel' => 'Rocket', 'amount' => '100.00', 'status' => 'pending']);
        $this->assertSame('0.00', (string) $order->paid);
    }

    public function test_a_rocket_payment_needs_its_reference_like_the_other_mobile_methods(): void
    {
        $dealer = User::factory()->dealer()->create();

        $this->actingAs($dealer)->post('/commands/orders', [
            'items' => [['product_id' => $this->product()->id, 'quantity' => 1]],
            'idempotency_key' => (string) Str::uuid(),
            'channel' => 'Rocket',
            'amount' => '100.00',
            'proof' => UploadedFile::fake()->image('proof.png'),
        ])->assertSessionHasErrors('reference');

        $this->assertDatabaseCount('orders', 0);
    }

    public function test_the_rest_of_a_short_balance_order_can_be_paid_by_rocket_and_the_invoice_lists_both(): void
    {
        $dealer = $this->dealerWithBalance('150.00');
        $finance = User::factory()->staff('finance')->create();

        $this->splitOrder($dealer, 2, 'Rocket', ['reference' => 'ROCKET99887', 'amount' => '50.00'])->assertSessionHasNoErrors();

        $order = Order::where('user_id', $dealer->id)->sole();
        $this->assertSame('0.00', $this->usable($dealer));
        $this->assertDatabaseHas('receipts', ['order_id' => $order->id, 'channel' => 'Account', 'amount' => '150.00']);
        $this->assertDatabaseHas('payment_claims', ['order_id' => $order->id, 'channel' => 'Rocket', 'amount' => '50.00', 'status' => 'pending']);

        $this->actingAs($finance)->onPortal('manage')->post('/commands/claims/'.DB::table('payment_claims')->where('order_id', $order->id)->where('channel', 'Rocket')->value('id').'/verify', ['approve' => '1', 'reason' => 'Money seen in Rocket'])->assertSessionHasNoErrors();

        $order->refresh();
        $this->assertSame('200.00', (string) $order->paid);
        $payload = app(OrderInvoice::class)->payload($order, '');
        $lines = collect($payload['payments'])->map(fn (array $line): string => $line['channel'].' '.$line['amount'])->all();
        $this->assertContains('Wallet / account balance 150.00', $lines);
        $this->assertContains('Rocket 50.00', $lines);
        $this->assertBooksAgree();
    }

    public function test_an_order_just_above_the_balance_is_not_a_single_account_payment(): void
    {
        $dealer = $this->dealerWithBalance('199.99');

        $this->actingAs($dealer)->post('/commands/orders', [
            'items' => [['product_id' => $this->product()->id, 'quantity' => 2]],
            'idempotency_key' => (string) Str::uuid(),
            'channel' => 'Account',
        ])->assertSessionHasErrors('channel');

        $this->assertDatabaseCount('orders', 0);
        $this->assertSame('199.99', $this->usable($dealer));
    }

    public function test_a_split_with_no_second_method_is_refused_and_nothing_is_taken(): void
    {
        $dealer = $this->dealerWithBalance('150.00');

        $this->actingAs($dealer)->post('/commands/orders', [
            'items' => [['product_id' => $this->product()->id, 'quantity' => 2]],
            'idempotency_key' => (string) Str::uuid(),
            'use_account' => true,
        ])->assertSessionHasErrors('channel');

        $this->assertDatabaseCount('orders', 0);
        $this->assertSame('150.00', $this->usable($dealer));
    }

    public function test_a_balance_that_dropped_after_the_page_loaded_is_refused_with_the_new_figures(): void
    {
        $dealer = $this->dealerWithBalance('150.00');
        // The page was shown with 150.00; a withdrawal then took 40.00 before the order was sent.
        DB::table('wallets')->where('user_id', $dealer->id)->update(['available' => '110.00']);

        $this->splitOrder($dealer, 2, 'Rocket', ['reference' => 'ROCKET11122', 'amount' => '50.00'])
            ->assertSessionHasErrors(['amount' => __('order_split.err_split_changed', ['balance' => '110.00', 'rest' => '90.00'])]);

        $this->assertDatabaseCount('orders', 0);
        $this->assertDatabaseCount('receipts', 0);
        $this->assertSame('110.00', $this->usable($dealer));
    }

    public function test_cancelling_a_split_order_returns_exactly_the_account_part(): void
    {
        $dealer = $this->dealerWithBalance('150.00');
        $owner = User::factory()->staff('super_admin')->create();
        $this->splitOrder($dealer, 2, 'Rocket', ['reference' => 'ROCKET55443', 'amount' => '50.00'])->assertSessionHasNoErrors();
        $order = Order::where('user_id', $dealer->id)->sole();
        $this->assertSame('0.00', $this->usable($dealer));

        // The partner asks to cancel; money was taken, so the office decides and returns the account part.
        $asked = app(OrderReturns::class)->ask($dealer, $order, OrderReturns::KindCancel, [], 'I do not need it any more');
        $this->assertFalse($asked['cancelled_now']);
        app(OrderReturns::class)->approve($owner, (int) $asked['id'], '150.00', OrderReturns::MethodAccount, null, 'Return the account part');

        $this->assertSame('cancelled', $order->fresh()->status);
        $this->assertSame('150.00', $this->usable($dealer));
        $this->assertDatabaseHas('order_refunds', ['order_id' => $order->id, 'to_account' => '150.00']);
        $this->assertBooksAgree();
    }

    public function test_the_order_page_tells_a_partner_with_nothing_to_spend_so(): void
    {
        $empty = $this->dealerWithBalance('0.00');
        $this->actingAs($empty)->onPortal('dealer')->get('/purchase')->assertOk()->assertInertia(fn (Assert $page) => $page
            ->where('data.pay_options.account_available', '0.00'));

        $noWallet = User::factory()->dealer()->create();
        $this->actingAs($noWallet)->onPortal('dealer')->get('/purchase')->assertOk()->assertInertia(fn (Assert $page) => $page
            ->where('data.pay_options.account_available', '0.00'));
    }

    /** @param array<string, mixed> $extra */
    private function splitOrder(User $dealer, int $quantity, string $channel, array $extra = []): TestResponse
    {
        return $this->actingAs($dealer)->post('/commands/orders', [
            'items' => [['product_id' => $this->product()->id, 'quantity' => $quantity]],
            'idempotency_key' => (string) Str::uuid(),
            'channel' => $channel,
            'use_account' => true,
            ...($extra === [] ? [] : [...$extra, 'proof' => UploadedFile::fake()->image('proof.png')]),
        ]);
    }

    private function product(): Product
    {
        return Product::where('sku', 'RKT-1')->first() ?? Product::factory()->create(['sku' => 'RKT-1', 'mrp' => '200.00', 'dealer_price' => '100.00', 'stock' => 500]);
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
