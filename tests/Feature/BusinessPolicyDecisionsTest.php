<?php

namespace Tests\Feature;

use App\Domain\Commissions\CommissionPolicy;
use App\Domain\Commissions\CommissionPosting;
use App\Domain\Commissions\CommissionService;
use App\Domain\Orders\OrderService;
use App\Models\Order;
use App\Models\Product;
use App\Models\User;
use App\Support\PortalHost;
use Carbon\CarbonImmutable;
use Illuminate\Auth\Notifications\ResetPassword;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Str;
use Tests\TestCase;

class BusinessPolicyDecisionsTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->withoutVite();
        config(['madina.financial_activation' => true]);
        $this->travelTo(CarbonImmutable::parse('2026-09-15T06:00:00Z'));
    }

    protected function tearDown(): void
    {
        $this->travelBack();
        parent::tearDown();
    }

    private function purchase(User $buyer, int $quantity = 1): Order
    {
        $product = Product::factory()->create(['mrp' => '200.00', 'dealer_price' => '100.00']);

        return app(OrderService::class)->create($buyer, ['items' => [['product_id' => $product->id, 'quantity' => $quantity]], 'idempotency_key' => (string) Str::uuid()]);
    }

    /** @param array<string, mixed> $rule */
    private function recipientSchedule(User $recipient, array $rule): int
    {
        $id = $this->actingAs(User::factory()->staff('finance')->create())->postJson('/api/v1/operations/policy-schedules', [
            'scope' => 'recipient', 'user_id' => $recipient->id, 'rules' => ['direct_referral' => $rule], 'effective_at' => now()->toIso8601String(), 'reason' => 'Agreed individual recipient policy',
        ])->assertOk()->json('data.id');
        $this->actingAs(User::factory()->staff('finance')->create())->postJson('/api/v1/operations/policy-schedules/'.$id.'/review', ['decision' => 'approved', 'reason' => 'Independent policy approval'])->assertOk();

        return $id;
    }

    private function approve(Order $order): User
    {
        $actor = User::factory()->staff('finance')->create();
        $this->actingAs($actor)->postJson('/api/v1/orders/'.$order->id.'/decision', ['status' => 'approved', 'allow_credit' => true, 'reason' => 'Approve the current financial terms'])->assertOk();

        return $actor;
    }

    private function postEarnings(Order $order): void
    {
        $order->refresh()->update(['paid' => $order->total, 'status' => 'delivered']);
        app(CommissionPosting::class)->process($order->fresh());
    }

    public function test_owner_employee_and_partner_accounts_only_authenticate_at_their_own_portals(): void
    {
        $address = 1;
        foreach (['super_admin' => 'sadmin', 'md' => 'sadmin', 'chairman' => 'sadmin', 'finance' => 'manage', 'sr' => 'manage', 'dealer' => 'dealer', 'sub_dealer' => 'subdealer'] as $role => $portal) {
            $this->withServerVariables(['REMOTE_ADDR' => '192.0.2.'.$address++]);
            $user = User::factory()->create(['role' => $role, 'password' => 'SyntheticPassword123']);
            $data = ['email' => $user->email, 'password' => 'SyntheticPassword123'];
            foreach (array_diff(['sadmin', 'manage', 'dealer', 'subdealer'], [$portal]) as $wrongPortal) {
                $this->onPortal($wrongPortal)->post('/login', $data)->assertSessionHasErrors('email');
                $this->assertGuest();
            }
            $this->onPortal($portal)->post('/login', $data)->assertRedirect('/');
            $this->assertAuthenticatedAs($user);
            $this->post('/logout')->assertRedirect('/login');
        }
    }

    public function test_owner_recovery_and_private_pages_use_the_owner_entry(): void
    {
        Notification::fake();
        $owner = User::factory()->staff()->create();
        $this->get('/owner/login')->assertOk()->assertSee('Owner Sign In')->assertHeader('Cache-Control', 'max-age=0, no-store, private');
        $this->post('/owner/forgot-password', ['email' => $owner->email])->assertSessionHas('success');
        Notification::assertSentTo($owner, ResetPassword::class, fn ($notice) => str_starts_with($notice->toMail($owner)->actionUrl, PortalHost::url('sadmin', '/reset-password/')));
        $this->actingAs($owner)->withHeader('X-Inertia', 'true')->post('/logout')->assertStatus(409)->assertHeader('X-Inertia-Location', PortalHost::loginUrl($owner));
    }

    public function test_owner_titles_do_not_automatically_grant_financial_write_permissions(): void
    {
        $owner = User::factory()->create(['role' => 'md']);
        $this->actingAs($owner)->get('/company/operations/financial-reports')->assertOk();
        $this->postJson('/api/v1/finance/settlements', ['kind' => 'salary', 'period_month' => '2026-08'])->assertForbidden();
        $this->patchJson('/api/v1/finance/policy', ['rules' => ['own_dealer_cashback' => ['percentage' => '4.00']]])->assertForbidden();
        $this->assertDatabaseCount('commission_policy_revisions', 0);
    }

    public function test_approval_freezes_current_rules_and_later_changes_preserve_the_approved_snapshot(): void
    {
        $buyer = User::factory()->dealer()->create();
        $order = $this->purchase($buyer);
        $finance = User::factory()->staff('finance')->create();
        app(CommissionPolicy::class)->update($finance, ['own_dealer_cashback' => ['percentage' => '4.00']]);
        $this->approve($order);
        $snapshot = $order->fresh()->commission_policy_snapshot;
        $this->assertSame('approval', $snapshot['capture_event']);
        $this->assertSame('4.00', $snapshot['rules']['own_dealer_cashback']['percentage']);
        app(CommissionPolicy::class)->update($finance, ['own_dealer_cashback' => ['percentage' => '3.00']]);
        $this->postEarnings($order);
        app(CommissionPosting::class)->capture($order->fresh(), $buyer);
        $this->assertSame($snapshot, $order->fresh()->commission_policy_snapshot);
        $this->assertDatabaseHas('commission_allocations', ['order_id' => $order->id, 'category' => 'own_dealer_cashback', 'amount' => '4.00']);
        $this->assertDatabaseHas('audit_events', ['action' => 'commissions.policy_approved', 'subject' => $order->id]);
    }

    public function test_company_funded_increase_is_captured_at_approval_and_reverses_proportionally(): void
    {
        $sponsor = User::factory()->dealer()->create();
        $order = $this->purchase(User::factory()->dealer()->create(['sponsor_id' => $sponsor->id]));
        $this->recipientSchedule($sponsor, ['percentage' => '12.00', 'funding_mode' => 'company']);
        $this->approve($order);
        $this->assertDatabaseCount('commission_allocations', 0);
        $this->postEarnings($order);
        $this->assertDatabaseHas('commission_batches', ['order_id' => $order->id, 'company_share' => '58.00', 'total_allocated' => '42.00', 'unallocated' => '0.00']);
        $this->assertDatabaseHas('commission_allocations', ['order_id' => $order->id, 'user_id' => $sponsor->id, 'category' => 'direct_referral', 'amount' => '12.00']);
        app(CommissionService::class)->reverseOrderPartial($order->fresh(), '50.00', 'Half of the purchase returned', 'SPECIAL-HALF');
        $this->assertDatabaseHas('commission_allocations', ['order_id' => $order->id, 'user_id' => $sponsor->id, 'category' => 'direct_referral', 'original_amount' => '12.00', 'amount' => '6.00']);
        $this->assertDatabaseHas('wallets', ['user_id' => $sponsor->id, 'available' => '6.00']);
    }

    public function test_allocation_funded_increase_deducts_the_selected_reserve_and_preserves_total_funding(): void
    {
        $sponsor = User::factory()->dealer()->create();
        $this->recipientSchedule($sponsor, ['percentage' => '12.00', 'funding_mode' => 'allocations', 'funding_sources' => ['company_marketing_fund' => '2.00']]);
        $order = $this->purchase(User::factory()->dealer()->create(['sponsor_id' => $sponsor->id]));
        $this->approve($order);
        $this->postEarnings($order);
        $this->assertDatabaseHas('commission_batches', ['order_id' => $order->id, 'company_share' => '60.00', 'total_allocated' => '40.00']);
        $this->assertDatabaseHas('commission_allocations', ['order_id' => $order->id, 'category' => 'company_marketing_fund', 'amount' => '0.00']);
        $this->assertDatabaseHas('wallets', ['user_id' => $sponsor->id, 'available' => '12.00']);
    }

    public function test_an_individual_can_receive_a_company_funded_rate_when_the_base_category_is_zero(): void
    {
        $sponsor = User::factory()->dealer()->create();
        app(CommissionPolicy::class)->update(User::factory()->staff('finance')->create(), ['direct_referral' => ['percentage' => '0.00']]);
        $this->recipientSchedule($sponsor, ['percentage' => '2.00', 'funding_mode' => 'company']);
        $order = $this->purchase(User::factory()->dealer()->create(['sponsor_id' => $sponsor->id]));
        $this->approve($order);
        $this->postEarnings($order);
        $this->assertDatabaseHas('commission_batches', ['order_id' => $order->id, 'company_share' => '58.00', 'total_allocated' => '32.00', 'unallocated' => '10.00']);
        $this->assertDatabaseHas('wallets', ['user_id' => $sponsor->id, 'available' => '2.00']);
    }

    public function test_multiple_named_funding_sources_are_deducted_without_increasing_the_total_pool(): void
    {
        $sponsor = User::factory()->dealer()->create();
        $this->recipientSchedule($sponsor, ['percentage' => '13.00', 'funding_mode' => 'allocations', 'funding_sources' => ['company_marketing_fund' => '2.00', 'rank_incentive_pool' => '1.00']]);
        $order = $this->purchase(User::factory()->dealer()->create(['sponsor_id' => $sponsor->id]));
        $this->approve($order);
        $this->postEarnings($order);
        $this->assertDatabaseHas('commission_allocations', ['order_id' => $order->id, 'category' => 'company_marketing_fund', 'amount' => '0.00']);
        $this->assertDatabaseHas('commission_allocations', ['order_id' => $order->id, 'category' => 'rank_incentive_pool', 'amount' => '1.00']);
        $this->assertDatabaseHas('wallets', ['user_id' => $sponsor->id, 'available' => '13.00']);
    }

    public function test_unfunded_increase_fails_before_order_approval_without_changing_the_snapshot(): void
    {
        $sponsor = User::factory()->dealer()->create();
        $this->recipientSchedule($sponsor, ['percentage' => '13.00', 'funding_mode' => 'allocations', 'funding_sources' => ['company_marketing_fund' => '3.00']]);
        $order = $this->purchase(User::factory()->dealer()->create(['sponsor_id' => $sponsor->id]));
        $before = $order->commission_policy_snapshot;
        $finance = User::factory()->staff('finance')->create();
        $this->actingAs($finance)->postJson('/api/v1/orders/'.$order->id.'/decision', ['status' => 'approved', 'allow_credit' => true, 'reason' => 'Attempt unfunded approval'])->assertUnprocessable()->assertJsonValidationErrors('funding');
        $this->assertSame('submitted', $order->fresh()->status);
        $this->assertSame($before, $order->fresh()->commission_policy_snapshot);
        $this->assertDatabaseCount('commission_allocations', 0);
        $this->assertDatabaseCount('journal_entries', 0);
    }

    public function test_increases_cannot_overdraw_the_company_share_or_use_mismatched_allocation_percentages(): void
    {
        foreach ([['percentage' => '80.00', 'funding_mode' => 'company'], ['percentage' => '12.00', 'funding_mode' => 'allocations', 'funding_sources' => ['company_marketing_fund' => '1.00']]] as $rule) {
            $sponsor = User::factory()->dealer()->create();
            $this->recipientSchedule($sponsor, $rule);
            $order = $this->purchase(User::factory()->dealer()->create(['sponsor_id' => $sponsor->id]));
            $this->actingAs(User::factory()->staff('finance')->create())->postJson('/api/v1/orders/'.$order->id.'/decision', ['status' => 'approved', 'allow_credit' => true, 'reason' => 'Attempt a funding deficit'])->assertUnprocessable()->assertJsonValidationErrors('funding');
            $this->assertSame('submitted', $order->fresh()->status);
        }
        $this->assertDatabaseCount('commission_batches', 0);
        $this->assertDatabaseCount('journal_entries', 0);
    }

    public function test_a_delegated_employee_can_change_rules_but_a_viewer_cannot(): void
    {
        $editor = User::factory()->staff('employee')->create(['profile' => ['action_permissions' => ['finance' => ['view' => true, 'edit' => true]]]]);
        $viewer = User::factory()->staff('employee')->create(['profile' => ['action_permissions' => ['finance' => ['view' => true]]]]);
        $this->actingAs($editor)->patchJson('/api/v1/finance/policy', ['rules' => ['own_dealer_cashback' => ['percentage' => '4.00']]])->assertOk();
        $this->actingAs($viewer)->patchJson('/api/v1/finance/policy', ['rules' => ['own_dealer_cashback' => ['percentage' => '3.00']]])->assertForbidden();
        $this->assertSame('4.00', app(CommissionPolicy::class)->snapshot()['rules']['own_dealer_cashback']['percentage']);
        $this->actingAs($editor)->patchJson('/api/v1/finance/policy', ['rules' => ['d2d_multitier' => ['parameters' => ['dynamic_compression' => true]]]])->assertUnprocessable();
    }

    public function test_a_manual_replacement_cannot_occupy_another_paid_referral_position(): void
    {
        $second = User::factory()->dealer()->create();
        $inactive = User::factory()->dealer()->create(['status' => 'suspended', 'sponsor_id' => $second->id]);
        $order = $this->purchase(User::factory()->dealer()->create(['sponsor_id' => $inactive->id]));
        $this->actingAs(User::factory()->staff('finance')->create())->postJson('/api/v1/orders/'.$order->id.'/decision', ['status' => 'approved', 'allow_credit' => true, 'reason' => 'Attempt a repeated recipient', 'referral_replacement_1' => $second->id])->assertUnprocessable()->assertJsonValidationErrors('replacement');
        $this->assertSame('submitted', $order->fresh()->status);
    }

    public function test_default_referrals_stop_at_three_and_only_explicit_manual_replacement_can_pay_a_later_ancestor(): void
    {
        $fourth = User::factory()->dealer()->create();
        $third = User::factory()->dealer()->create(['sponsor_id' => $fourth->id]);
        $second = User::factory()->dealer()->create(['sponsor_id' => $third->id]);
        $inactive = User::factory()->dealer()->create(['sponsor_id' => $second->id, 'status' => 'suspended']);
        $buyer = User::factory()->dealer()->create(['sponsor_id' => $inactive->id]);
        $normal = $this->purchase($buyer);
        $this->approve($normal);
        $this->postEarnings($normal);
        $this->assertDatabaseMissing('commission_allocations', ['order_id' => $normal->id, 'user_id' => $fourth->id]);
        $this->assertDatabaseHas('commission_allocations', ['order_id' => $normal->id, 'user_id' => $inactive->id, 'category' => 'direct_referral', 'status' => 'held', 'amount' => '10.00']);
        $manual = $this->purchase($buyer);
        $finance = User::factory()->staff('finance')->create();
        $this->actingAs($finance)->postJson('/api/v1/orders/'.$manual->id.'/decision', ['status' => 'approved', 'allow_credit' => true, 'reason' => 'Explicitly replace inactive sponsor', 'referral_replacement_1' => $fourth->id])->assertOk();
        $this->postEarnings($manual);
        $this->assertDatabaseHas('commission_allocations', ['order_id' => $manual->id, 'user_id' => $fourth->id, 'category' => 'direct_referral', 'amount' => '10.00']);
        $this->assertDatabaseMissing('commission_allocations', ['order_id' => $manual->id, 'user_id' => $inactive->id]);
        $this->assertSame($finance->id, $manual->fresh()->commission_policy_snapshot['referral_replacements'][1]['authorized_by']);
        $snapshot = $manual->fresh()->commission_policy_snapshot;
        $this->postJson('/api/v1/orders/'.$manual->id.'/decision', ['status' => 'approved', 'reason' => 'Attempt duplicate reassignment', 'referral_replacement_1' => $second->id])->assertUnprocessable();
        $this->assertSame($snapshot, $manual->fresh()->commission_policy_snapshot);
    }

    public function test_replacement_requires_delegated_financial_edit_permission_and_an_eligible_distinct_recipient(): void
    {
        $sponsor = User::factory()->dealer()->create(['status' => 'suspended']);
        $buyer = User::factory()->dealer()->create(['sponsor_id' => $sponsor->id]);
        $order = $this->purchase($buyer);
        $order->update(['paid' => '100.00']);
        $replacement = User::factory()->dealer()->create();
        $data = ['status' => 'approved', 'reason' => 'Review inactive sponsor replacement', 'referral_replacement_1' => $replacement->id];
        $this->actingAs(User::factory()->staff('account_manager')->create())->postJson('/api/v1/orders/'.$order->id.'/decision', $data)->assertForbidden();
        $finance = User::factory()->staff('finance')->create();
        $this->actingAs($finance)->postJson('/api/v1/orders/'.$order->id.'/decision', [...$data, 'referral_replacement_1' => $buyer->id])->assertUnprocessable();
        $sponsor->update(['status' => 'active']);
        $this->postJson('/api/v1/orders/'.$order->id.'/decision', $data)->assertUnprocessable();
        $this->assertSame('submitted', $order->fresh()->status);
    }

    public function test_partner_salary_and_royalty_use_direct_authorized_settlement_without_a_second_reviewer(): void
    {
        $order = $this->purchase(User::factory()->dealer()->create(), 250);
        $this->approve($order);
        $this->postEarnings($order);
        $this->travelTo(CarbonImmutable::parse('2026-10-01T06:00:00Z'));
        $finance = User::factory()->staff('finance')->create();
        $this->actingAs($finance)->postJson('/api/v1/finance/settlements', ['kind' => 'salary', 'period_month' => '2026-09', 'request_key' => (string) Str::uuid()])->assertOk();
        $this->postJson('/api/v1/finance/settlements', ['kind' => 'royalty', 'period_month' => '2026-09', 'request_key' => (string) Str::uuid()])->assertOk();
        $this->assertDatabaseHas('wallets', ['user_id' => $order->user_id, 'available' => '2000.00']);
        $this->assertDatabaseHas('audit_events', ['actor_id' => $finance->id, 'action' => 'commissions.salary_pool_settled']);
        $this->assertDatabaseHas('audit_events', ['actor_id' => $finance->id, 'action' => 'commissions.royalty_pool_settled']);
    }

    public function test_an_inactive_recipients_posted_hold_can_be_reassigned_once_without_rewriting_the_approved_policy(): void
    {
        $inactive = User::factory()->dealer()->create(['status' => 'suspended']);
        $order = $this->purchase(User::factory()->dealer()->create(['sponsor_id' => $inactive->id]));
        $this->approve($order);
        $this->postEarnings($order);
        $snapshot = $order->fresh()->commission_policy_snapshot;
        $fundId = DB::table('commission_allocations')->where('order_id', $order->id)->where('category', 'direct_referral')->value('id');
        $replacement = User::factory()->dealer()->create();
        $finance = User::factory()->staff('finance')->create();
        $url = '/api/v1/operations/holds/'.$fundId.'/reassign';
        $data = ['user_id' => $replacement->id, 'request_key' => (string) Str::uuid(), 'reason' => 'Reviewed replacement of inactive recipient'];
        $this->actingAs($finance)->postJson($url, $data)->assertOk();
        $this->postJson($url, $data)->assertOk();
        $this->postJson($url, [...$data, 'reason' => 'Changed reason for the same key'])->assertConflict();
        $this->assertDatabaseHas('wallets', ['user_id' => $replacement->id, 'available' => '10.00']);
        $this->assertDatabaseMissing('wallets', ['user_id' => $inactive->id]);
        $this->assertSame($snapshot, $order->fresh()->commission_policy_snapshot);
        $this->assertDatabaseHas('audit_events', ['actor_id' => $finance->id, 'action' => 'commission.hold_reassigned', 'subject' => (string) $fundId]);
        app(CommissionService::class)->reverseOrderPartial($order->fresh(), '50.00', 'Source purchase partially returned', 'REASSIGNED-RETURN');
        $this->assertDatabaseHas('wallets', ['user_id' => $replacement->id, 'available' => '5.00']);
    }

    public function test_hold_reassignment_rejects_unauthorized_duplicate_and_disabled_posting_requests(): void
    {
        $second = User::factory()->dealer()->create();
        $inactive = User::factory()->dealer()->create(['status' => 'suspended', 'sponsor_id' => $second->id]);
        $order = $this->purchase(User::factory()->dealer()->create(['sponsor_id' => $inactive->id]));
        $this->approve($order);
        $this->postEarnings($order);
        $fundId = DB::table('commission_allocations')->where('order_id', $order->id)->where('category', 'direct_referral')->value('id');
        $url = '/api/v1/operations/holds/'.$fundId.'/reassign';
        $data = ['user_id' => $second->id, 'request_key' => (string) Str::uuid(), 'reason' => 'Attempt a duplicate referral recipient'];
        $limited = User::factory()->staff('employee')->create(['profile' => ['action_permissions' => ['finance' => ['view' => true, 'approve' => true]]]]);
        $this->actingAs($limited)->postJson($url, $data)->assertForbidden();
        $this->actingAs(User::factory()->staff('finance')->create())->postJson($url, $data)->assertUnprocessable();
        config(['madina.financial_activation' => false]);
        $this->postJson($url, [...$data, 'user_id' => User::factory()->dealer()->create()->id])->assertUnprocessable();
        config(['madina.financial_activation' => true]);
        DB::table('commission_batches')->where('order_id', $order->id)->delete();
        $this->postJson($url, [...$data, 'user_id' => User::factory()->dealer()->create()->id])->assertUnprocessable()->assertJsonValidationErrors('hold');
        $this->assertDatabaseHas('commission_allocations', ['id' => $fundId, 'user_id' => $inactive->id, 'amount' => '10.00', 'status' => 'held']);
    }
}
