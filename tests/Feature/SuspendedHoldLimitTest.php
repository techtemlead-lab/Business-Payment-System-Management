<?php

namespace Tests\Feature;

use App\Domain\Commissions\CommissionPolicy;
use App\Domain\Commissions\CommissionService;
use App\Domain\Finance\CompanyFunds;
use App\Domain\Finance\FinancialReadiness;
use App\Domain\Finance\UnownedShareSweeps;
use App\Domain\Orders\OrderService;
use App\Models\CommissionAllocation;
use App\Models\Order;
use App\Models\Product;
use App\Models\User;
use Database\Seeders\CommissionRuleSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\TestCase;

/**
 * Owner decision 2026-11-21: commission held for a suspended (not closed) partner waits for them to be active
 * again only for the held-share limit (3 months unless changed); after that it goes to company profit.
 */
class SuspendedHoldLimitTest extends TestCase
{
    use RefreshDatabase;

    private User $owner;

    protected function setUp(): void
    {
        parent::setUp();
        $this->withoutVite();
        $this->enableFinancialPosting();
        $this->seed(CommissionRuleSeeder::class);
        $this->seedClassicPlaceCodes();
        $this->owner = User::factory()->create(['role' => 'super_admin']);
        $this->travelTo(Carbon::parse('2026-11-10 10:00:00'));
    }

    public function test_held_referral_and_own_cashback_go_to_company_profit_after_three_months_not_before(): void
    {
        $upline = User::factory()->dealer()->create(['status' => 'suspended']);
        $buyer = User::factory()->dealer()->create(['status' => 'active', 'sponsor_id' => $upline->id]);
        $order = $this->purchase($buyer, suspendBuyerFirst: true);
        $referral = CommissionAllocation::query()->where('order_id', $order->id)->where('category', 'direct_referral')->sole();
        $cashback = CommissionAllocation::query()->where('order_id', $order->id)->where('category', 'own_dealer_cashback')->sole();
        $this->assertSame(['held', 'held'], [$referral->status, $cashback->status]);
        $sweeps = app(UnownedShareSweeps::class);

        // One minute before the three months are up: nothing moves.
        $this->travelTo(Carbon::parse('2027-02-10 09:59:00'));
        $this->assertSame('nothing_to_move', $sweeps->suspendedHoldsToCompanyProfit()['status']);
        $this->assertSame('held', $referral->fresh()->status);

        $profitBefore = app(CompanyFunds::class)->balances()['company_profit']['money_in'];
        $this->travelTo(Carbon::parse('2027-02-10 10:01:00'));
        $result = $sweeps->suspendedHoldsToCompanyProfit();

        $this->assertSame(['status' => 'moved', 'months' => 3, 'moved_count' => 2, 'total_amount' => '1500.00'], $result);
        $this->assertSame(['forfeited', 'forfeited'], [$referral->fresh()->status, $cashback->fresh()->status]);
        $this->assertSame('suspended_hold_expired', $cashback->fresh()->metadata['forfeit_reason']);
        $this->assertSame($profitBefore + 150000, app(CompanyFunds::class)->balances()['company_profit']['money_in']);
        $this->assertSame(2, CommissionAllocation::query()->where('metadata->reason_key', UnownedShareSweeps::SuspendedHoldReason)->count());
        $audit = DB::table('audit_events')->where('action', 'commission.suspended_holds_to_company_profit')->sole();
        $this->assertSame(UnownedShareSweeps::SuspendedHoldReason, json_decode((string) $audit->metadata, true)['reason']);
        $this->assertDatabaseHas('account_notices', ['user_id' => $upline->id, 'title' => __('money_core.notice_suspended_hold_title')]);
        $this->assertDatabaseHas('account_notices', ['user_id' => $buyer->id, 'title' => __('money_core.notice_suspended_hold_title')]);

        // Running again moves nothing twice, and the books still agree.
        $this->assertSame('nothing_to_move', $sweeps->suspendedHoldsToCompanyProfit()['status']);
        // Only the bank receipt check fails, because the test order has no bank receipt.
        $this->assertSame(['order_receipts'], FinancialReadiness::failedNames(app(FinancialReadiness::class)->checks()));
    }

    public function test_a_partner_made_active_before_the_limit_is_paid_and_nothing_moves_later(): void
    {
        $upline = User::factory()->dealer()->create(['status' => 'suspended']);
        $order = $this->purchase(User::factory()->dealer()->create(['status' => 'active', 'sponsor_id' => $upline->id]));
        $held = CommissionAllocation::query()->where('order_id', $order->id)->where('category', 'direct_referral')->sole();

        $this->travelTo(Carbon::parse('2027-02-09 10:00:00'));
        $this->actingAs($this->owner)->onPortal('sadmin')->patch('/commands/accounts/'.$upline->id, [
            'name' => $upline->name, 'status' => 'active', 'reason' => 'Partner came back',
        ])->assertRedirect();
        $this->assertSame('available', $held->fresh()->status);

        $this->travelTo(Carbon::parse('2027-03-01 10:00:00'));
        $this->assertSame('nothing_to_move', app(UnownedShareSweeps::class)->suspendedHoldsToCompanyProfit()['status']);
        $this->assertSame('1000.00', DB::table('wallets')->where('user_id', $upline->id)->value('available'));
    }

    public function test_closed_accounts_and_area_shares_keep_their_own_rules(): void
    {
        $closed = User::factory()->dealer()->create(['status' => 'suspended', 'closed_at' => now()]);
        $order = $this->purchase(User::factory()->dealer()->create(['status' => 'active', 'sponsor_id' => $closed->id]));
        $held = CommissionAllocation::query()->where('order_id', $order->id)->where('category', 'direct_referral')->sole();

        $this->travelTo(Carbon::parse('2027-02-10 10:01:00'));
        $this->assertSame('nothing_to_move', app(UnownedShareSweeps::class)->suspendedHoldsToCompanyProfit()['status']);
        $this->assertSame('held', $held->fresh()->status);
        // The closed-account rule (3 months after closing) moves it instead.
        $this->assertSame('moved', app(UnownedShareSweeps::class)->closedAccountHoldsToCompanyProfit()['status']);
        $this->assertSame(UnownedShareSweeps::ClosedAccountReason, CommissionAllocation::query()->where('metadata->source_allocation_id', $held->id)->sole()->metadata['reason_key']);
    }

    public function test_the_owners_limit_is_used_and_zero_means_wait_for_ever(): void
    {
        $upline = User::factory()->dealer()->create(['status' => 'suspended']);
        $order = $this->purchase(User::factory()->dealer()->create(['status' => 'active', 'sponsor_id' => $upline->id]));
        $held = CommissionAllocation::query()->where('order_id', $order->id)->where('category', 'direct_referral')->sole();

        $this->setHeldMonths(0);
        $this->travelTo(Carbon::parse('2028-01-01 10:00:00'));
        $this->assertSame('off', app(UnownedShareSweeps::class)->suspendedHoldsToCompanyProfit()['status']);
        $this->assertSame('held', $held->fresh()->status);

        $this->setHeldMonths(1);
        $this->assertSame(1, app(UnownedShareSweeps::class)->suspendedHoldsToCompanyProfit()['months']);
        $this->assertSame('forfeited', $held->fresh()->status);
    }

    public function test_the_hourly_run_moves_expired_holds(): void
    {
        $upline = User::factory()->dealer()->create(['status' => 'suspended']);
        $order = $this->purchase(User::factory()->dealer()->create(['status' => 'active', 'sponsor_id' => $upline->id]));
        $held = CommissionAllocation::query()->where('order_id', $order->id)->where('category', 'direct_referral')->sole();

        $this->travelTo(Carbon::parse('2027-02-11 10:00:00'));
        $this->artisan('finance:auto-approve')->assertSuccessful();

        $this->assertSame('forfeited', $held->fresh()->status);
    }

    private function purchase(User $buyer, bool $suspendBuyerFirst = false): Order
    {
        $product = Product::factory()->create(['mrp' => '20000.00', 'dealer_price' => '10000.00']);
        $order = app(OrderService::class)->create($buyer, ['items' => [['product_id' => $product->id, 'quantity' => 1]], 'idempotency_key' => (string) Str::uuid()]);
        $order->update(['paid' => '10000.00', 'status' => 'delivered']);
        if ($suspendBuyerFirst) {
            $buyer->forceFill(['status' => 'suspended'])->save();
        }
        app(CommissionService::class)->processOrder($order->fresh());

        return $order;
    }

    private function setHeldMonths(int $months): void
    {
        $this->actingAs($this->owner);
        app(CommissionPolicy::class)->update($this->owner, ['area_dealer_pool' => ['parameters' => ['held_share_months' => (string) $months]]]);
    }
}
