<?php

namespace Tests\Feature;

use App\Domain\Notifications\SmsText;
use App\Domain\Orders\OrderService;
use App\Domain\Payouts\ManualPayoutService;
use App\Domain\Payouts\PayoutLimits;
use App\Models\Order;
use App\Models\Product;
use App\Models\User;
use App\Support\DeploymentReadiness;
use Database\Seeders\CommissionRuleSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Tests\Support\ReceiptImage;
use Tests\TestCase;

/**
 * Money limits and payment checks after the 2026-11-18 company test: per-method withdrawal limits
 * with a plain message (MA-08), a cap on cash promised at the office and a cash count when it is
 * confirmed (MA-17), a fresh reference for a second office payment (MA-21), an NID before the first
 * withdrawal and a TIN (LC-06, decision D12), and one way to write big amounts in SMS (MA-18).
 */
class PaymentCheckAndPayoutLimitsTest extends TestCase
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

    public function test_a_withdrawal_above_its_methods_limit_gets_a_plain_message_with_the_limit_and_balance(): void
    {
        $dealer = $this->partnerWithWallet('90000.00');

        $this->actingAs($dealer)->onPortal('dealer')->post('/commands/withdrawals', $this->request('50000.00', 'bKash'))
            ->assertSessionHasErrors(['amount' => __('payouts.err_channel_limit', ['channel' => __('portal.pay_channel_bkash'), 'limit' => '৳30,000', 'balance' => '৳90,000'])]);
        // Bank has no limit of its own; the same amount goes through.
        $this->actingAs($dealer)->onPortal('dealer')->post('/commands/withdrawals', $this->request('50000.00', 'Bank'))->assertSessionHasNoErrors();
    }

    public function test_an_amount_above_the_balance_names_the_balance_instead_of_saying_it_is_badly_written(): void
    {
        $dealer = $this->partnerWithWallet('1000.00');

        $this->actingAs($dealer)->onPortal('dealer')->post('/commands/withdrawals', $this->request('100000000.00', 'Bank'))
            ->assertSessionHasErrors(['amount' => __('payouts.err_over_balance', ['balance' => '৳1,000'])]);
        $this->actingAs($dealer)->onPortal('dealer')->post('/commands/withdrawals', $this->request('12,000', 'Bank'))
            ->assertSessionHasErrors(['amount' => __('payouts.err_amount_digits')]);
    }

    public function test_an_old_request_above_the_limit_cannot_be_approved(): void
    {
        $dealer = $this->partnerWithWallet('90000.00');
        $id = app(ManualPayoutService::class)->request($dealer, ['amount' => '50000.00', 'channel' => 'Bank', 'destination' => '0123456789', 'idempotency_key' => (string) Str::uuid()]);
        DB::table('withdrawals')->where('id', $id)->update(['channel' => 'Rocket']);

        try {
            app(ManualPayoutService::class)->review(User::factory()->staff('finance')->create(), $id, 'approved', 'Checked');
            $this->fail('An over-limit request was approved.');
        } catch (ValidationException $exception) {
            $this->assertStringContainsString('৳30,000', (string) $exception->validator->errors()->first('withdrawal'));
        }
        $this->assertSame('requested', DB::table('withdrawals')->where('id', $id)->value('status'));
    }

    public function test_the_owner_sets_the_limits_and_others_cannot(): void
    {
        $owner = User::factory()->staff('super_admin')->create();
        $finance = User::factory()->staff('finance')->create();
        $limits = ['per_channel' => ['Bank' => '', 'bKash' => '25000', 'Nagad' => '25000', 'Rocket' => ''], 'office_cash' => '150000', 'due_days' => 2, 'version' => 0, 'reason' => 'Mobile money limits from the providers'];

        $this->actingAs($finance)->onPortal('manage')->post('/commands/operations/settings/payout-limits', $limits)->assertForbidden();
        $this->actingAs($owner)->onPortal('sadmin')->post('/commands/operations/settings/payout-limits', $limits)->assertSessionHasNoErrors();

        $saved = app(PayoutLimits::class)->settings();
        $this->assertSame(['Bank' => null, 'bKash' => '25000.00', 'Nagad' => '25000.00', 'Rocket' => null], $saved['per_channel']);
        $this->assertSame('150000.00', $saved['office_cash']);
        $this->assertSame(2, $saved['due_days']);
        $this->assertDatabaseHas('audit_events', ['action' => 'payout.limits_changed', 'actor_id' => $owner->id]);
    }

    public function test_cash_promised_at_the_office_is_capped(): void
    {
        $dealer = User::factory()->dealer()->create();
        $product = Product::factory()->create(['mrp' => '200000.00', 'dealer_price' => '150000.00', 'stock' => 50]);

        $this->actingAs($dealer)->post('/commands/orders', ['items' => [['product_id' => $product->id, 'quantity' => 2]], 'idempotency_key' => (string) Str::uuid(), 'channel' => 'Office'])
            ->assertSessionHasErrors(['channel' => __('payouts.err_office_cash_limit', ['limit' => '৳2,00,000', 'amount' => '৳3,00,000 (3 lakh taka)'])]);
        $this->assertSame(0, Order::where('user_id', $dealer->id)->count());
    }

    public function test_office_cash_is_confirmed_only_with_the_full_amount_counted(): void
    {
        $dealer = User::factory()->dealer()->create();
        $finance = User::factory()->staff('finance')->create();
        $this->officeOrder($dealer);
        $claim = DB::table('payment_claims')->where('channel', 'Office')->sole();
        $verify = fn (array $data) => $this->actingAs($finance)->onPortal('manage')->post('/commands/claims/'.$claim->id.'/verify', ['approve' => '1', 'reason' => 'Cash at the counter', ...$data]);

        $verify([])->assertSessionHasErrors(['cash_counted' => __('payouts.err_cash_count_needed')]);
        $verify(['cash_counted' => '150.00'])->assertSessionHasErrors('cash_counted');
        $this->assertSame('pending', DB::table('payment_claims')->where('id', $claim->id)->value('status'));

        $verify(['cash_counted' => '200'])->assertSessionHasNoErrors();
        $this->assertSame('verified', DB::table('payment_claims')->where('id', $claim->id)->value('status'));
    }

    public function test_a_second_office_payment_on_the_same_order_gets_its_own_reference(): void
    {
        $dealer = User::factory()->dealer()->create();
        $finance = User::factory()->staff('finance')->create();
        $order = $this->officeOrder($dealer);
        $first = DB::table('payment_claims')->where('order_id', $order->id)->sole();
        app(OrderService::class)->verify($finance, (int) $first->id, false, 'No cash came');

        $this->actingAs($dealer)->onPortal('dealer')->post('/commands/orders/'.$order->id.'/payments', ['channel' => 'Office'])->assertSessionHasNoErrors();

        $references = DB::table('payment_claims')->where('order_id', $order->id)->orderBy('id')->pluck('reference')->all();
        $this->assertSame(['OFC-'.$order->number, 'OFC-'.$order->number.'-2'], $references);
    }

    public function test_the_payment_queue_lists_waiting_payments_oldest_first_and_filters_by_checker(): void
    {
        $owner = User::factory()->staff('super_admin')->create();
        $finance = User::factory()->staff('finance')->create(['name' => 'Checker Rina']);
        $older = $this->officeOrder(User::factory()->dealer()->create());
        $this->travel(1)->hours();
        $this->officeOrder(User::factory()->dealer()->create());

        $this->actingAs($owner)->onPortal('sadmin')->get('/payments?status=pending')->assertOk()
            ->assertInertia(fn ($page) => $page->where('data.payments_table.rows.data.0.number', $older->number));

        $claim = DB::table('payment_claims')->where('order_id', $older->id)->sole();
        app(OrderService::class)->verify($finance, (int) $claim->id, true, 'Counted', '200.00');
        $this->actingAs($owner)->onPortal('sadmin')->get('/payments?checked_by='.$finance->id)->assertOk()
            ->assertInertia(fn ($page) => $page->has('data.payments_table.rows.data', 1)->where('data.payments_table.rows.data.0.checked_by', 'Checker Rina'));
    }

    public function test_a_partner_with_no_national_id_cannot_make_a_first_withdrawal_but_can_save_a_tin(): void
    {
        $dealer = $this->partnerWithWallet('5000.00');
        DB::table('users')->where('id', $dealer->id)->update(['nid' => null]);
        $dealer->refresh();

        $this->actingAs($dealer)->onPortal('dealer')->post('/commands/withdrawals', $this->request('1000.00', 'Bank'))
            ->assertSessionHasErrors(['amount' => __('payouts.err_nid_first')]);

        $this->actingAs($dealer)->onPortal('dealer')->post('/commands/withdrawals/tax-number', ['tin' => '১২৩৪৫৬৭৮৯০১২'])->assertSessionHasNoErrors();
        $this->assertSame('123456789012', $dealer->fresh()->tin);
        $this->actingAs($dealer)->onPortal('dealer')->post('/commands/withdrawals/tax-number', ['tin' => '12345'])->assertSessionHasErrors('tin');

        DB::table('users')->where('id', $dealer->id)->update(['nid' => encrypt('1990123456789', false)]);
        $this->actingAs($dealer->fresh())->onPortal('dealer')->post('/commands/withdrawals', $this->request('1000.00', 'Bank'))->assertSessionHasNoErrors();
    }

    public function test_readiness_warns_while_no_tax_is_withheld_from_payouts(): void
    {
        $this->assertContains('payout_tax_rate_zero', app(DeploymentReadiness::class)->inspect(false)['warnings']);
    }

    public function test_sms_write_big_amounts_with_grouping_and_words(): void
    {
        $text = SmsText::make('sms.withdrawal_paid', ['amount' => '12000000.00', 'channel' => 'Bank']);

        $this->assertStringContainsString('৳১,২০,০০,০০০ (১ কোটি ২০ লাখ টাকা)', $text);
        $this->assertStringNotContainsString('12000000.00', $text);
        $this->assertTrue(SmsText::hasBanglaLetters($text));
    }

    public function test_a_blank_payment_picture_from_a_partner_is_kept_but_flagged_for_the_checker(): void
    {
        $dealer = User::factory()->dealer()->create();
        $blank = $this->unpaidOrder($dealer);
        $real = $this->unpaidOrder($dealer);

        $this->actingAs($dealer)->postJson('/api/v1/orders/'.$blank->id.'/payments', ['channel' => 'Nagad', 'reference' => 'NGD9X7Q2M1', 'amount' => '500.00',
            'proof' => UploadedFile::fake()->image('white.png', 400, 300)])->assertSuccessful();
        $this->actingAs($dealer)->postJson('/api/v1/orders/'.$real->id.'/payments', ['channel' => 'Nagad', 'reference' => 'NGD9X7Q2M2', 'amount' => '500.00',
            'proof' => ReceiptImage::make('nagad.png')])->assertSuccessful();

        $this->assertTrue((bool) DB::table('payment_claims')->where('reference', 'NGD9X7Q2M1')->value('proof_looks_blank'));
        $this->assertFalse((bool) DB::table('payment_claims')->where('reference', 'NGD9X7Q2M2')->value('proof_looks_blank'));
    }

    private function unpaidOrder(User $buyer): Order
    {
        return Order::create(['number' => 'ORD-'.Str::upper(Str::random(8)), 'user_id' => $buyer->id, 'status' => 'submitted', 'total' => '500.00', 'paid' => '0.00',
            'idempotency_key' => (string) Str::uuid(), 'payload_hash' => hash('sha256', Str::random())]);
    }

    private function officeOrder(User $dealer): Order
    {
        $product = Product::factory()->create(['mrp' => '200.00', 'dealer_price' => '100.00', 'stock' => 50]);
        $this->actingAs($dealer)->post('/commands/orders', ['items' => [['product_id' => $product->id, 'quantity' => 2]], 'idempotency_key' => (string) Str::uuid(), 'channel' => 'Office'])
            ->assertSessionHasNoErrors();

        return Order::where('user_id', $dealer->id)->latest()->firstOrFail();
    }

    /** @return array<string, string> */
    private function request(string $amount, string $channel): array
    {
        return ['amount' => $amount, 'channel' => $channel, 'destination' => '01700000000', 'idempotency_key' => (string) Str::uuid()];
    }

    private function partnerWithWallet(string $available): User
    {
        $dealer = User::factory()->dealer()->create(['status' => 'active']);
        DB::table('wallets')->updateOrInsert(['user_id' => $dealer->id], [
            'available' => $available, 'held' => '0.00', 'created_at' => now(), 'updated_at' => now(),
        ]);

        return $dealer;
    }
}
