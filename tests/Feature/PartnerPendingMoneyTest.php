<?php

namespace Tests\Feature;

use App\Domain\Accounts\PartnerDashboardHome;
use App\Domain\Accounts\PartnerPendingMoney;
use App\Domain\Commissions\CommissionPosting;
use App\Domain\Orders\OrderService;
use App\Domain\Payouts\ManualPayoutService;
use App\Models\AreaDealerAssignment;
use App\Models\CommissionAllocation;
use App\Models\Order;
use App\Models\PlaceCode;
use App\Models\Product;
use App\Models\User;
use App\Support\TakaText;
use Database\Seeders\CommissionRuleSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\TestCase;

/**
 * The wallet's pending balance: area shares waiting for the target and the month-end salary are shown
 * as pending, are never part of the usable balance, and what the month close does with them is unchanged.
 */
class PartnerPendingMoneyTest extends TestCase
{
    use RefreshDatabase;

    private PlaceCode $division;

    protected function setUp(): void
    {
        parent::setUp();
        $this->withoutVite();
        $this->enableFinancialPosting();
        config(['madina.auto_release_quota_holds' => false]);
        $this->seed(CommissionRuleSeeder::class);
        $this->travelTo(now()->setDate(2026, 11, 10)->startOfDay());
        $this->division = PlaceCode::factory()->division()->create(['name' => 'Rangpur']);
    }

    private function purchase(User $buyer, string $cash = '100.00', ?string $mrp = null): Order
    {
        $product = Product::factory()->create(['mrp' => $mrp ?? bcmul($cash, '2', 2), 'dealer_price' => $cash, 'sub_dealer_price' => $buyer->role === 'sub_dealer' ? $cash : null]);
        $order = app(OrderService::class)->create($buyer, ['items' => [['product_id' => $product->id, 'quantity' => 1]], 'idempotency_key' => (string) Str::uuid()]);
        $order->update(['status' => 'delivered', 'paid' => $cash]);
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

    private function available(User $user): string
    {
        return (string) (DB::table('wallets')->where('user_id', $user->id)->value('available') ?? '0.00');
    }

    private function myMoney(User $partner, callable $assert): void
    {
        $this->actingAs($partner)->onPortal($partner->role === 'sub_dealer' ? 'subdealer' : 'dealer')->get('/my-money')->assertOk()->assertInertia($assert);
    }

    public function test_an_area_share_waiting_for_the_target_is_pending_with_its_reason_and_not_in_the_balance(): void
    {
        $dealer = $this->seatedDealer();
        $share = $this->areaShare($this->purchase($this->buyer()));
        $this->assertSame('held', $share->status);

        $this->myMoney($dealer, fn (Assert $page) => $page
            ->where('pending.total', (string) $share->amount)
            ->where('pending.count', 1)
            ->where('pending.lines.0.reason', 'target_not_met')
            ->where('pending.lines.0.head', 'area_division')
            ->where('pending.lines.0.amount', (string) $share->amount)
            ->where('pending.lines.0.estimate', false)
            ->where('summary.available', '0.00')
            ->where('summary.lifetime.earned', '0.00')
            ->where('held_commission.lines', []));
        $this->assertSame('0.00', $this->available($dealer));
    }

    public function test_the_pending_balance_shows_beside_the_balance_on_the_home_screen_data_too(): void
    {
        $dealer = $this->seatedDealer();
        $share = $this->areaShare($this->purchase($this->buyer()));

        $home = app(PartnerDashboardHome::class)->for($dealer);

        $this->assertSame((string) $share->amount, $home['wallet']['pending']['total']);
        $this->assertSame('0.00', $home['totals']['balance']);
    }

    public function test_pending_money_cannot_be_withdrawn_or_borrowed_against(): void
    {
        $dealer = $this->seatedDealer();
        $share = $this->areaShare($this->purchase($this->buyer(), '100000.00'));
        DB::table('wallets')->updateOrInsert(['user_id' => $dealer->id], ['available' => '50.00', 'held' => '0.00', 'created_at' => now(), 'updated_at' => now()]);
        $this->assertGreaterThan(50, (float) $share->amount);

        try {
            app(ManualPayoutService::class)->request($dealer, [
                'amount' => (string) $share->amount, 'channel' => 'bKash', 'destination' => '01700000000', 'idempotency_key' => (string) Str::uuid(),
            ]);
            $this->fail('A withdrawal larger than the usable balance must be refused, whatever is pending.');
        } catch (ValidationException $exception) {
            $this->assertSame(__('payouts.err_over_balance', ['balance' => TakaText::amount('50.00')]), $exception->errors()['amount'][0]);
        }

        $this->assertSame('50.00', $this->available($dealer));
        $this->assertSame(0, DB::table('withdrawals')->where('user_id', $dealer->id)->count());
    }

    public function test_a_share_whose_target_is_met_but_not_yet_released_is_pending_and_says_so(): void
    {
        $dealer = $this->seatedDealer('100.00');
        $share = $this->areaShare($this->purchase($this->buyer()));
        $this->purchase($dealer);
        $this->assertSame('held', $share->fresh()->status);
        // The dealer's own first order was posted before the target was reached, so its area share is held too.
        $held = number_format((float) DB::table('commission_allocations')->where('user_id', $dealer->id)->where('status', 'held')->where('category', 'like', 'area\_%')->sum('amount'), 2, '.', '');

        $this->myMoney($dealer, fn (Assert $page) => $page
            ->where('pending.lines.0.reason', 'target_met_waiting')
            ->where('pending.total', $held));
    }

    public function test_a_missed_target_at_month_end_shows_not_received_and_adds_nothing_to_the_wallet(): void
    {
        $dealer = $this->seatedDealer();
        $share = $this->areaShare($this->purchase($this->buyer()));

        $this->closeNovember();

        $this->assertSame('forfeited', $share->fresh()->status);
        $this->assertSame('0.00', $this->available($dealer));
        $this->assertSame(1, CommissionAllocation::where('metadata->source_allocation_id', $share->id)->count());
        $this->myMoney($dealer, fn (Assert $page) => $page
            ->where('pending.total', '0.00')
            ->where('pending.lines', [])
            ->where('not_earned_area.0.month', '2026-11')
            ->where('not_earned_area.0.amount', (string) $share->amount)
            ->where('summary.available', '0.00'));
    }

    public function test_a_met_target_is_paid_once_at_month_end_however_often_the_close_runs(): void
    {
        config(['madina.auto_release_quota_holds' => true]);
        $dealer = $this->seatedDealer('100.00');
        $share = $this->areaShare($this->purchase($this->buyer()));
        $this->purchase($dealer);
        $before = $this->available($dealer);

        $this->closeNovember();
        $afterFirst = $this->available($dealer);
        $this->artisan('finance:auto-approve')->assertSuccessful();

        $this->assertNotSame($before, $afterFirst);
        $this->assertSame($afterFirst, $this->available($dealer));
        $this->assertSame(1, DB::table('journal_entries')->where('event_key', 'commission.hold_release.'.$share->id)->count());
        $this->assertSame(0, CommissionAllocation::where('metadata->reason_key', 'retained_reason_profit_quota_missed')->count());
        $this->myMoney($dealer, fn (Assert $page) => $page
            ->where('pending.total', '0.00')
            ->where('pending.lines', [])
            ->where('pending.paid.0.amount', (string) $share->amount)
            ->where('not_earned_area', []));
    }

    public function test_incentives_and_other_money_are_not_part_of_the_pending_balance(): void
    {
        $dealer = $this->seatedDealer();
        $buyer = $this->buyer();
        $order = $this->purchase($buyer);
        foreach (['rank_incentive_pool', 'rank_royalty_pool', 'car_bike_incentive_fund', 'sub_dealer_tour', 'direct_referral'] as $category) {
            DB::table('commission_allocations')->insert([
                'order_id' => $order->id, 'user_id' => $dealer->id, 'category' => $category, 'rate_percentage' => '1.00', 'cash_basis' => '100.00',
                'amount' => '77.00', 'status' => $category === 'direct_referral' ? 'held' : 'pending',
                'metadata' => json_encode(['hold_reason' => 'quota', 'period_month' => '2026-11'], JSON_THROW_ON_ERROR), 'created_at' => now(), 'updated_at' => now(),
            ]);
        }
        $share = $this->areaShare($order);

        $pending = app(PartnerPendingMoney::class)->for($dealer);

        $this->assertSame((string) $share->amount, $pending['total']);
        $this->assertSame(['area_division'], array_column($pending['lines'], 'head'));
    }

    public function test_a_sub_dealers_salary_is_pending_until_the_month_close_pays_it(): void
    {
        $subDealer = User::factory()->subDealer()->inPlace($this->division)->create();
        $this->purchase($subDealer, '120.00', '200.00');
        $set = number_format((float) DB::table('commission_allocations')->where('user_id', $subDealer->id)->where('category', 'sub_dealer_salary')->where('status', 'pending')->sum('amount'), 2, '.', '');
        $this->assertGreaterThan(0, (float) $set);
        // The sub-dealer's own cashback is already in the wallet; the salary is not.
        $before = $this->available($subDealer);

        $this->myMoney($subDealer, fn (Assert $page) => $page
            ->where('pending.total', $set)
            ->where('pending.lines.0.head', 'salary')
            ->where('pending.lines.0.reason', 'month_end')
            ->where('pending.lines.0.estimate', false)
            ->where('summary.available', $before));

        $this->closeNovember();

        $after = number_format((float) $before + (float) $set, 2, '.', '');
        $this->assertSame($after, $this->available($subDealer));
        $this->myMoney($subDealer, fn (Assert $page) => $page
            ->where('pending.total', '0.00')
            ->where('pending.lines', [])
            ->where('summary.available', $after));
    }

    public function test_a_dealers_salary_share_is_a_pending_estimate_until_the_month_close_pays_it(): void
    {
        $dealer = User::factory()->inPlace($this->division)->create();
        $this->purchase($dealer, '20000.00');

        $pending = app(PartnerPendingMoney::class)->for($dealer);
        $this->assertCount(1, $pending['lines']);
        $this->assertSame('salary', $pending['lines'][0]['head']);
        $this->assertSame('month_end', $pending['lines'][0]['reason']);
        $this->assertTrue($pending['lines'][0]['estimate']);
        $this->assertTrue($pending['has_estimate']);
        $estimate = $pending['total'];
        $this->assertGreaterThan(0, (float) $estimate);
        // The dealer's own cashback is already in the wallet; the salary share is not.
        $before = $this->available($dealer);

        $this->closeNovember();

        $this->assertSame([], app(PartnerPendingMoney::class)->for($dealer)['lines']);
        $this->assertSame(number_format((float) $before + (float) $estimate, 2, '.', ''), $this->available($dealer));
    }

    public function test_a_partner_with_nothing_pending_sees_nothing_new(): void
    {
        $dealer = User::factory()->dealer()->create();
        $subDealer = User::factory()->subDealer()->create();

        foreach ([$dealer, $subDealer] as $partner) {
            $this->myMoney($partner, fn (Assert $page) => $page
                ->where('pending.total', '0.00')->where('pending.count', 0)->where('pending.lines', [])->where('pending.paid', [])
                ->where('not_earned_area', [])->where('held_commission.lines', []));
        }
    }

    public function test_the_office_partner_view_shows_the_same_pending_figure(): void
    {
        $dealer = $this->seatedDealer();
        $share = $this->areaShare($this->purchase($this->buyer()));
        $finance = User::factory()->create(['role' => 'finance', 'status' => 'active']);

        $this->actingAs($finance)->onPortal('manage')->get('/treasury/partners/'.$dealer->id)->assertOk()->assertInertia(fn (Assert $page) => $page
            ->component('TreasuryPartner')
            ->where('pending.total', (string) $share->amount)
            ->where('pending.lines.0.reason', 'target_not_met'));
    }
}
