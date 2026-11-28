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
use Illuminate\Testing\TestResponse;
use Tests\TestCase;

/**
 * Owner's request (2026-11-27): "the money came, approve the order" is one press. The payment check and
 * the approval run together in one transaction, with every check and record of the two separate steps.
 */
class OrderVerifyAndApproveTest extends TestCase
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

    public function test_one_press_checks_the_payment_and_approves_the_order(): void
    {
        $finance = User::factory()->staff('finance')->create();
        $order = $this->orderPaidByOffice(User::factory()->dealer()->create());

        $this->press($finance, $order, ['cash_counted' => '200.00'])
            ->assertSessionHasNoErrors()
            ->assertSessionHas('success', __('order_payment.flash_verified_approved'));

        $order->refresh();
        $this->assertSame('approved', $order->status);
        $this->assertSame('200.00', (string) $order->paid);
        $this->assertSame('verified', DB::table('payment_claims')->where('order_id', $order->id)->value('status'));
        $this->assertSame(1, DB::table('receipts')->where('order_id', $order->id)->count());
        $this->assertSame('approval', $order->commission_policy_snapshot['capture_event']);
        foreach (['payment.verified', 'order.approved'] as $action) {
            $this->assertDatabaseHas('order_events', ['order_id' => $order->id, 'action' => $action, 'actor_id' => $finance->id]);
        }
        foreach (['payment.verified', 'order.approved', 'commissions.policy_approved'] as $action) {
            $this->assertDatabaseHas('audit_events', ['actor_id' => $finance->id, 'action' => $action, 'subject' => $order->id]);
        }
        $actions = DB::table('order_events')->where('order_id', $order->id)->orderBy('id')->pluck('action')->all();
        $this->assertLessThan(array_search('order.approved', $actions, true), array_search('payment.verified', $actions, true));
    }

    public function test_staff_who_send_goods_go_on_to_the_courier_page(): void
    {
        $owner = User::factory()->staff('super_admin')->create();
        $order = $this->orderPaidByOffice(User::factory()->dealer()->create());

        $this->actingAs($owner)->onPortal('sadmin')
            ->post('/commands/orders/'.$order->id.'/verify-and-approve', ['claim_id' => $this->claimId($order), 'reason' => 'Cash counted', 'cash_counted' => '200.00'])
            ->assertRedirect('/orders/'.$order->id.'/courier');

        $this->assertSame('approved', $order->fresh()->status);
    }

    public function test_the_partner_gets_one_notice_and_one_sms_for_both_steps(): void
    {
        $dealer = User::factory()->dealer()->create(['phone' => '01720000088']);
        $finance = User::factory()->staff('finance')->create();
        $order = $this->orderPaidByOffice($dealer);
        $before = DB::table('account_notices')->where('user_id', $dealer->id)->count();
        config(['madina.sms.driver' => 'array', 'madina.sms.sent' => []]);

        $this->press($finance, $order, ['cash_counted' => '200.00'])->assertSessionHasNoErrors();

        $this->assertSame($before + 1, DB::table('account_notices')->where('user_id', $dealer->id)->count());
        $latest = DB::table('account_notices')->where('user_id', $dealer->id)->orderByDesc('id')->first();
        $this->assertSame(__('portal.notice_payment_reviewed_body', ['number' => $order->number, 'outcome' => __('portal.payment_outcome_verified_approved')]), $latest->body);
        $this->assertSame(['payment.verified'], collect(config('madina.sms.sent'))->pluck('event')->all());
        $this->assertStringContainsString(SmsText::make('portal.payment_outcome_verified_approved'), (string) config('madina.sms.sent')[0]['message']);
    }

    public function test_a_held_order_is_approved_by_the_one_press(): void
    {
        $finance = User::factory()->staff('finance')->create();
        $order = $this->orderPaidByOffice(User::factory()->dealer()->create());
        $this->actingAs($finance)->onPortal('manage')->post('/commands/orders/'.$order->id.'/decision', ['status' => 'held', 'reason' => 'Checking stock'])
            ->assertSessionHasNoErrors();

        $this->press($finance, $order, ['cash_counted' => '200.00'])->assertSessionHasNoErrors();

        $this->assertSame('approved', $order->fresh()->status);
    }

    public function test_the_upline_replacement_is_chosen_in_the_same_press(): void
    {
        $fourth = User::factory()->dealer()->create();
        $third = User::factory()->dealer()->create(['sponsor_id' => $fourth->id]);
        $second = User::factory()->dealer()->create(['sponsor_id' => $third->id]);
        $inactive = User::factory()->dealer()->create(['sponsor_id' => $second->id]);
        $buyer = User::factory()->dealer()->create(['sponsor_id' => $inactive->id]);
        $finance = User::factory()->staff('finance')->create();
        $order = $this->orderPaidByOffice($buyer);
        $inactive->forceFill(['status' => 'suspended'])->save();

        $this->press($finance, $order, ['cash_counted' => '200.00', 'referral_replacement_1' => $fourth->id])->assertSessionHasNoErrors();

        $order->refresh();
        $this->assertSame('approved', $order->status);
        $this->assertSame($fourth->id, $order->commission_policy_snapshot['referral_replacements'][1]['user_id'] ?? null);
        $this->assertSame($finance->id, $order->commission_policy_snapshot['referral_replacements'][1]['authorized_by']);
    }

    public function test_a_refused_approval_leaves_the_payment_unchecked_too(): void
    {
        $second = User::factory()->dealer()->create();
        $inactive = User::factory()->dealer()->create(['sponsor_id' => $second->id]);
        $buyer = User::factory()->dealer()->create(['sponsor_id' => $inactive->id]);
        $finance = User::factory()->staff('finance')->create();
        $order = $this->orderPaidByOffice($buyer);
        $inactive->forceFill(['status' => 'suspended'])->save();

        // The grandparent already holds a paid place in this order, so the approval refuses him as the replacement.
        $this->press($finance, $order, ['cash_counted' => '200.00', 'referral_replacement_1' => $second->id])->assertSessionHasErrors('replacement');

        $order->refresh();
        $this->assertSame('submitted', $order->status);
        $this->assertSame('0.00', (string) $order->paid);
        $this->assertSame('pending', DB::table('payment_claims')->where('order_id', $order->id)->value('status'));
        $this->assertSame(0, DB::table('receipts')->where('order_id', $order->id)->count());
        $this->assertDatabaseMissing('order_events', ['order_id' => $order->id, 'action' => 'payment.verified']);
    }

    public function test_a_payment_that_leaves_something_due_is_refused_and_nothing_changes(): void
    {
        $finance = User::factory()->staff('finance')->create();
        $order = $this->orderPaidByOffice(User::factory()->dealer()->create());
        DB::table('payment_claims')->where('order_id', $order->id)->update(['amount' => '50.00']);

        $this->press($finance, $order, ['cash_counted' => '50.00'])->assertSessionHasErrors('status');

        $this->assertSame('submitted', $order->fresh()->status);
        $this->assertSame('0.00', (string) $order->fresh()->paid);
        $this->assertSame('pending', DB::table('payment_claims')->where('order_id', $order->id)->value('status'));
    }

    public function test_office_cash_still_needs_the_counted_amount(): void
    {
        $finance = User::factory()->staff('finance')->create();
        $order = $this->orderPaidByOffice(User::factory()->dealer()->create());

        $this->press($finance, $order, ['cash_counted' => '150.00'])->assertSessionHasErrors('cash_counted');
        $this->press($finance, $order, [])->assertSessionHasErrors('cash_counted');

        $this->assertSame('submitted', $order->fresh()->status);
        $this->assertSame('pending', DB::table('payment_claims')->where('order_id', $order->id)->value('status'));
    }

    public function test_a_second_press_changes_nothing(): void
    {
        $finance = User::factory()->staff('finance')->create();
        $order = $this->orderPaidByOffice(User::factory()->dealer()->create());

        $this->press($finance, $order, ['cash_counted' => '200.00'])->assertSessionHasNoErrors();
        $this->press($finance, $order, ['cash_counted' => '200.00'])->assertSessionHasNoErrors();
        app(OrderService::class)->verifyAndApprove($finance, $order->fresh(), $this->claimId($order), 'Again', [], '200.00');

        $this->assertSame(1, DB::table('receipts')->where('order_id', $order->id)->count());
        $this->assertSame(1, DB::table('order_events')->where('order_id', $order->id)->where('action', 'order.approved')->count());
        $this->assertSame(1, DB::table('order_events')->where('order_id', $order->id)->where('action', 'payment.verified')->count());
        $this->assertSame('200.00', (string) $order->fresh()->paid);
    }

    public function test_it_needs_both_the_payment_right_and_the_approval_right(): void
    {
        $order = $this->orderPaidByOffice(User::factory()->dealer()->create());

        foreach (['account_manager', 'inventory'] as $role) {
            $this->press(User::factory()->staff($role)->create(), $order, ['cash_counted' => '200.00'])->assertForbidden();
        }

        $this->assertSame('submitted', $order->fresh()->status);
        $this->assertSame('pending', DB::table('payment_claims')->where('order_id', $order->id)->value('status'));
    }

    public function test_a_payment_of_another_order_is_not_found(): void
    {
        $finance = User::factory()->staff('finance')->create();
        $order = $this->orderPaidByOffice(User::factory()->dealer()->create());
        $other = $this->orderPaidByOffice(User::factory()->dealer()->create());

        $this->actingAs($finance)->onPortal('manage')
            ->post('/commands/orders/'.$order->id.'/verify-and-approve', ['claim_id' => $this->claimId($other), 'reason' => 'Cash counted', 'cash_counted' => '200.00'])
            ->assertNotFound();

        $this->assertSame('pending', DB::table('payment_claims')->where('order_id', $other->id)->value('status'));
    }

    public function test_cash_at_the_counter_for_an_office_pickup_also_hands_the_goods_over_in_the_same_press(): void
    {
        $owner = User::factory()->staff('super_admin')->create();
        $order = $this->orderPaidByOffice(User::factory()->dealer()->create(), 'office');
        $fields = ['claim_id' => $this->claimId($order), 'reason' => 'Cash counted', 'cash_counted' => '200.00',
            'hand_over' => '1', 'idempotency_key' => (string) Str::uuid(), 'handover_note' => 'Taken by the dealer himself'];

        $this->actingAs($owner)->onPortal('sadmin')->from('/orders/'.$order->id)
            ->post('/commands/orders/'.$order->id.'/verify-and-approve', $fields)
            ->assertSessionHasNoErrors()
            ->assertRedirect('/orders/'.$order->id)
            ->assertSessionHas('success', __('order_payment.flash_verified_approved_handed'));
        // A double press gives the goods out once.
        $this->actingAs($owner)->onPortal('sadmin')->from('/orders/'.$order->id)
            ->post('/commands/orders/'.$order->id.'/verify-and-approve', $fields);

        $order->refresh();
        $this->assertSame('delivered', $order->status);
        $this->assertSame('verified', DB::table('payment_claims')->where('order_id', $order->id)->value('status'));
        $this->assertSame(1, DB::table('shipments')->where('order_id', $order->id)->count());
        foreach (['payment.verified', 'order.approved', 'order.office_handover'] as $action) {
            $this->assertDatabaseHas('order_events', ['order_id' => $order->id, 'action' => $action, 'actor_id' => $owner->id]);
        }
    }

    public function test_without_the_hand_over_tick_an_office_pickup_is_only_approved(): void
    {
        $owner = User::factory()->staff('super_admin')->create();
        $order = $this->orderPaidByOffice(User::factory()->dealer()->create(), 'office');

        $this->actingAs($owner)->onPortal('sadmin')
            ->post('/commands/orders/'.$order->id.'/verify-and-approve', ['claim_id' => $this->claimId($order), 'reason' => 'Cash counted', 'cash_counted' => '200.00', 'hand_over' => '0'])
            ->assertSessionHasNoErrors()
            ->assertRedirect('/orders/'.$order->id);

        $this->assertSame('approved', $order->fresh()->status);
        $this->assertSame(0, DB::table('shipments')->where('order_id', $order->id)->count());
    }

    public function test_staff_who_may_not_send_goods_get_the_money_and_approval_but_no_hand_over(): void
    {
        $finance = User::factory()->staff('finance')->create();
        $order = $this->orderPaidByOffice(User::factory()->dealer()->create(), 'office');

        $this->press($finance, $order, ['cash_counted' => '200.00', 'hand_over' => '1', 'idempotency_key' => (string) Str::uuid()])
            ->assertSessionHasNoErrors()
            ->assertSessionHas('success', __('order_payment.flash_verified_approved'));

        $this->assertSame('approved', $order->fresh()->status);
        $this->assertSame(0, DB::table('shipments')->where('order_id', $order->id)->count());
    }

    public function test_the_hand_over_needs_a_request_key(): void
    {
        $owner = User::factory()->staff('super_admin')->create();
        $order = $this->orderPaidByOffice(User::factory()->dealer()->create(), 'office');

        $this->actingAs($owner)->onPortal('sadmin')
            ->post('/commands/orders/'.$order->id.'/verify-and-approve', ['claim_id' => $this->claimId($order), 'reason' => 'Cash counted', 'cash_counted' => '200.00', 'hand_over' => '1'])
            ->assertSessionHasErrors('idempotency_key');

        $this->assertSame('submitted', $order->fresh()->status);
    }

    /** @param array<string, mixed> $fields */
    private function press(User $staff, Order $order, array $fields): TestResponse
    {
        return $this->actingAs($staff)->onPortal('manage')->from('/orders/'.$order->id)
            ->post('/commands/orders/'.$order->id.'/verify-and-approve', ['claim_id' => $this->claimId($order), 'reason' => 'Cash counted', ...$fields]);
    }

    private function claimId(Order $order): int
    {
        return (int) DB::table('payment_claims')->where('order_id', $order->id)->value('id');
    }

    /** A ৳200 order with a cash-at-the-office payment waiting for the finance team. */
    private function orderPaidByOffice(User $dealer, ?string $deliveryMethod = null): Order
    {
        $product = Product::factory()->create(['mrp' => '200.00', 'dealer_price' => '100.00', 'stock' => 500]);
        $this->actingAs($dealer)->post('/commands/orders', [
            'items' => [['product_id' => $product->id, 'quantity' => 2]],
            'idempotency_key' => (string) Str::uuid(),
            'channel' => 'Office',
            ...($deliveryMethod !== null ? ['delivery_method' => $deliveryMethod] : []),
        ])->assertSessionHasNoErrors();

        return Order::where('user_id', $dealer->id)->latest('created_at')->firstOrFail();
    }
}
