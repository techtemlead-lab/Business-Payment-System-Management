<?php

namespace Tests\Feature;

use App\Domain\Commissions\CommissionPosting;
use App\Domain\Finance\OwnerApprovalLimits;
use App\Domain\Orders\OrderService;
use App\Domain\Payouts\ManualPayoutService;
use App\Models\Order;
use App\Models\Product;
use App\Models\User;
use Database\Seeders\CommissionRuleSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Illuminate\Testing\TestResponse;
use Illuminate\Validation\ValidationException;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\TestCase;

/**
 * CR-16: staff with the right permission finish the work; only money at or above the owner's
 * limits waits for the owner. A second staff member, never the maker, checks money steps; the
 * owner is never blocked (CR-10); every step names the staff member in the activity log.
 */
class OwnerApprovalLimitsTest extends TestCase
{
    use RefreshDatabase;

    private User $owner;

    protected function setUp(): void
    {
        parent::setUp();
        $this->withoutVite();
        Storage::fake('local');
        $this->enableFinancialPosting();
        $this->owner = User::factory()->staff('super_admin')->create(['status' => 'active']);
    }

    public function test_the_limits_start_at_one_lakh_fifty_thousand_and_one_lakh(): void
    {
        $limits = app(OwnerApprovalLimits::class)->all();

        $this->assertSame('100000.00', $limits['withdrawal']['amount']);
        $this->assertSame('50000.00', $limits['refund']['amount']);
        $this->assertSame('100000.00', $limits['fund_spend']['amount']);
        $this->assertFalse(app(OwnerApprovalLimits::class)->needsOwner('refund', '49999.99'));
        $this->assertTrue(app(OwnerApprovalLimits::class)->needsOwner('refund', '50000.00'));
    }

    public function test_only_the_owner_changes_a_limit_and_the_change_is_logged(): void
    {
        $finance = User::factory()->staff('finance')->create();
        $payload = ['kind' => 'refund', 'amount' => '20000', 'version' => 0, 'reason' => 'Smaller refunds for now'];

        $this->actingAs($finance)->onPortal('manage')->post('/commands/operations/accounting/approval-limit', $payload)->assertForbidden();
        $this->actingAs($this->owner)->onPortal('sadmin')->post('/commands/operations/accounting/approval-limit', $payload)->assertRedirect()->assertSessionHasNoErrors();

        $this->assertSame('20000.00', app(OwnerApprovalLimits::class)->current('refund')['amount']);
        $this->assertSame('100000.00', app(OwnerApprovalLimits::class)->current('withdrawal')['amount'], 'Changing one limit leaves the others alone.');
        $this->assertDatabaseHas('audit_events', ['actor_id' => $this->owner->id, 'action' => 'finance.refund_owner_limit_changed']);

        $this->actingAs($this->owner)->onPortal('sadmin')->get('/operations/finance-settings')->assertOk()
            ->assertInertia(fn (Assert $page) => $page->where('approvalLimit.limits.refund.amount', '20000.00')->where('approvalLimit.limits.fund_spend.amount', '100000.00')->where('approvalLimit.can_edit', true));
    }

    public function test_finance_staff_approve_a_small_withdrawal_without_the_owner_and_a_second_staff_member_pays(): void
    {
        $this->setLimit('withdrawal', '2000.00');
        $approver = User::factory()->staff('finance')->create(['name' => 'Approving Clerk']);
        $payer = User::factory()->staff('finance')->create();
        $id = $this->withdraw($this->partnerWithWallet('5000.00'), '1500.00');

        app(ManualPayoutService::class)->review($approver, $id, 'approved', 'Checked by phone');

        $this->assertSame('approved', DB::table('withdrawals')->where('id', $id)->value('status'));
        $this->assertDatabaseHas('audit_events', ['actor_id' => $approver->id, 'action' => 'withdrawal.approved', 'subject' => $id]);
        $withdrawal = DB::table('withdrawals')->where('id', $id)->first();
        $this->assertSame('second_person', app(ManualPayoutService::class)->payerState($withdrawal, $approver), 'The approver cannot also pay.');
        $this->assertNull(app(ManualPayoutService::class)->payerState($withdrawal, $payer));
        $this->assertNotContains($approver->id, array_column(app(ManualPayoutService::class)->eligiblePayers($approver), 'id'));
    }

    public function test_a_withdrawal_at_or_above_the_limit_waits_for_the_owner(): void
    {
        $this->setLimit('withdrawal', '2000.00');
        $finance = User::factory()->staff('finance')->create();
        $id = $this->withdraw($this->partnerWithWallet('5000.00'), '2000.00');

        try {
            app(ManualPayoutService::class)->review($finance, $id, 'approved', 'Checked by phone');
            $this->fail('Finance staff approved a withdrawal at the owner limit.');
        } catch (ValidationException $exception) {
            $this->assertArrayHasKey('withdrawal', $exception->errors());
        }
        $this->assertSame('requested', DB::table('withdrawals')->where('id', $id)->value('status'));
        $this->assertTrue(app(ManualPayoutService::class)->waitsForOwner(DB::table('withdrawals')->where('id', $id)->first(), $finance));
        $this->assertFalse(app(ManualPayoutService::class)->waitsForOwner(DB::table('withdrawals')->where('id', $id)->first(), $this->owner));

        app(ManualPayoutService::class)->review($this->owner, $id, 'approved', 'Owner checked');

        $this->assertSame('approved', DB::table('withdrawals')->where('id', $id)->value('status'));
        $event = DB::table('audit_events')->where('action', 'withdrawal.approved')->where('subject', $id)->first();
        $this->assertSame($this->owner->id, (int) $event->actor_id);
        $this->assertTrue(json_decode((string) $event->metadata, true)['owner_limit']);
    }

    public function test_staff_may_still_turn_down_a_large_withdrawal(): void
    {
        $this->setLimit('withdrawal', '2000.00');
        $finance = User::factory()->staff('finance')->create();
        $id = $this->withdraw($this->partnerWithWallet('5000.00'), '3000.00');

        app(ManualPayoutService::class)->review($finance, $id, 'rejected', 'Wrong account number');

        $this->assertSame('rejected', DB::table('withdrawals')->where('id', $id)->value('status'));
    }

    public function test_small_withdrawal_notices_go_to_staff_and_large_ones_to_the_owner(): void
    {
        $this->setLimit('withdrawal', '2000.00');
        $finance = User::factory()->staff('finance')->create();
        $partner = $this->partnerWithWallet('5000.00');
        $title = __('portal.notice_admin_withdrawal_title');

        $this->withdraw($partner, '500.00');
        $this->assertSame(0, DB::table('account_notices')->where('user_id', $this->owner->id)->where('title', $title)->count(), 'Staff work no longer lands with the owner.');
        $this->assertSame(1, DB::table('account_notices')->where('user_id', $finance->id)->where('title', $title)->count());

        $this->withdraw($partner, '2500.00');
        $this->assertSame(1, DB::table('account_notices')->where('user_id', $this->owner->id)->where('title', $title)->count());
        $this->assertSame(1, DB::table('account_notices')->where('user_id', $finance->id)->where('title', $title)->count());
    }

    public function test_a_small_refund_is_finished_by_two_staff_members_without_the_owner(): void
    {
        $this->seed(CommissionRuleSeeder::class);
        $this->setLimit('refund', '150.00');
        $order = $this->paidOrder();
        $first = User::factory()->staff('finance')->create();
        $second = User::factory()->staff('finance')->create();

        $this->actingAs($first)->onPortal('manage')->post('/commands/orders/'.$order->id.'/refund', ['amount' => '100.00', 'reason' => 'Dealer changed the order'])->assertSessionHasNoErrors();
        $request = (int) DB::table('order_return_requests')->where('order_id', $order->id)->value('id');
        $this->actingAs($first)->onPortal('manage')->post('/commands/order-returns/'.$request.'/confirm', ['reason' => 'Checked it myself', 'confirmed' => '1'])
            ->assertSessionHasErrors('request');
        $this->actingAs($second)->onPortal('manage')->post('/commands/order-returns/'.$request.'/confirm', ['reason' => 'Checked the bank slip', 'confirmed' => '1'])
            ->assertSessionHasNoErrors();

        $this->assertDatabaseHas('order_refunds', ['order_id' => $order->id, 'amount' => '100.00', 'refunded_by' => $first->id, 'approved_by' => $second->id]);
        $this->assertDatabaseHas('audit_events', ['actor_id' => $second->id, 'action' => 'order.return_done', 'subject' => $order->id]);
        $this->assertSame(0, DB::table('account_notices')->where('user_id', $this->owner->id)->where('title', __('orders_stock.notice_second_needed_title', ['number' => $order->number]))->count());
    }

    public function test_a_refund_at_or_above_the_limit_needs_the_owner_as_second_approver(): void
    {
        $this->seed(CommissionRuleSeeder::class);
        $this->setLimit('refund', '50.00');
        $order = $this->paidOrder();
        $first = User::factory()->staff('finance')->create();
        $second = User::factory()->staff('finance')->create();

        $this->actingAs($first)->onPortal('manage')->post('/commands/orders/'.$order->id.'/refund', ['amount' => '100.00', 'reason' => 'Dealer changed the order'])->assertSessionHasNoErrors();
        $request = (int) DB::table('order_return_requests')->where('order_id', $order->id)->value('id');
        $this->assertSame(1, DB::table('account_notices')->where('user_id', $this->owner->id)->where('title', __('orders_stock.notice_second_needed_title', ['number' => $order->number]))->count());

        $this->actingAs($second)->onPortal('manage')->get('/orders/'.$order->id)->assertOk()
            ->assertInertia(fn (Assert $page) => $page->where('returns.open.can_confirm', false)->where('returns.open.waits_for_owner', true));
        $this->actingAs($second)->onPortal('manage')->post('/commands/order-returns/'.$request.'/confirm', ['reason' => 'Checked the bank slip', 'confirmed' => '1'])
            ->assertSessionHasErrors('request');
        $this->assertDatabaseCount('order_refunds', 0);

        $this->actingAs($this->owner)->onPortal('sadmin')->post('/commands/order-returns/'.$request.'/confirm', ['reason' => 'Owner checked', 'confirmed' => '1'])
            ->assertSessionHasNoErrors();
        $this->assertDatabaseHas('order_refunds', ['order_id' => $order->id, 'amount' => '100.00', 'refunded_by' => $first->id, 'approved_by' => $this->owner->id]);
    }

    public function test_the_md_and_chairman_cannot_finish_a_refund_without_finance_approval(): void
    {
        $this->seed(CommissionRuleSeeder::class);
        $this->setLimit('refund', '150.00');
        $finance = User::factory()->staff('finance')->create();
        $small = $this->paidOrder();
        $large = $this->paidOrder();

        $this->actingAs($finance)->onPortal('manage')->post('/commands/orders/'.$small->id.'/refund', ['amount' => '100.00', 'reason' => 'Dealer changed the order'])->assertSessionHasNoErrors();
        $waiting = (int) DB::table('order_return_requests')->where('order_id', $small->id)->value('id');

        foreach (['md', 'chairman'] as $role) {
            $leader = User::factory()->staff($role)->create();
            $this->actingAs($leader)->onPortal('sadmin')->post('/commands/orders/'.$large->id.'/refund', ['amount' => '100.00', 'reason' => 'Trying to refund it'])->assertForbidden();
            $this->actingAs($leader)->onPortal('sadmin')->post('/commands/order-returns/'.$waiting.'/confirm', ['reason' => 'Trying to pay it back', 'confirmed' => '1'])->assertForbidden();
        }

        $this->assertDatabaseCount('order_refunds', 0);
        $this->assertDatabaseMissing('order_return_requests', ['order_id' => $large->id]);
        $this->assertSame('awaiting_second', DB::table('order_return_requests')->where('id', $waiting)->value('status'));
    }

    public function test_the_owner_refunds_a_large_amount_alone(): void
    {
        $this->seed(CommissionRuleSeeder::class);
        $this->setLimit('refund', '50.00');
        $order = $this->paidOrder();

        $this->actingAs($this->owner)->onPortal('sadmin')->post('/commands/orders/'.$order->id.'/refund', ['amount' => '100.00', 'reason' => 'Stock ran out'])->assertSessionHasNoErrors();

        $this->assertDatabaseHas('order_refunds', ['order_id' => $order->id, 'amount' => '100.00', 'refunded_by' => $this->owner->id]);
    }

    public function test_a_small_fund_spend_is_approved_by_a_second_staff_member(): void
    {
        $this->setLimit('fund_spend', '200.00');
        [$maker, $checker] = [User::factory()->staff('finance')->create(), User::factory()->staff('finance')->create()];
        $this->fundMoney('1000.00');
        $id = $this->spend($maker, '150.00')->assertOk()->json('data.id');

        $this->reviewSpend($maker, $id)->assertUnprocessable()->assertJsonValidationErrors('spend');
        $this->actingAs($checker)->onPortal('manage')->get('/treasury/work')->assertOk()
            ->assertInertia(fn (Assert $page) => $page->where('approvals_table.rows.data.0.can_review', true)->where('approvals_table.rows.data.0.waits_for_owner', false));
        $this->reviewSpend($checker, $id)->assertOk();

        $this->assertSame('paid', DB::table('reserve_disbursements')->where('id', $id)->value('status'));
        $this->assertDatabaseHas('audit_events', ['actor_id' => $checker->id, 'action' => 'fund.spend_approved', 'subject' => (string) $id]);
        $this->assertSame(0, DB::table('account_notices')->where('user_id', $this->owner->id)->where('title', __('funds.notice_owner_spend_title'))->count());
    }

    public function test_a_fund_spend_at_or_above_the_limit_waits_for_the_owner(): void
    {
        $this->setLimit('fund_spend', '200.00');
        [$maker, $checker] = [User::factory()->staff('finance')->create(), User::factory()->staff('finance')->create()];
        $this->fundMoney('1000.00');
        $id = $this->spend($maker, '200.00')->assertOk()->json('data.id');

        $this->assertSame(1, DB::table('account_notices')->where('user_id', $this->owner->id)->where('title', __('funds.notice_owner_spend_title'))->count());
        $this->actingAs($checker)->onPortal('manage')->get('/treasury/work')->assertOk()
            ->assertInertia(fn (Assert $page) => $page->where('approvals_table.rows.data.0.can_review', false)->where('approvals_table.rows.data.0.waits_for_owner', true));
        $this->reviewSpend($checker, $id)->assertUnprocessable()->assertJsonValidationErrors('spend');
        $this->assertSame('requested', DB::table('reserve_disbursements')->where('id', $id)->value('status'));

        $this->reviewSpend($this->owner, $id)->assertOk();
        $this->assertSame('paid', DB::table('reserve_disbursements')->where('id', $id)->value('status'));
        $this->assertDatabaseHas('audit_events', ['actor_id' => $this->owner->id, 'action' => 'fund.spend_approved', 'subject' => (string) $id]);
    }

    private function setLimit(string $kind, string $amount): void
    {
        app(OwnerApprovalLimits::class)->save($this->owner, $kind, $amount, app(OwnerApprovalLimits::class)->current($kind)['version'], 'Test limit');
    }

    private function partnerWithWallet(string $available): User
    {
        $dealer = User::factory()->dealer()->create(['status' => 'active', 'nid' => '1234567890']);
        DB::table('wallets')->updateOrInsert(['user_id' => $dealer->id], ['available' => $available, 'held' => '0.00', 'created_at' => now(), 'updated_at' => now()]);

        return $dealer;
    }

    private function withdraw(User $dealer, string $amount): string
    {
        return app(ManualPayoutService::class)->request($dealer, [
            'amount' => $amount, 'channel' => 'bKash', 'destination' => '01700000000', 'idempotency_key' => (string) Str::uuid(),
        ]);
    }

    private function paidOrder(): Order
    {
        $product = Product::factory()->create(['mrp' => '200.00', 'dealer_price' => '100.00', 'stock' => 5]);
        $order = app(OrderService::class)->create(User::factory()->dealer()->create(), ['items' => [['product_id' => $product->id, 'quantity' => 1]], 'idempotency_key' => (string) Str::uuid()]);
        $order->update(['status' => 'approved', 'paid' => '100.00']);

        return $order->fresh();
    }

    /** Money in the company profit fund, from one posted order. */
    private function fundMoney(string $amount): void
    {
        $this->seed(CommissionRuleSeeder::class);
        $product = Product::factory()->create(['mrp' => '200.00', 'dealer_price' => '100.00', 'sub_dealer_price' => '120.00']);
        $order = app(OrderService::class)->create(User::factory()->create(['role' => 'sub_dealer']), ['items' => [['product_id' => $product->id, 'quantity' => 1]], 'idempotency_key' => (string) Str::uuid()]);
        $order->update(['status' => 'delivered', 'paid' => '120.00']);
        app(CommissionPosting::class)->process($order);
        DB::table('commission_allocations')->insert(['order_id' => $order->id, 'category' => 'company_profit_fund', 'rate_percentage' => '0.00', 'cash_basis' => '1000.00',
            'amount' => $amount, 'original_amount' => $amount, 'status' => 'reserved', 'metadata' => '{}', 'created_at' => now(), 'updated_at' => now()]);
    }

    private function spend(User $actor, string $amount): TestResponse
    {
        return $this->actingAs($actor)->post('/api/v1/operations/company-funds/spends', [
            'fund' => 'company_profit', 'expense_head_id' => (int) DB::table('fund_expense_heads')->where('name_en', 'Office rent')->value('id'),
            'amount' => $amount, 'spent_on' => now()->toDateString(), 'payee' => 'Landlord', 'payment_method' => 'cash', 'note' => 'Monthly payment',
            'request_key' => (string) Str::uuid(),
        ], ['Accept' => 'application/json']);
    }

    private function reviewSpend(User $actor, int $id): TestResponse
    {
        return $this->actingAs($actor)->postJson('/api/v1/operations/company-funds/spends/'.$id.'/review', ['decision' => 'approved', 'reason' => 'Checked against the bill']);
    }
}
