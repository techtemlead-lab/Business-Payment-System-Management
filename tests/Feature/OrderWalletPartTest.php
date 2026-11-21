<?php

namespace Tests\Feature;

use App\Domain\Finance\FinancialReadiness;
use App\Models\Order;
use App\Models\Product;
use App\Models\User;
use App\Support\LocalDigits;
use App\Support\Money;
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
 * "Wallet / account balance" at order time: always listed on the order form, with the spendable
 * balance; the partner may use all of it or a smaller part (account_amount), and the rest must be
 * paid another way. Only the spendable balance counts and nothing is ever taken twice.
 */
class OrderWalletPartTest extends TestCase
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

    public function test_the_order_form_always_lists_the_wallet_with_its_spendable_balance(): void
    {
        $form = (string) file_get_contents(resource_path('js/components/ProductPurchaseMatrix.tsx'));
        // Listed whatever the balance; only disabled (with the reason) when it cannot be used.
        $this->assertStringContainsString('<option value={ACCOUNT_CHANNEL} disabled={!accountOffered} data-testid="wallet-option">', $form);
        $this->assertStringNotContainsString('{accountOffered && (', $form);
        $this->assertSame('Wallet / account balance', __('portal.pay_channel_account', [], 'en'));
        $this->assertSame('ওয়ালেট / অ্যাকাউন্ট ব্যালেন্স', __('portal.pay_channel_account', [], 'bn'));

        $dealer = $this->dealerWithBalance('50000.00', held: '7000.00');
        $this->actingAs($dealer)->onPortal('dealer')->get('/purchase')->assertOk()->assertInertia(fn (Assert $page) => $page
            ->where('data.pay_options.account_available', '50000.00')
            ->where('data.pay_options.account_usable', true));
    }

    public function test_a_sixty_thousand_order_takes_fifty_thousand_from_the_wallet_and_the_rest_by_bkash(): void
    {
        $dealer = $this->dealerWithBalance('50000.00', held: '9000.00');
        $product = $this->product('60000.00');

        $this->walletOrder($dealer, $product, 1, '50000.00', 'bKash', ['reference' => 'BK60000REST', 'amount' => '10000.00'])->assertSessionHasNoErrors();

        $order = Order::where('user_id', $dealer->id)->sole();
        $this->assertSame('50000.00', (string) $order->paid);
        $this->assertDatabaseHas('receipts', ['order_id' => $order->id, 'channel' => 'Account', 'amount' => '50000.00']);
        $this->assertDatabaseHas('payment_claims', ['order_id' => $order->id, 'channel' => 'bKash', 'amount' => '10000.00', 'status' => 'pending']);
        // Only the spendable money was used; the held money is untouched.
        $this->assertDatabaseHas('wallets', ['user_id' => $dealer->id, 'available' => '0.00', 'held' => '9000.00']);
    }

    public function test_the_wallet_part_can_be_set_lower_than_the_balance(): void
    {
        $dealer = $this->dealerWithBalance('500.00');

        $this->walletOrder($dealer, $this->product('200.00'), 1, '150.00', 'Rocket', ['reference' => 'ROCKET-PART-1', 'amount' => '50.00'])->assertSessionHasNoErrors();

        $order = Order::where('user_id', $dealer->id)->sole();
        $this->assertDatabaseHas('receipts', ['order_id' => $order->id, 'channel' => 'Account', 'amount' => '150.00']);
        $this->assertDatabaseHas('payment_claims', ['order_id' => $order->id, 'channel' => 'Rocket', 'amount' => '50.00', 'status' => 'pending']);
        $this->assertSame('350.00', $this->usable($dealer));
        $this->assertBooksAgree();
    }

    public function test_the_rest_can_be_cash_at_the_office(): void
    {
        $dealer = $this->dealerWithBalance('500.00');

        $this->walletOrder($dealer, $this->product('200.00'), 1, '120.00', 'Office')->assertSessionHasNoErrors();

        $order = Order::where('user_id', $dealer->id)->sole();
        $this->assertDatabaseHas('receipts', ['order_id' => $order->id, 'channel' => 'Account', 'amount' => '120.00']);
        $this->assertDatabaseHas('payment_claims', ['order_id' => $order->id, 'channel' => 'Office', 'amount' => '80.00', 'status' => 'pending']);
        $this->assertSame('380.00', $this->usable($dealer));
    }

    public function test_a_wallet_part_equal_to_the_total_pays_the_whole_order(): void
    {
        $dealer = $this->dealerWithBalance('500.00');

        // The whole total from the wallet: nothing is left for the second method to pay.
        $this->walletOrder($dealer, $this->product('200.00'), 1, '200.00', 'Office')->assertSessionHasNoErrors();

        $order = Order::where('user_id', $dealer->id)->sole();
        $this->assertSame('200.00', (string) $order->paid);
        $this->assertDatabaseCount('payment_claims', 1);
        $this->assertSame('300.00', $this->usable($dealer));
    }

    public function test_more_than_the_spendable_balance_is_refused_and_nothing_is_taken(): void
    {
        // 300 is held for a withdrawal, so only 100 can be spent.
        $dealer = $this->dealerWithBalance('100.00', held: '300.00');

        $this->walletOrder($dealer, $this->product('200.00'), 1, '150.00', 'bKash', ['reference' => 'BK-OVER-1', 'amount' => '50.00'])
            ->assertSessionHasErrors(['account_amount' => __('order_payment.err_wallet_part_over', [
                'balance' => LocalDigits::text(Money::grouped('100.00')),
                'part' => LocalDigits::text(Money::grouped('150.00')),
            ])]);

        $this->assertDatabaseCount('orders', 0);
        $this->assertDatabaseCount('receipts', 0);
        $this->assertDatabaseHas('wallets', ['user_id' => $dealer->id, 'available' => '100.00', 'held' => '300.00']);
    }

    public function test_a_wallet_part_above_the_order_total_or_zero_is_refused(): void
    {
        $dealer = $this->dealerWithBalance('900.00');

        $this->walletOrder($dealer, $this->product('200.00'), 1, '250.00', 'Office')->assertSessionHasErrors('account_amount');
        $this->walletOrder($dealer, $this->product('200.00'), 1, '0.00', 'Office')->assertSessionHasErrors('account_amount');

        $this->assertDatabaseCount('orders', 0);
        $this->assertSame('900.00', $this->usable($dealer));
    }

    public function test_a_smaller_wallet_part_needs_a_second_method_for_the_rest(): void
    {
        $dealer = $this->dealerWithBalance('500.00');

        $this->actingAs($dealer)->post('/commands/orders', [
            'items' => [['product_id' => $this->product('200.00')->id, 'quantity' => 1]],
            'idempotency_key' => (string) Str::uuid(),
            'use_account' => true,
            'account_amount' => '150.00',
        ])->assertSessionHasErrors('channel');

        $this->assertDatabaseCount('orders', 0);
        $this->assertSame('500.00', $this->usable($dealer));
    }

    public function test_the_rest_amount_must_match_the_chosen_wallet_part(): void
    {
        $dealer = $this->dealerWithBalance('500.00');

        $this->walletOrder($dealer, $this->product('200.00'), 1, '150.00', 'Nagad', ['reference' => 'NG-PART-9', 'amount' => '60.00'])
            ->assertSessionHasErrors('amount');

        $this->assertDatabaseCount('orders', 0);
        $this->assertSame('500.00', $this->usable($dealer));
    }

    public function test_sending_the_same_wallet_order_twice_takes_the_money_once(): void
    {
        $dealer = $this->dealerWithBalance('500.00');
        $product = $this->product('200.00');
        $key = (string) Str::uuid();
        $proof = UploadedFile::fake()->image('proof.png');
        $payload = [
            'items' => [['product_id' => $product->id, 'quantity' => 1]],
            'idempotency_key' => $key,
            'channel' => 'bKash',
            'use_account' => true,
            'account_amount' => '150.00',
            'reference' => 'BK-TWICE-1',
            'amount' => '50.00',
            'proof' => $proof,
        ];

        $this->actingAs($dealer)->post('/commands/orders', $payload)->assertSessionHasNoErrors();
        $this->actingAs($dealer)->post('/commands/orders', $payload)->assertSessionHasNoErrors();

        $this->assertDatabaseCount('orders', 1);
        $this->assertDatabaseCount('receipts', 1);
        $this->assertSame('350.00', $this->usable($dealer));
        $this->assertBooksAgree();
    }

    /** @param array<string, string> $rest */
    private function walletOrder(User $dealer, Product $product, int $quantity, string $walletPart, string $channel, array $rest = []): TestResponse
    {
        return $this->actingAs($dealer)->post('/commands/orders', [
            'items' => [['product_id' => $product->id, 'quantity' => $quantity]],
            'idempotency_key' => (string) Str::uuid(),
            'channel' => $channel,
            'use_account' => true,
            'account_amount' => $walletPart,
            ...($rest === [] ? [] : [...$rest, 'proof' => UploadedFile::fake()->image('proof.png')]),
        ]);
    }

    private function product(string $dealerPrice): Product
    {
        return Product::factory()->create(['mrp' => Money::decimal(Money::cents($dealerPrice) * 2), 'dealer_price' => $dealerPrice, 'stock' => 500]);
    }

    private function dealerWithBalance(string $available, string $held = '0.00'): User
    {
        $dealer = User::factory()->dealer()->create();
        DB::table('wallets')->insert(['user_id' => $dealer->id, 'available' => $available, 'held' => $held, 'commission_held' => '0.00', 'created_at' => now(), 'updated_at' => now()]);
        $total = Money::decimal(Money::cents($available) + Money::cents($held));
        DB::table('journal_entries')->insert(['id' => $entry = (string) Str::uuid(), 'event_key' => 'test.opening.'.$dealer->id, 'description' => 'Test opening balance', 'created_at' => now()]);
        DB::table('journal_lines')->insert([
            ['entry_id' => $entry, 'user_id' => $dealer->id, 'account' => 'wallet_liability', 'debit' => '0.00', 'credit' => $total],
            ['entry_id' => $entry, 'user_id' => null, 'account' => 'commission_expense', 'debit' => $total, 'credit' => '0.00'],
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
