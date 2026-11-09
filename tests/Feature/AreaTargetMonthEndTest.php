<?php

namespace Tests\Feature;

use App\Domain\Commissions\CommissionPosting;
use App\Domain\Finance\EventLog;
use App\Domain\Finance\ReserveService;
use App\Domain\Finance\TreasuryFunds;
use App\Domain\Orders\OrderService;
use App\Models\AreaDealerAssignment;
use App\Models\CommissionAllocation;
use App\Models\Order;
use App\Models\PlaceCode;
use App\Models\Product;
use App\Models\User;
use App\Support\Money;
use Database\Seeders\CommissionRuleSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Inertia\Testing\AssertableInertia;
use Tests\TestCase;

/**
 * Owner rule (2026-11-08): when an area dealer's target is not met, the money goes to the company
 * profit fund at month end. It does not roll over and it does not stay held.
 */
class AreaTargetMonthEndTest extends TestCase
{
    use RefreshDatabase;

    private PlaceCode $division;

    protected function setUp(): void
    {
        parent::setUp();
        $this->withoutVite();
        $this->enableFinancialPosting();
        $this->seed(CommissionRuleSeeder::class);
        $this->travelTo(now()->setDate(2026, 11, 10)->startOfDay());
        $this->division = PlaceCode::factory()->division()->create(['name' => 'Rangpur']);
    }

    private function purchase(User $buyer, string $cash = '100.00', string $status = 'delivered'): Order
    {
        $product = Product::factory()->create(['mrp' => bcmul($cash, '2', 2), 'dealer_price' => $cash]);
        $order = app(OrderService::class)->create($buyer, ['items' => [['product_id' => $product->id, 'quantity' => 1]], 'idempotency_key' => (string) Str::uuid()]);
        $order->update(['status' => $status, 'paid' => $cash]);
        app(CommissionPosting::class)->process($order->fresh());

        return $order;
    }

    private function seatedDealer(string $target = '100000.00'): User
    {
        $dealer = User::factory()->inPlace($this->division)->create();
        AreaDealerAssignment::create(['user_id' => $dealer->id, 'level' => 'division', 'place_code_id' => $this->division->id,
            'division' => 'Rangpur', 'zone_name' => 'Rangpur', 'monthly_quota' => $target, 'is_active' => true]);

        return $dealer;
    }

    private function buyer(): User
    {
        return User::factory()->inPlace($this->division)->create();
    }

    private function areaShare(Order $order): CommissionAllocation
    {
        return CommissionAllocation::where('order_id', $order->id)->where('category', 'area_division')->sole();
    }

    private function closeNovember(): void
    {
        $this->travelTo(now()->setDate(2026, 12, 1)->setTime(1, 0));
        $this->artisan('finance:auto-approve')->assertSuccessful();
    }

    private function movedToProfit(): int
    {
        return CommissionAllocation::where('metadata->reason_key', 'retained_reason_profit_quota_missed')->count();
    }

    private function allOf(): Request
    {
        return Request::create('/x', 'GET', ['period' => '']);
    }

    public function test_a_missed_target_moves_the_share_to_the_company_profit_fund_at_month_end(): void
    {
        $dealer = $this->seatedDealer();
        $share = $this->areaShare($this->purchase($this->buyer()));
        $this->assertSame('held', $share->status);

        $this->closeNovember();

        $this->assertSame('forfeited', $share->fresh()->status);
        $profit = CommissionAllocation::where('metadata->source_allocation_id', $share->id)->sole();
        $this->assertSame('company_profit_fund', $profit->category);
        $this->assertSame((string) $share->amount, (string) $profit->amount);
        $this->assertSame($dealer->id, $profit->metadata['intended_user_id']);
        $this->assertSame('2026-11', $profit->metadata['period_month']);
        $this->assertSame('0.00', (string) (DB::table('wallets')->where('user_id', $dealer->id)->value('available') ?? '0.00'));
    }

    public function test_a_missed_target_does_not_stay_held_because_the_dealer_is_suspended(): void
    {
        $dealer = $this->seatedDealer();
        $share = $this->areaShare($this->purchase($this->buyer()));
        $dealer->forceFill(['status' => 'suspended'])->save();

        $this->closeNovember();

        $this->assertSame('forfeited', $share->fresh()->status);
        $this->assertSame(1, $this->movedToProfit());
    }

    public function test_a_close_that_ran_late_still_moves_the_money_instead_of_leaving_it_held(): void
    {
        $this->seatedDealer();
        $share = $this->areaShare($this->purchase($this->buyer()));

        $this->travelTo(now()->setDate(2027, 3, 2)->setTime(1, 0));
        $this->artisan('finance:auto-approve')->assertSuccessful();

        $this->assertSame('forfeited', $share->fresh()->status);
    }

    public function test_only_delivered_goods_count_toward_the_target(): void
    {
        $dealer = $this->seatedDealer('500.00');
        $share = $this->areaShare($this->purchase($this->buyer()));
        $this->purchase($dealer, '1000.00', 'approved');

        $this->closeNovember();

        $this->assertSame('forfeited', $share->fresh()->status);
    }

    public function test_a_met_target_pays_as_before(): void
    {
        $dealer = $this->seatedDealer('100.00');
        $heldBeforeTarget = $this->areaShare($this->purchase($this->buyer()));
        $this->purchase($dealer);
        $paidAfterTarget = $this->areaShare($this->purchase($this->buyer()));
        $this->assertSame('held', $heldBeforeTarget->status);
        $this->assertSame('available', $paidAfterTarget->status);
        $wallet = DB::table('wallets')->where('user_id', $dealer->id)->value('available');

        $this->closeNovember();

        $this->assertSame('held', $heldBeforeTarget->fresh()->status);
        $this->assertSame('available', $paidAfterTarget->fresh()->status);
        $this->assertSame($wallet, DB::table('wallets')->where('user_id', $dealer->id)->value('available'));
        $this->assertSame(0, $this->movedToProfit());
        app(ReserveService::class)->release(User::factory()->staff('finance')->create(), $heldBeforeTarget->id, 'Target reached');
        $this->assertSame('available', $heldBeforeTarget->fresh()->status);
    }

    public function test_a_dealer_held_only_for_being_suspended_keeps_the_hold_when_the_target_was_met(): void
    {
        $dealer = $this->seatedDealer('100.00');
        $this->purchase($dealer);
        $dealer->forceFill(['status' => 'suspended'])->save();
        $share = $this->areaShare($this->purchase($this->buyer()));
        $this->assertSame('held', $share->status);

        $this->closeNovember();

        $this->assertSame('held', $share->fresh()->status);
        $this->assertSame(0, $this->movedToProfit());
    }

    public function test_running_the_month_end_twice_moves_nothing_further(): void
    {
        $dealer = $this->seatedDealer();
        $this->areaShare($this->purchase($this->buyer()));
        $this->closeNovember();
        $balance = app(TreasuryFunds::class)->card('company_profit', true)['balance'];

        $this->artisan('finance:auto-approve')->assertSuccessful();
        $second = app(ReserveService::class)->forfeitUnmetQuotaHolds('2026-11');

        $this->assertSame(0, $second['forfeited_count']);
        $this->assertSame(1, $this->movedToProfit());
        $this->assertSame($balance, app(TreasuryFunds::class)->card('company_profit', true)['balance']);
        $this->assertSame(1, DB::table('account_notices')->where('user_id', $dealer->id)->where('title', __('portal.notice_area_target_missed_title', [], $dealer->preferredLocale()))->count());
        $this->assertSame(1, DB::table('audit_events')->where('action', 'commission.quota_holds_forfeited')->count());
    }

    public function test_shares_held_from_a_month_closed_before_the_rule_are_left_where_they_are(): void
    {
        $this->travelTo(now()->setDate(2026, 10, 15)->startOfDay());
        $this->seatedDealer();
        $share = $this->areaShare($this->purchase($this->buyer()));
        $this->assertSame('2026-10', $share->metadata['period_month']);

        $this->closeNovember();
        $direct = app(ReserveService::class)->forfeitUnmetQuotaHolds('2026-10');

        $this->assertSame('before_rule', $direct['status']);
        $this->assertSame(0, $direct['forfeited_count']);
        $this->assertSame('held', $share->fresh()->status);
        $this->assertSame(0, $this->movedToProfit());
        $this->assertDatabaseMissing('audit_events', ['action' => 'commission.quota_holds_forfeited']);
    }

    public function test_treasury_totals_agree_and_show_the_reason_in_both_languages(): void
    {
        $this->seatedDealer();
        $share = $this->areaShare($this->purchase($this->buyer()));
        $funds = app(TreasuryFunds::class);
        $before = Money::cents($funds->card('company_profit', true)['money_in']);

        $this->travelTo(now()->setDate(2026, 12, 1)->setTime(1, 0));
        app(ReserveService::class)->forfeitUnmetQuotaHolds('2026-11');

        $card = $funds->card('company_profit', true);
        $this->assertSame($before + Money::cents((string) $share->amount), Money::cents($card['money_in']));
        $this->assertSame($card['money_in'], $funds->moneyIn($this->allOf(), 'company_profit')['totals']['sums']['amount']);
        $this->assertSame(Money::cents($card['money_in']) - Money::cents($card['money_out']), Money::cents($card['balance']));

        $viewer = User::factory()->staff()->create();
        foreach (['en' => 'target', 'bn' => 'টার্গেট'] as $locale => $word) {
            app()->setLocale($locale);
            $rows = collect($funds->moneyIn($this->allOf(), 'company_profit')['rows']->items());
            $this->assertStringContainsString($word, (string) $rows->firstWhere('reason', '!=', null)['reason']);
            $event = collect(app(EventLog::class)->table($this->allOf(), $viewer, 'admin')['rows']->items())->firstWhere('action', 'moved_to_profit');
            $this->assertStringContainsString($word, (string) $event['label']);
            $this->assertSame((string) $share->amount, (string) $event['amount']);
        }
    }

    public function test_the_area_dealer_sees_it_plainly_as_not_earned_because_the_target_was_missed(): void
    {
        $dealer = $this->seatedDealer();
        $first = $this->areaShare($this->purchase($this->buyer()));
        $second = $this->areaShare($this->purchase($this->buyer()));
        $total = Money::decimal(Money::cents((string) $first->amount) + Money::cents((string) $second->amount));

        $this->closeNovember();

        $notices = DB::table('account_notices')->where('user_id', $dealer->id)->where('title', __('portal.notice_area_target_missed_title', [], $dealer->preferredLocale()))->get();
        $this->assertCount(1, $notices);
        $this->assertStringContainsString($total, (string) $notices->first()->body);
        $this->actingAs($dealer)->onPortal('dealer')->get('/my-money')->assertOk()->assertInertia(fn (AssertableInertia $page) => $page
            ->where('not_earned_area.0.month', '2026-11')
            ->where('not_earned_area.0.amount', $total));
        $this->assertSame('0.00', (string) (DB::table('wallets')->where('user_id', $dealer->id)->value('available') ?? '0.00'));
    }
}
