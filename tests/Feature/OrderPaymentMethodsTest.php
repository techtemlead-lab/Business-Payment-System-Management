<?php

namespace Tests\Feature;

use App\Domain\Accounts\PartnerWalletStatement;
use App\Domain\Commissions\CommissionWallet;
use App\Domain\Finance\AccountingStatements;
use App\Domain\Finance\FinancialReadiness;
use App\Domain\Finance\ReportData;
use App\Domain\Orders\OrderRefund;
use App\Domain\Orders\OrderService;
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
use Illuminate\Validation\ValidationException;
use Inertia\Testing\AssertableInertia as Assert;
use Symfony\Component\HttpFoundation\Response;
use Tests\TestCase;

/**
 * The two payment methods added for ordering: By Office (cash at the office, staff confirm)
 * and From My Account (taken from the usable balance only, straight away).
 */
class OrderPaymentMethodsTest extends TestCase
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

    public function test_a_balance_exactly_equal_to_the_total_pays_for_the_order(): void
    {
        $dealer = $this->dealerWithBalance('200.00');

        $this->order($dealer, $this->product(), 2)->assertSessionHasNoErrors()->assertRedirect();

        $order = Order::where('user_id', $dealer->id)->sole();
        $this->assertSame('200.00', (string) $order->paid);
        $this->assertSame('submitted', $order->status);
        $this->assertSame('0.00', $this->usable($dealer));

        $claim = DB::table('payment_claims')->where('order_id', $order->id)->sole();
        $this->assertSame('Account', $claim->channel);
        $this->assertSame('ACC-'.$order->number, $claim->reference);
        $this->assertSame('200.00', (string) $claim->amount);
        $this->assertSame('verified', $claim->status);
        $this->assertNull($claim->verified_by);
        $this->assertNull($claim->proof_path);
        $this->assertDatabaseHas('receipts', ['order_id' => $order->id, 'claim_id' => $claim->id, 'channel' => 'Account', 'reference' => 'ACC-'.$order->number, 'amount' => '200.00']);

        $entry = DB::table('journal_entries')->where('event_key', 'order.account_pay.'.$order->id)->sole();
        $lines = DB::table('journal_lines')->where('entry_id', $entry->id)->orderBy('account')->get();
        $this->assertCount(2, $lines);
        $this->assertSame(['wallet_liability', 'wallet_order_clearing'], $lines->pluck('account')->sort()->values()->all());
        $wallet = $lines->firstWhere('account', 'wallet_liability');
        $this->assertSame($dealer->id, (int) $wallet->user_id);
        $this->assertSame('200.00', (string) $wallet->debit);
        $this->assertSame('0.00', (string) $wallet->credit);
        $clearing = $lines->firstWhere('account', 'wallet_order_clearing');
        $this->assertNull($clearing->user_id);
        $this->assertSame('200.00', (string) $clearing->credit);
        $this->assertBooksAgree();
    }

    public function test_short_by_one_paisa_is_refused_and_says_what_is_missing(): void
    {
        $dealer = $this->dealerWithBalance('199.99');

        $this->order($dealer, $this->product(), 2)->assertSessionHasErrors([
            'channel' => $this->shortMessage('199.99', '200.00', '0.01'),
        ]);

        $this->assertDatabaseCount('orders', 0);
        $this->assertDatabaseCount('payment_claims', 0);
        $this->assertDatabaseCount('receipts', 0);
        $this->assertSame('199.99', $this->usable($dealer));
    }

    public function test_held_and_waiting_money_is_never_spent(): void
    {
        $dealer = $this->dealerWithBalance('50.00', held: '900.00', commissionHeld: '600.00');

        $this->order($dealer, $this->product(), 1)->assertSessionHasErrors(['channel' => $this->shortMessage('50.00', '100.00', '50.00')]);

        $this->assertDatabaseCount('orders', 0);
        $this->assertDatabaseHas('wallets', ['user_id' => $dealer->id, 'available' => '50.00', 'held' => '900.00', 'commission_held' => '600.00']);
    }

    public function test_a_partner_with_no_wallet_yet_is_refused(): void
    {
        $dealer = User::factory()->dealer()->create();

        $this->order($dealer, $this->product(), 1)->assertSessionHasErrors(['channel' => $this->shortMessage('0.00', '100.00', '100.00')]);

        $this->assertDatabaseCount('orders', 0);
    }

    public function test_the_same_key_charges_once(): void
    {
        $dealer = $this->dealerWithBalance('500.00');
        $product = $this->product();
        $key = (string) Str::uuid();

        $this->order($dealer, $product, 2, key: $key)->assertSessionHasNoErrors();
        $this->order($dealer, $product, 2, key: $key)->assertSessionHasNoErrors();

        $this->assertDatabaseCount('orders', 1);
        $this->assertDatabaseCount('payment_claims', 1);
        $this->assertDatabaseCount('receipts', 1);
        $this->assertSame(1, DB::table('journal_entries')->where('event_key', 'like', 'order.account_pay.%')->count());
        $this->assertSame('300.00', $this->usable($dealer));
    }

    public function test_the_same_key_with_a_different_order_is_refused_and_charges_nothing_more(): void
    {
        $dealer = $this->dealerWithBalance('500.00');
        $product = $this->product();
        $key = (string) Str::uuid();

        $this->order($dealer, $product, 2, key: $key)->assertSessionHasNoErrors();
        $this->order($dealer, $product, 3, key: $key)->assertStatus(409);

        $this->assertDatabaseCount('orders', 1);
        $this->assertSame('300.00', $this->usable($dealer));
    }

    public function test_staff_can_never_spend_a_partners_money(): void
    {
        $dealer = $this->dealerWithBalance('500.00');
        $owner = User::factory()->staff('super_admin')->create();

        try {
            app(OrderService::class)->create($dealer, [
                'items' => [['product_id' => $this->product()->id, 'quantity' => 1]],
                'idempotency_key' => (string) Str::uuid(),
                'channel' => 'Account',
            ], $owner);
            $this->fail('A staff-placed order must not take money from the partner.');
        } catch (ValidationException $refused) {
            $this->assertArrayHasKey('channel', $refused->errors());
        }

        $this->assertDatabaseCount('orders', 0);
        $this->assertDatabaseCount('receipts', 0);
        $this->assertSame('500.00', $this->usable($dealer));
    }

    public function test_the_field_sales_order_form_cannot_use_a_partners_money_either(): void
    {
        $dealer = $this->dealerWithBalance('500.00');
        $owner = User::factory()->staff('super_admin')->create();

        $this->actingAs($owner)->onPortal('sadmin')->post('/commands/operations/staff/orders', [
            'user_id' => $dealer->id,
            'items' => [['product_id' => $this->product()->id, 'quantity' => 1]],
            'idempotency_key' => (string) Str::uuid(),
            'channel' => 'Account',
        ])->assertSessionHasErrors(['channel' => __('order_payment.err_account_staff')]);

        $this->assertDatabaseCount('orders', 0);
        $this->assertSame('500.00', $this->usable($dealer));
    }

    public function test_a_partner_who_owes_money_back_is_refused(): void
    {
        $dealer = $this->dealerWithBalance('500.00');
        $old = $this->unpaidOrder($dealer);
        DB::table('commission_allocations')->insert(['order_id' => $old->id, 'user_id' => $dealer->id, 'category' => 'direct_referral', 'rate_percentage' => '5.00',
            'cash_basis' => '100.00', 'amount' => '0.00', 'status' => 'available', 'metadata' => json_encode(['unrecovered_deficit' => '12.00']), 'created_at' => now(), 'updated_at' => now()]);

        $this->order($dealer, $this->product(), 1)->assertSessionHasErrors(['channel' => __('order_payment.err_account_debt')]);

        $this->assertSame(1, Order::where('user_id', $dealer->id)->count());
        $this->assertSame('500.00', $this->usable($dealer));
        $this->actingAs($dealer)->onPortal('dealer')->get('/purchase')->assertOk()->assertInertia(fn (Assert $page) => $page
            ->where('data.pay_options.account_usable', false)
            ->where('data.pay_options.account_block', 'debt'));
    }

    public function test_when_money_movement_is_off_the_account_is_refused_and_not_offered(): void
    {
        $dealer = $this->dealerWithBalance('500.00');
        config(['madina.financial_activation' => false]);

        $this->order($dealer, $this->product(), 1)->assertSessionHasErrors(['channel' => __('order_payment.err_account_off')]);

        $this->assertDatabaseCount('orders', 0);
        $this->assertSame('500.00', $this->usable($dealer));
        $this->actingAs($dealer)->onPortal('dealer')->get('/purchase')->assertOk()->assertInertia(fn (Assert $page) => $page
            ->where('data.pay_options.account_usable', false)
            ->where('data.pay_options.account_block', 'off'));
    }

    public function test_the_order_page_is_told_the_usable_balance(): void
    {
        $dealer = $this->dealerWithBalance('8500.00');

        $this->actingAs($dealer)->onPortal('dealer')->get('/purchase')->assertOk()->assertInertia(fn (Assert $page) => $page
            ->where('data.pay_options.account_available', '8500.00')
            ->where('data.pay_options.account_usable', true)
            ->where('data.pay_options.account_block', null));
    }

    public function test_the_server_sets_the_payment_details_for_both_methods(): void
    {
        $dealer = $this->dealerWithBalance('500.00');
        $product = $this->product();

        foreach (['Office', 'Account'] as $channel) {
            $this->actingAs($dealer)->post('/commands/orders', [
                'items' => [['product_id' => $product->id, 'quantity' => 1]], 'idempotency_key' => (string) Str::uuid(), 'channel' => $channel,
                'reference' => 'MYOWNREF1', 'amount' => '1.00', 'payment_account_id' => 1, 'proof' => UploadedFile::fake()->image('x.png'),
            ])->assertSessionHasErrors(['reference', 'amount', 'payment_account_id', 'proof']);
        }

        $this->assertDatabaseCount('orders', 0);
    }

    public function test_by_office_needs_nothing_from_the_partner_and_waits_for_staff(): void
    {
        $dealer = $this->dealerWithBalance('0.00');

        $this->order($dealer, $this->product(), 2, 'Office')->assertSessionHasNoErrors()->assertRedirect();

        $order = Order::where('user_id', $dealer->id)->sole();
        $this->assertSame('0.00', (string) $order->paid);
        $claim = DB::table('payment_claims')->where('order_id', $order->id)->sole();
        $this->assertSame('Office', $claim->channel);
        $this->assertSame('OFC-'.$order->number, $claim->reference);
        $this->assertSame('200.00', (string) $claim->amount);
        $this->assertSame('pending', $claim->status);
        $this->assertNull($claim->proof_path);
        $this->assertNull($claim->payment_account_id);
        $this->assertDatabaseCount('receipts', 0);
        $this->assertDatabaseMissing('journal_entries', ['event_key' => 'order.account_pay.'.$order->id]);
    }

    public function test_by_office_confirm_then_approve(): void
    {
        $dealer = User::factory()->dealer()->create();
        $finance = User::factory()->staff('finance')->create();
        $reviewer = User::factory()->staff('super_admin')->create();
        $this->order($dealer, $this->product(), 2, 'Office')->assertSessionHasNoErrors();
        $order = Order::where('user_id', $dealer->id)->sole();
        $claim = DB::table('payment_claims')->where('order_id', $order->id)->sole();

        $this->actingAs($reviewer)->onPortal('sadmin')->post('/commands/orders/'.$order->id.'/decision', ['status' => 'approved', 'reason' => 'Looks fine'])
            ->assertSessionHasErrors('status');
        $this->assertSame('submitted', $order->fresh()->status);

        $this->actingAs($finance)->onPortal('manage')->post('/commands/claims/'.$claim->id.'/verify', ['approve' => '1', 'reason' => 'Rahim took the cash at the counter'])
            ->assertSessionHasNoErrors();

        $this->assertSame('200.00', (string) $order->fresh()->paid);
        $this->assertDatabaseHas('receipts', ['order_id' => $order->id, 'channel' => 'Office', 'reference' => 'OFC-'.$order->number, 'amount' => '200.00']);
        $this->assertDatabaseHas('payment_claims', ['id' => $claim->id, 'status' => 'verified', 'verified_by' => $finance->id, 'reason' => 'Rahim took the cash at the counter']);
        $this->assertSame(0, DB::table('journal_entries')->where('event_key', 'like', 'order.%')->count());

        $this->actingAs($reviewer)->onPortal('sadmin')->post('/commands/orders/'.$order->id.'/decision', ['status' => 'approved', 'reason' => 'Cash confirmed'])
            ->assertSessionHasNoErrors();
        $this->assertSame('approved', $order->fresh()->status);
        $this->assertBooksAgree();
    }

    public function test_rejecting_an_unpaid_by_office_order_closes_its_claim(): void
    {
        $dealer = User::factory()->dealer()->create();
        $reviewer = User::factory()->staff('super_admin')->create();
        $this->order($dealer, $this->product(), 1, 'Office')->assertSessionHasNoErrors();
        $order = Order::where('user_id', $dealer->id)->sole();

        $this->actingAs($reviewer)->onPortal('sadmin')->post('/commands/orders/'.$order->id.'/decision', ['status' => 'rejected', 'reason' => 'No cash came'])
            ->assertSessionHasNoErrors();

        $this->assertSame('rejected', $order->fresh()->status);
        $this->assertDatabaseHas('payment_claims', ['order_id' => $order->id, 'channel' => 'Office', 'status' => 'rejected', 'verified_by' => $reviewer->id, 'reason' => 'No cash came']);
        $this->assertDatabaseCount('receipts', 0);
        $this->assertDatabaseCount('order_refunds', 0);
    }

    public function test_rejecting_a_paid_by_office_order_still_needs_the_refund_by_hand(): void
    {
        $dealer = User::factory()->dealer()->create();
        $finance = User::factory()->staff('finance')->create();
        $reviewer = User::factory()->staff('super_admin')->create();
        $this->order($dealer, $this->product(), 1, 'Office')->assertSessionHasNoErrors();
        $order = Order::where('user_id', $dealer->id)->sole();
        app(OrderService::class)->verify($finance, (int) DB::table('payment_claims')->where('order_id', $order->id)->value('id'), true, 'Cash taken');

        $this->actingAs($reviewer)->onPortal('sadmin')->post('/commands/orders/'.$order->id.'/decision', ['status' => 'rejected', 'reason' => 'Out of stock'])
            ->assertSessionHasErrors(['status' => __('portal.err_order_paid_needs_refund')]);

        $this->assertSame('submitted', $order->fresh()->status);
    }

    public function test_rejecting_an_order_paid_from_the_account_returns_the_money_at_once(): void
    {
        $dealer = $this->dealerWithBalance('8500.00');
        $reviewer = User::factory()->staff('super_admin')->create();
        $this->order($dealer, $this->product(), 60)->assertSessionHasNoErrors();
        $order = Order::where('user_id', $dealer->id)->sole();
        $this->assertSame('2500.00', $this->usable($dealer));
        $this->assertBooksAgree();

        $this->actingAs($reviewer)->onPortal('sadmin')->post('/commands/orders/'.$order->id.'/decision', ['status' => 'rejected', 'reason' => 'Out of stock'])
            ->assertSessionHasNoErrors();

        $this->assertSame('rejected', $order->fresh()->status);
        $this->assertSame('8500.00', $this->usable($dealer));
        $refund = DB::table('order_refunds')->where('order_id', $order->id)->sole();
        $this->assertSame('6000.00', (string) $refund->amount);
        $this->assertSame('6000.00', (string) $refund->to_account);
        $this->assertSame($reviewer->id, (int) $refund->refunded_by);
        $entry = DB::table('journal_entries')->where('event_key', 'order.account_return.'.$refund->id)->sole();
        $this->assertDatabaseHas('journal_lines', ['entry_id' => $entry->id, 'account' => 'wallet_order_clearing', 'debit' => '6000.00']);
        $this->assertDatabaseHas('journal_lines', ['entry_id' => $entry->id, 'account' => 'wallet_liability', 'user_id' => $dealer->id, 'credit' => '6000.00']);
        $this->assertDatabaseHas('account_notices', ['user_id' => $dealer->id, 'title' => __('order_payment.notice_returned_title', [], $dealer->preferredLocale())]);
        $this->assertBooksAgree();
    }

    public function test_a_refund_before_dispatch_returns_the_account_money(): void
    {
        $dealer = $this->dealerWithBalance('500.00');
        $finance = User::factory()->staff('finance')->create();
        $this->order($dealer, $this->product(), 2)->assertSessionHasNoErrors();
        $order = Order::where('user_id', $dealer->id)->sole();

        $this->actingAs($finance)->onPortal('manage')->post('/commands/orders/'.$order->id.'/refund', ['amount' => '200.00', 'reason' => 'Partner changed their mind'])
            ->assertSessionHasNoErrors();

        $this->assertSame('cancelled', $order->fresh()->status);
        $this->assertSame('200.00', (string) $order->fresh()->paid);
        $this->assertSame('500.00', $this->usable($dealer));
        $this->assertDatabaseHas('order_refunds', ['order_id' => $order->id, 'amount' => '200.00', 'to_account' => '200.00']);
        $this->assertBooksAgree();
        $this->actingAs($finance)->onPortal('manage')->get('/orders/'.$order->id)->assertOk()->assertInertia(fn (Assert $page) => $page->where('refund.to_account', '200.00'));
    }

    public function test_a_part_refund_returns_only_that_part(): void
    {
        $dealer = $this->dealerWithBalance('500.00');
        $finance = User::factory()->staff('finance')->create();
        $this->order($dealer, $this->product(), 2)->assertSessionHasNoErrors();
        $order = Order::where('user_id', $dealer->id)->sole();

        app(OrderRefund::class)->refund($finance, $order, '150.00', 'Part of the goods are unavailable');

        $this->assertSame('450.00', $this->usable($dealer));
        $this->assertDatabaseHas('order_refunds', ['order_id' => $order->id, 'amount' => '150.00', 'to_account' => '150.00']);
        $this->assertBooksAgree();
    }

    public function test_a_refund_of_a_bank_paid_order_touches_no_account(): void
    {
        $dealer = $this->dealerWithBalance('500.00');
        $finance = User::factory()->staff('finance')->create();
        $order = $this->unpaidOrder($dealer);
        $claim = DB::table('payment_claims')->insertGetId(['order_id' => $order->id, 'channel' => 'Bank', 'reference' => 'BANK-REF-1', 'amount' => '100.00', 'created_at' => now(), 'updated_at' => now()]);
        app(OrderService::class)->verify($finance, $claim, true, 'Matched');

        app(OrderRefund::class)->refund($finance, $order->fresh(), '100.00', 'Dealer changed their mind');

        $this->assertSame('500.00', $this->usable($dealer));
        $this->assertDatabaseHas('order_refunds', ['order_id' => $order->id, 'to_account' => '0.00']);
        $this->assertBooksAgree();
    }

    public function test_money_comes_back_after_clearing_an_old_debt(): void
    {
        $dealer = $this->dealerWithBalance('200.00');
        $finance = User::factory()->staff('finance')->create();
        $this->order($dealer, $this->product(), 2)->assertSessionHasNoErrors();
        $order = Order::where('user_id', $dealer->id)->sole();
        $old = $this->unpaidOrder($dealer);
        DB::table('commission_allocations')->insert(['order_id' => $old->id, 'user_id' => $dealer->id, 'category' => 'direct_referral', 'rate_percentage' => '5.00',
            'cash_basis' => '100.00', 'amount' => '0.00', 'status' => 'available', 'metadata' => json_encode(['unrecovered_deficit' => '30.00']), 'created_at' => now(), 'updated_at' => now()]);

        app(OrderRefund::class)->refund($finance, $order, '200.00', 'Stock ran out');

        $this->assertSame('170.00', $this->usable($dealer));
        $this->assertFalse(CommissionWallet::hasDebt($dealer->id));
    }

    public function test_commission_at_delivery_is_identical_to_a_bank_paid_order(): void
    {
        $staff = User::factory()->staff('super_admin')->create();
        $accountBuyer = $this->dealerWithBalance('500.00');
        $bankBuyer = User::factory()->dealer()->create();
        $product = $this->product();

        $this->order($accountBuyer, $product, 2)->assertSessionHasNoErrors();
        $accountOrder = Order::where('user_id', $accountBuyer->id)->sole();
        $id = $this->actingAs($bankBuyer)->postJson('/api/v1/orders', [
            'items' => [['product_id' => $product->id, 'quantity' => 2]], 'idempotency_key' => (string) Str::uuid(),
            'channel' => 'Bank', 'reference' => 'BANK-PAID-1', 'amount' => '200.00', 'proof' => UploadedFile::fake()->image('slip.jpg'),
        ])->assertCreated()->json('data.id');
        $this->actingAs($staff)->postJson('/api/v1/claims/'.DB::table('payment_claims')->where('order_id', $id)->value('id').'/verify', ['approve' => true, 'reason' => 'Matched'])->assertOk();
        $bankOrder = Order::findOrFail($id);

        $this->deliver($accountOrder, $staff);
        $this->deliver($bankOrder, $staff);

        $this->assertSame('delivered', $accountOrder->fresh()->status);
        $this->assertNotEmpty($this->commissionOf($bankOrder)['rows']);
        $this->assertSame($this->commissionOf($bankOrder), $this->commissionOf($accountOrder));
        $this->assertBooksAgree();
    }

    public function test_paying_an_existing_order_from_the_account(): void
    {
        $dealer = $this->dealerWithBalance('250.00');
        $order = $this->unpaidOrder($dealer);

        $this->actingAs($dealer)->onPortal('dealer')->post('/commands/orders/'.$order->id.'/pay-from-account', [])->assertSessionHasNoErrors()->assertRedirect();

        $this->assertSame('100.00', (string) $order->fresh()->paid);
        $this->assertSame('150.00', $this->usable($dealer));
        $this->assertDatabaseHas('receipts', ['order_id' => $order->id, 'channel' => 'Account', 'reference' => 'ACC-'.$order->number, 'amount' => '100.00']);

        // A second click charges nothing more.
        $this->actingAs($dealer)->onPortal('dealer')->post('/commands/orders/'.$order->id.'/pay-from-account', [])->assertSessionHasNoErrors();
        $this->assertSame('150.00', $this->usable($dealer));
        $this->assertDatabaseCount('receipts', 1);
        $this->assertBooksAgree();
    }

    public function test_paying_an_existing_order_answers_json_when_asked(): void
    {
        $dealer = $this->dealerWithBalance('250.00');
        $order = $this->unpaidOrder($dealer);

        $this->actingAs($dealer)->onPortal('dealer')->postJson('/commands/orders/'.$order->id.'/pay-from-account', [])->assertOk();

        $this->assertSame('100.00', (string) $order->fresh()->paid);
    }

    public function test_paying_an_existing_order_is_refused_when_short(): void
    {
        $dealer = $this->dealerWithBalance('99.99');
        $order = $this->unpaidOrder($dealer);

        $this->actingAs($dealer)->onPortal('dealer')->post('/commands/orders/'.$order->id.'/pay-from-account', [])->assertSessionHasErrors('channel');

        $this->assertSame('0.00', (string) $order->fresh()->paid);
        $this->assertSame('99.99', $this->usable($dealer));
    }

    public function test_only_the_partner_who_ordered_can_pay_from_their_account(): void
    {
        $dealer = $this->dealerWithBalance('250.00');
        $other = $this->dealerWithBalance('250.00');
        $order = $this->unpaidOrder($dealer);

        $this->actingAs($other)->onPortal('dealer')->post('/commands/orders/'.$order->id.'/pay-from-account', [])->assertNotFound();
        $this->actingAs(User::factory()->staff('finance')->create())->onPortal('manage')->post('/commands/orders/'.$order->id.'/pay-from-account', [])->assertForbidden();
        $this->actingAs(User::factory()->staff('super_admin')->create())->onPortal('sadmin')->post('/commands/orders/'.$order->id.'/pay-from-account', [])->assertNotFound();

        $this->assertSame('0.00', (string) $order->fresh()->paid);
        $this->assertSame('250.00', $this->usable($dealer));
        $this->assertSame('250.00', $this->usable($other));
    }

    public function test_paying_while_another_payment_waits_is_refused(): void
    {
        $dealer = $this->dealerWithBalance('250.00');
        $order = $this->unpaidOrder($dealer);
        DB::table('payment_claims')->insert(['order_id' => $order->id, 'channel' => 'Bank', 'reference' => 'BANK-WAITING-1', 'amount' => '100.00', 'created_at' => now(), 'updated_at' => now()]);

        $this->actingAs($dealer)->onPortal('dealer')->post('/commands/orders/'.$order->id.'/pay-from-account', [])->assertSessionHasErrors('channel');

        $this->assertSame('250.00', $this->usable($dealer));
    }

    public function test_an_old_balance_order_cannot_be_paid_from_the_account(): void
    {
        $dealer = $this->dealerWithBalance('250.00');
        $order = $this->unpaidOrder($dealer);
        DB::table('orders')->where('id', $order->id)->update(['is_opening_balance' => true]);

        $this->actingAs($dealer)->onPortal('dealer')->post('/commands/orders/'.$order->id.'/pay-from-account', [])
            ->assertSessionHasErrors(['channel' => __('order_payment.err_account_old_due')]);

        $this->assertSame('250.00', $this->usable($dealer));
        $this->actingAs($dealer)->onPortal('dealer')->get('/orders/'.$order->id)->assertOk()->assertInertia(fn (Assert $page) => $page->where('payOptions', null));
    }

    public function test_a_closed_order_cannot_be_paid_from_the_account(): void
    {
        $dealer = $this->dealerWithBalance('250.00');
        $order = $this->unpaidOrder($dealer);
        $order->update(['status' => 'rejected']);

        $this->actingAs($dealer)->onPortal('dealer')->post('/commands/orders/'.$order->id.'/pay-from-account', [])->assertSessionHasErrors('channel');

        $this->assertSame('250.00', $this->usable($dealer));
    }

    public function test_a_mixed_payment_is_not_rejected_without_the_refund_step(): void
    {
        $dealer = $this->dealerWithBalance('250.00');
        $finance = User::factory()->staff('finance')->create();
        $reviewer = User::factory()->staff('super_admin')->create();
        $order = $this->unpaidOrder($dealer, 2);
        $claim = DB::table('payment_claims')->insertGetId(['order_id' => $order->id, 'channel' => 'Bank', 'reference' => 'BANK-PART-1', 'amount' => '50.00', 'created_at' => now(), 'updated_at' => now()]);
        app(OrderService::class)->verify($finance, $claim, true, 'Matched');
        $this->actingAs($dealer)->onPortal('dealer')->post('/commands/orders/'.$order->id.'/pay-from-account', [])->assertSessionHasNoErrors();
        $this->assertSame('100.00', $this->usable($dealer));

        $this->actingAs($reviewer)->onPortal('sadmin')->post('/commands/orders/'.$order->id.'/decision', ['status' => 'rejected', 'reason' => 'Out of stock'])
            ->assertSessionHasErrors('status');
        $this->assertSame('100.00', $this->usable($dealer));

        app(OrderRefund::class)->refund($finance, $order->fresh(), '200.00', 'Out of stock');
        $this->assertSame('250.00', $this->usable($dealer));
        $this->assertDatabaseHas('order_refunds', ['order_id' => $order->id, 'amount' => '200.00', 'to_account' => '150.00']);
        $this->assertBooksAgree();
    }

    public function test_an_order_by_office_can_be_asked_for_on_an_existing_order(): void
    {
        $dealer = User::factory()->dealer()->create();
        $order = $this->unpaidOrder($dealer);

        $this->actingAs($dealer)->onPortal('dealer')->post('/commands/orders/'.$order->id.'/payments', ['channel' => 'Office'])->assertSessionHasNoErrors();

        $this->assertDatabaseHas('payment_claims', ['order_id' => $order->id, 'channel' => 'Office', 'reference' => 'OFC-'.$order->number, 'amount' => '100.00', 'status' => 'pending']);
    }

    public function test_the_payment_form_on_an_order_has_no_from_my_account_channel(): void
    {
        $dealer = $this->dealerWithBalance('250.00');
        $order = $this->unpaidOrder($dealer);

        $this->actingAs($dealer)->onPortal('dealer')->post('/commands/orders/'.$order->id.'/payments', ['channel' => 'Account'])->assertSessionHasErrors('channel');

        $this->assertDatabaseCount('payment_claims', 0);
    }

    public function test_the_order_detail_page_is_told_whether_the_account_can_pay(): void
    {
        $dealer = $this->dealerWithBalance('250.00');
        $order = $this->unpaidOrder($dealer);

        $this->actingAs($dealer)->onPortal('dealer')->get('/orders/'.$order->id)->assertOk()->assertInertia(fn (Assert $page) => $page
            ->where('payOptions.account_available', '250.00')
            ->where('payOptions.account_usable', true));
    }

    public function test_the_collections_report_leaves_out_account_payments(): void
    {
        $dealer = $this->dealerWithBalance('500.00');
        $finance = User::factory()->staff('finance')->create();
        $this->order($dealer, $this->product(), 1)->assertSessionHasNoErrors();
        $this->order($dealer, $this->product(), 1, 'Office')->assertSessionHasNoErrors();
        $today = now('Asia/Dhaka')->toDateString();

        $channels = app(ReportData::class)->query($finance, 'collections', $today, $today)['query']->get()->pluck('channel')->all();

        $this->assertSame(['Office'], $channels);
    }

    public function test_the_payment_queue_never_lists_account_payments(): void
    {
        $dealer = $this->dealerWithBalance('500.00');
        $this->order($dealer, $this->product(), 1)->assertSessionHasNoErrors();
        $this->order($dealer, $this->product(), 1, 'Office')->assertSessionHasNoErrors();

        $this->actingAs(User::factory()->staff('finance')->create())->onPortal('manage')->get('/payments')->assertOk()->assertInertia(fn (Assert $page) => $page
            ->where('data.payments_table.rows.total', 1)
            ->where('data.payments_table.rows.data.0.channel', 'Office'));
    }

    public function test_the_books_check_catches_an_account_payment_with_no_matching_entry(): void
    {
        $dealer = $this->dealerWithBalance('500.00');
        $this->order($dealer, $this->product(), 1)->assertSessionHasNoErrors();
        $this->assertBooksAgree();

        DB::table('journal_entries')->insert(['id' => $entry = (string) Str::uuid(), 'event_key' => 'test.stray', 'description' => 'Stray entry', 'created_at' => now()]);
        DB::table('journal_lines')->insert([
            ['entry_id' => $entry, 'user_id' => null, 'account' => 'wallet_order_clearing', 'debit' => '0.00', 'credit' => '1.00'],
            ['entry_id' => $entry, 'user_id' => null, 'account' => 'commission_expense', 'debit' => '1.00', 'credit' => '0.00'],
        ]);

        $this->assertFalse(app(FinancialReadiness::class)->checks()['account_order_money']);
    }

    public function test_the_accounting_statements_have_a_place_for_the_new_entries(): void
    {
        $dealer = $this->dealerWithBalance('500.00');
        $this->order($dealer, $this->product(), 2)->assertSessionHasNoErrors();
        $this->assertSame(AccountingStatements::Asset, AccountingStatements::AccountClasses['wallet_order_clearing']);
        $today = now('Asia/Dhaka')->toDateString();

        $checks = app(AccountingStatements::class)->build($today, $today)['checks'];

        $this->assertSame([], $checks['unclassified_accounts']);
        $this->assertTrue($checks['journal_balanced']);
        $this->assertTrue($checks['ties_to_journal']);
    }

    public function test_the_statement_file_names_the_new_movements_in_the_readers_language(): void
    {
        $dealer = $this->dealerWithBalance('500.00');
        $finance = User::factory()->staff('finance')->create();
        $this->order($dealer, $this->product(), 2)->assertSessionHasNoErrors();
        $order = Order::where('user_id', $dealer->id)->sole();
        app(OrderRefund::class)->refund($finance, $order, '200.00', 'Stock ran out');

        foreach (['bn', 'en'] as $locale) {
            app()->setLocale($locale);
            $lines = collect(app(PartnerWalletStatement::class)->for($dealer)['lines'])->pluck('description')->all();
            $this->assertContains(__('order_payment.jr_account_pay', ['number' => $order->number]), $lines);
            $this->assertContains(__('order_payment.jr_account_return', ['number' => $order->number]), $lines);
        }
    }

    public function test_both_methods_have_a_name_and_their_events_have_labels_in_both_languages(): void
    {
        foreach (['bn', 'en'] as $locale) {
            foreach (['pay_channel_office', 'pay_channel_account', 'event_payment_account_paid', 'event_payment_account_returned'] as $key) {
                $this->assertNotSame('portal.'.$key, __('portal.'.$key, [], $locale), $key);
            }
        }
        $this->assertNotSame(__('portal.pay_channel_office', [], 'bn'), __('portal.pay_channel_office', [], 'en'));
    }

    public function test_the_order_form_keeps_its_retry_key_until_the_order_changes(): void
    {
        $form = (string) file_get_contents(resource_path('js/components/ProductPurchaseMatrix.tsx'));

        $this->assertStringNotContainsString('idempotency_key: randomUuid()', $form);
        $this->assertStringContainsString('idempotency_key: orderRetryKey(', $form);
    }

    private function shortMessage(string $balance, string $total, string $short): string
    {
        return __('order_payment.err_account_short', [
            'balance' => LocalDigits::text(Money::grouped($balance)),
            'total' => LocalDigits::text(Money::grouped($total)),
            'short' => LocalDigits::text(Money::grouped($short)),
        ]);
    }

    private function product(): Product
    {
        return Product::factory()->create(['mrp' => '200.00', 'dealer_price' => '100.00', 'stock' => 500]);
    }

    /** @return TestResponse<Response> */
    private function order(User $dealer, Product $product, int $quantity, string $channel = 'Account', ?string $key = null): TestResponse
    {
        return $this->actingAs($dealer)->post('/commands/orders', [
            'items' => [['product_id' => $product->id, 'quantity' => $quantity]],
            'idempotency_key' => $key ?? (string) Str::uuid(),
            'channel' => $channel,
        ]);
    }

    /** An order for ৳100 with no payment on it yet. */
    private function unpaidOrder(User $dealer, int $quantity = 1): Order
    {
        config(['madina.full_payment_when_ordering' => false]);
        $order = app(OrderService::class)->create($dealer, [
            'items' => [['product_id' => Product::factory()->create(['mrp' => '200.00', 'dealer_price' => '100.00', 'stock' => 50])->id, 'quantity' => $quantity]],
            'idempotency_key' => (string) Str::uuid(),
        ]);
        config(['madina.full_payment_when_ordering' => true]);

        return $order;
    }

    /** A dealer whose wallet and journal agree, so the books check starts clean. */
    private function dealerWithBalance(string $available, string $held = '0.00', string $commissionHeld = '0.00'): User
    {
        $dealer = User::factory()->dealer()->create();
        DB::table('wallets')->insert(['user_id' => $dealer->id, 'available' => $available, 'held' => $held, 'commission_held' => $commissionHeld, 'created_at' => now(), 'updated_at' => now()]);
        $this->assertSame(1, DB::table('wallets')->where('user_id', $dealer->id)->count());
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

    private function deliver(Order $order, User $staff): void
    {
        $this->actingAs($staff)->postJson('/api/v1/orders/'.$order->id.'/decision', ['status' => 'approved', 'reason' => 'Payment confirmed'])->assertOk();
        $line = DB::table('order_lines')->where('order_id', $order->id)->sole();
        $this->postJson('/api/v1/orders/'.$order->id.'/dispatch', ['courier' => 'Test courier', 'tracking' => 'TRACK-'.Str::random(8),
            'idempotency_key' => (string) Str::uuid(), 'items' => [['line_id' => $line->id, 'quantity' => $line->quantity]]])->assertOk();
        $shipment = DB::table('shipments')->where('order_id', $order->id)->value('id');
        $this->postJson('/api/v1/orders/'.$order->id.'/shipments/'.$shipment.'/delivered', ['reason' => 'Proof of delivery checked'])->assertOk();
    }

    /**
     * What delivery posted for an order, without anything that names the order or the person.
     *
     * @return array{batch: array<string, string>, rows: list<array<string, string>>}
     */
    private function commissionOf(Order $order): array
    {
        $batch = DB::table('commission_batches')->where('order_id', $order->id)->sole(['cash_basis', 'mrp_basis', 'company_share', 'total_allocated', 'unallocated', 'refunded_amount']);
        $rows = DB::table('commission_allocations')->where('order_id', $order->id)->orderBy('category')->orderBy('amount')->get(['category', 'rate_percentage', 'cash_basis', 'amount', 'status']);

        return [
            'batch' => array_map(strval(...), (array) $batch),
            'rows' => $rows->map(fn (object $row): array => array_map(strval(...), (array) $row))->all(),
        ];
    }

    private function assertBooksAgree(): void
    {
        $checks = app(FinancialReadiness::class)->checks();
        $this->assertArrayHasKey('account_order_money', $checks);
        $this->assertSame([], array_keys(array_filter($checks, fn (bool $passed): bool => ! $passed)));
    }
}
