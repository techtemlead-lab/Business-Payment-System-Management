<?php

namespace Tests\Feature;

use App\Domain\Notifications\SmsText;
use App\Domain\Orders\OrderService;
use App\Models\Order;
use App\Models\Product;
use App\Models\User;
use Database\Seeders\CommissionRuleSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Tests\TestCase;

/**
 * Owner's rule (2026-11-23): a verified payment that completes an order's price approves the
 * order by itself. Staff no longer approve the order and check the payment as two separate steps.
 */
class OrderAutoApprovalTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->withoutVite();
        config(['madina.full_payment_when_ordering' => true]);
        $this->enableFinancialPosting();
        $this->seed(CommissionRuleSeeder::class);
    }

    public function test_checking_the_full_payment_approves_the_order_by_itself(): void
    {
        $dealer = User::factory()->dealer()->create();
        $finance = User::factory()->staff('finance')->create();
        $order = $this->orderPaidByOffice($dealer);

        $this->verifyOfficeCash($finance, $order, '200.00');

        $order->refresh();
        $this->assertSame('approved', $order->status);
        $this->assertSame(__('portal.auto_approved_reason'), $order->decision_reason);
        $this->assertDatabaseHas('order_events', ['order_id' => $order->id, 'action' => 'payment.verified', 'actor_id' => $finance->id]);
        $this->assertDatabaseHas('order_events', ['order_id' => $order->id, 'action' => 'order.approved', 'actor_id' => $finance->id]);
        $this->assertSame('approval', $order->commission_policy_snapshot['capture_event']);
    }

    public function test_the_partner_gets_one_notice_for_the_payment_check_and_the_approval(): void
    {
        $dealer = User::factory()->dealer()->create();
        $finance = User::factory()->staff('finance')->create();
        $order = $this->orderPaidByOffice($dealer);
        $before = DB::table('account_notices')->where('user_id', $dealer->id)->count();

        $this->verifyOfficeCash($finance, $order, '200.00');

        $this->assertSame($before + 1, DB::table('account_notices')->where('user_id', $dealer->id)->count());
        $latest = DB::table('account_notices')->where('user_id', $dealer->id)->orderByDesc('id')->first();
        $this->assertSame(__('portal.notice_payment_reviewed_title'), $latest->title);
        $this->assertSame(__('portal.notice_payment_reviewed_body', ['number' => $order->number, 'outcome' => __('portal.payment_outcome_verified_approved')]), $latest->body);
    }

    public function test_the_partner_gets_one_sms_for_the_payment_check_and_the_approval(): void
    {
        $dealer = User::factory()->dealer()->create(['phone' => '01720000077']);
        $finance = User::factory()->staff('finance')->create();
        $order = $this->orderPaidByOffice($dealer);
        config(['madina.sms.driver' => 'array', 'madina.sms.sent' => []]);

        $this->verifyOfficeCash($finance, $order, '200.00');

        $events = collect(config('madina.sms.sent'))->pluck('event')->all();
        $this->assertSame(['payment.verified'], $events);
        $this->assertStringContainsString(SmsText::make('portal.payment_outcome_verified_approved'), (string) config('madina.sms.sent')[0]['message']);
    }

    public function test_a_payment_that_does_not_approve_the_order_still_sends_its_own_sms(): void
    {
        $dealer = User::factory()->dealer()->create(['phone' => '01720000077']);
        $finance = User::factory()->staff('finance')->create();
        $order = $this->orderPaidByOffice($dealer);
        DB::table('payment_claims')->where('order_id', $order->id)->update(['amount' => '50.00']);
        config(['madina.sms.driver' => 'array', 'madina.sms.sent' => []]);

        $this->verifyOfficeCash($finance, $order, '50.00');

        $this->assertSame(['payment.verified'], collect(config('madina.sms.sent'))->pluck('event')->all());
    }

    public function test_the_activity_line_comes_after_the_payment_line(): void
    {
        $dealer = User::factory()->dealer()->create();
        $finance = User::factory()->staff('finance')->create();
        $order = $this->orderPaidByOffice($dealer);

        $this->verifyOfficeCash($finance, $order, '200.00');

        $actions = DB::table('order_events')->where('order_id', $order->id)->orderBy('id')->pluck('action')->all();
        $this->assertLessThan(array_search('order.approved', $actions, true), array_search('payment.verified', $actions, true));
    }

    public function test_a_payment_that_leaves_something_due_does_not_approve_the_order(): void
    {
        $dealer = User::factory()->dealer()->create();
        $finance = User::factory()->staff('finance')->create();
        $order = $this->orderPaidByOffice($dealer);
        DB::table('payment_claims')->where('order_id', $order->id)->update(['amount' => '50.00']);

        $this->verifyOfficeCash($finance, $order, '50.00');

        $order->refresh();
        $this->assertSame('50.00', (string) $order->paid);
        $this->assertSame('submitted', $order->status);
        $this->assertDatabaseMissing('order_events', ['order_id' => $order->id, 'action' => 'order.approved']);
    }

    public function test_an_order_staff_put_on_hold_stays_held_after_its_payment_is_checked(): void
    {
        $dealer = User::factory()->dealer()->create();
        $finance = User::factory()->staff('finance')->create();
        $reviewer = User::factory()->staff('super_admin')->create();
        $order = $this->orderPaidByOffice($dealer);
        $this->actingAs($reviewer)->onPortal('sadmin')->post('/commands/orders/'.$order->id.'/decision', ['status' => 'held', 'reason' => 'Checking stock'])
            ->assertSessionHasNoErrors();

        $this->verifyOfficeCash($finance, $order, '200.00');

        $this->assertSame('held', $order->fresh()->status);
        $this->assertSame('200.00', (string) $order->fresh()->paid);
    }

    public function test_an_order_whose_referrer_needs_a_replacement_waits_for_a_person(): void
    {
        $upline = User::factory()->dealer()->create();
        $dealer = User::factory()->dealer()->create(['sponsor_id' => $upline->id]);
        $finance = User::factory()->staff('finance')->create();
        $order = $this->orderPaidByOffice($dealer);
        $upline->forceFill(['status' => 'suspended'])->save();

        $this->verifyOfficeCash($finance, $order, '200.00');

        $this->assertSame('submitted', $order->fresh()->status);
        $this->assertSame('200.00', (string) $order->fresh()->paid);
        $this->assertDatabaseHas('order_events', ['order_id' => $order->id, 'action' => 'order.auto_approve_waiting']);
        $this->assertDatabaseMissing('order_events', ['order_id' => $order->id, 'action' => 'order.approved']);
    }

    public function test_a_second_check_of_the_same_payment_is_refused_and_does_not_approve_twice(): void
    {
        $dealer = User::factory()->dealer()->create();
        $finance = User::factory()->staff('finance')->create();
        $order = $this->orderPaidByOffice($dealer);
        $this->verifyOfficeCash($finance, $order, '200.00');
        $claimId = (int) DB::table('payment_claims')->where('order_id', $order->id)->value('id');

        try {
            app(OrderService::class)->verify($finance, $claimId, true, 'Again', '200.00');
            $this->fail('A payment that was already checked must not be checked again.');
        } catch (ValidationException) {
            // Refused, as it should be.
        }

        $this->assertSame(1, DB::table('order_events')->where('order_id', $order->id)->where('action', 'order.approved')->count());
    }

    /** A ৳200 order with a cash-at-the-office payment waiting for the finance team. */
    private function orderPaidByOffice(User $dealer): Order
    {
        $product = Product::factory()->create(['mrp' => '200.00', 'dealer_price' => '100.00', 'stock' => 500]);
        $this->actingAs($dealer)->post('/commands/orders', [
            'items' => [['product_id' => $product->id, 'quantity' => 2]],
            'idempotency_key' => (string) Str::uuid(),
            'channel' => 'Office',
        ])->assertSessionHasNoErrors();

        return Order::where('user_id', $dealer->id)->sole();
    }

    private function verifyOfficeCash(User $finance, Order $order, string $counted): void
    {
        app(OrderService::class)->verify($finance, (int) DB::table('payment_claims')->where('order_id', $order->id)->value('id'), true, 'Cash taken', $counted);
    }
}
