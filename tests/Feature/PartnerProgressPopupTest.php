<?php

namespace Tests\Feature;

use App\Domain\Commissions\CommissionPolicy;
use App\Domain\Commissions\CommissionQualification;
use App\Domain\Commissions\CommissionService;
use App\Domain\Commissions\CommissionSettlement;
use App\Domain\Orders\OrderService;
use App\Models\DealerRank;
use App\Models\Order;
use App\Models\PlaceCode;
use App\Models\Product;
use App\Models\ReferralTreePath;
use App\Models\User;
use App\Support\DashboardManagement;
use App\Support\Money;
use Database\Seeders\CommissionRuleSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Testing\TestResponse;
use Tests\TestCase;

/**
 * The "my progress" pop-up must show the same numbers that settlement and the reward checks
 * later pay out, with every target taken from the live commission rules.
 */
class PartnerProgressPopupTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->withoutVite();
        $this->enableFinancialPosting();
        $this->seed(CommissionRuleSeeder::class);
        $this->travelTo(now()->setDate(2026, 9, 15)->setTime(11, 0));
    }

    protected function tearDown(): void
    {
        $this->travelBack();
        DashboardManagement::reset();
        parent::tearDown();
    }

    public function test_dealer_below_the_salary_target_sees_what_is_done_and_left(): void
    {
        $dealer = User::factory()->create(['division' => 'Rangpur', 'district' => 'Rangpur']);
        $this->purchase($dealer, '4000.00', '8000.00');

        $salary = $this->progress($dealer)->assertOk()->json('data.salary');

        $target = CommissionPolicy::defaults()['dealer_monthly_salary']['parameters']['min_monthly_volume'];
        $this->assertSame('dealer_pool', $salary['kind']);
        $this->assertSame('not_met', $salary['status']);
        $this->assertSame(Money::decimal(app(CommissionQualification::class)->monthlyVolume($dealer->id, '2026-09')), $salary['current']);
        $this->assertSame('4000.00', $salary['current']);
        $this->assertSame($target, $salary['target']);
        $this->assertSame('6000.00', $salary['left']);
        $this->assertEquals(40.0, $salary['percent']);
        $this->assertNull($salary['estimate']);
        $this->assertSame('2.00', $salary['rate']);
    }

    public function test_dealer_who_met_the_target_sees_the_share_settlement_then_pays(): void
    {
        // The salary pool is shared inside the division place code the dealers' districts belong to.
        $rangpur = PlaceCode::factory()->division()->create(['name' => 'Rangpur']);
        $dealer = User::factory()->inPlace(PlaceCode::factory()->district($rangpur)->create(['name' => 'Rangpur']))->create();
        $neighbour = User::factory()->inPlace(PlaceCode::factory()->district($rangpur)->create(['name' => 'Dinajpur']))->create();
        $belowTarget = User::factory()->inPlace(PlaceCode::factory()->district($rangpur)->create(['name' => 'Nilphamari']))->create();
        $this->purchase($dealer, '12000.00', '24000.00');
        $this->purchase($neighbour, '10000.00', '20000.00');
        $this->purchase($belowTarget, '3000.00', '6000.00');

        $salary = $this->progress($dealer)->assertOk()->json('data.salary');
        $estimate = collect(app(CommissionSettlement::class)->salaryPlan('2026-09')['dealers'])->firstWhere('user_id', $dealer->id);

        $this->assertSame('met', $salary['status']);
        $this->assertEquals(100.0, $salary['percent']);
        $this->assertSame('0.00', $salary['left']);
        $this->assertSame($estimate['share'], $salary['estimate']['share']);
        $this->assertSame(2, $salary['estimate']['qualifiers']);
        // 2% of the division's ৳25,000 of purchases, shared by the two dealers who met the target.
        $this->assertSame('500.00', $salary['estimate']['division_pool']);

        $this->travelTo(now()->setDate(2026, 10, 1));
        $paid = collect(app(CommissionService::class)->settleMonthlySalaryPool('2026-09')['distributed'])->firstWhere('user_id', $dealer->id);
        $this->assertSame($salary['estimate']['share'], $paid['amount']);
    }

    public function test_salary_target_and_switch_follow_the_live_rules(): void
    {
        $owner = User::factory()->create(['role' => 'super_admin']);
        $dealer = User::factory()->create();
        $this->purchase($dealer, '12000.00', '24000.00');
        app(CommissionPolicy::class)->update($owner, ['dealer_monthly_salary' => ['parameters' => ['min_monthly_volume' => '20000.00']]]);

        $salary = $this->progress($dealer)->json('data.salary');
        $this->assertSame('not_met', $salary['status']);
        $this->assertSame('20000.00', $salary['target']);
        $this->assertSame('8000.00', $salary['left']);

        app(CommissionPolicy::class)->update($owner, ['dealer_monthly_salary' => ['is_active' => false]]);
        $this->assertSame('off', $this->progress($dealer)->json('data.salary.status'));
    }

    public function test_salary_waived_by_the_office_counts_as_met(): void
    {
        $dealer = User::factory()->create(['profile' => ['benefit_controls' => ['unconditional_salary' => true]]]);

        $salary = $this->progress($dealer)->json('data.salary');

        $this->assertSame('waived', $salary['status']);
        $this->assertSame('0.00', $salary['current']);
    }

    public function test_new_dealer_with_no_purchases_starts_from_zero_without_writing_anything(): void
    {
        $dealer = User::factory()->create();
        ReferralTreePath::attachUser($dealer->id, null);

        $data = $this->progress($dealer)->assertOk()->json('data');

        $this->assertSame('dealer', $data['role']);
        $this->assertSame(['month' => '2026-09', 'starts_on' => '2026-09-01', 'ends_on' => '2026-09-30', 'days_left' => 16], $data['period']);
        $this->assertSame('0.00', $data['salary']['current']);
        $this->assertEquals(0.0, $data['salary']['percent']);
        $this->assertSame('dealer', $data['rank']['current']);
        $this->assertSame('mo', $data['rank']['next']['rank']);
        $this->assertSame('own_purchases', $data['rank']['next']['measure']);
        $this->assertSame(CommissionPolicy::defaults()['rank_royalty']['parameters']['mo_target'], $data['rank']['next']['target']);
        $this->assertSame(CommissionPolicy::defaults()['rank_incentive']['parameters']['mo_bonus'], $data['rank']['next']['award']);
        $this->assertNull($data['rank']['award']);
        $this->assertNull($data['rank']['keep']);
        $this->assertSame(['bike', 'car'], array_column($data['rewards'], 'type'));
        $this->assertSame('in_progress', $data['rewards'][0]['status']);
        $this->assertSame('0.00', $data['rewards'][0]['current']);
        $this->assertNull($data['tour']);
        $this->assertDatabaseMissing('dealer_ranks', ['user_id' => $dealer->id]);
    }

    public function test_dealer_on_the_way_to_mo_sees_lifetime_purchases_against_the_live_target(): void
    {
        $dealer = User::factory()->create();
        $this->purchase($dealer, '100000.00', '200000.00');

        $next = $this->progress($dealer)->json('data.rank.next');

        $this->assertSame(Money::decimal(Money::cents((string) DealerRank::query()->where('user_id', $dealer->id)->value('lifetime_purchase_total'))), $next['current']);
        $this->assertSame('100000.00', $next['current']);
        $this->assertSame('150000.00', $next['left']);
        $this->assertEquals(40.0, $next['percent']);
    }

    public function test_mo_dealer_sees_team_leaders_needed_for_am_and_rank_keeping_from_paid_as(): void
    {
        $leader = User::factory()->create();
        ReferralTreePath::attachUser($leader->id, null);
        DealerRank::create(['user_id' => $leader->id, 'current_rank' => 'mo', 'achieved_at' => now()->subDays(60)]);
        foreach (range(1, 3) as $index) {
            $member = User::factory()->create(['sponsor_id' => $leader->id]);
            ReferralTreePath::attachUser($member->id, $leader->id);
            DealerRank::create(['user_id' => $member->id, 'current_rank' => $index === 3 ? 'am' : 'mo', 'achieved_at' => now()->subDays(5)]);
        }
        $inactive = User::factory()->create(['sponsor_id' => $leader->id, 'status' => 'suspended']);
        ReferralTreePath::attachUser($inactive->id, $leader->id);
        DealerRank::create(['user_id' => $inactive->id, 'current_rank' => 'mo', 'achieved_at' => now()->subDays(5)]);
        $this->purchase($leader, '2000.00', '4000.00');

        $rank = $this->progress($leader)->json('data.rank');
        $paidAs = app(CommissionQualification::class)->paidAs($leader->id, '2026-09');

        $this->assertSame('mo', $rank['current']);
        $this->assertSame('am', $rank['next']['rank']);
        $this->assertSame('team_leaders', $rank['next']['measure']);
        $this->assertSame('mo', $rank['next']['required_rank']);
        $this->assertSame(3, $rank['next']['current']);
        $this->assertSame(10, $rank['next']['target']);
        $this->assertSame(7, $rank['next']['left']);
        $this->assertSame('50000.00', $rank['next']['award']);
        $this->assertSame($paidAs['status'], $rank['keep']['status']);
        $this->assertSame('demoted', $rank['keep']['status']);
        $this->assertSame($paidAs['monthly_volume'], $rank['keep']['current']);
        $this->assertSame($paidAs['required_volume'], $rank['keep']['target']);
        $this->assertSame('3000.00', $rank['keep']['left']);
        $this->assertSame(['rank' => 'mo', 'amount' => '5000.00', 'status' => 'waiting'], $rank['award']);
    }

    public function test_rank_award_already_paid_shows_as_paid(): void
    {
        $leader = User::factory()->create();
        DealerRank::create(['user_id' => $leader->id, 'current_rank' => 'mo', 'achieved_at' => now()]);
        // ৳5 lakh funds ৳10,000; the buyer's own MO reward (paid at once) takes half.
        $this->purchase(User::factory()->create(), '500000.00', '1000000.00');
        $this->assertTrue(app(CommissionService::class)->claimOneTimeRankIncentive($leader->id)['success']);

        $rank = $this->progress($leader)->json('data.rank');

        $this->assertSame(['rank' => 'mo', 'amount' => '5000.00', 'status' => 'paid'], $rank['award']);
        $this->assertSame('grace_period', $rank['keep']['status']);
    }

    public function test_bike_reward_progress_matches_the_reward_check_and_shows_when_reached(): void
    {
        $owner = User::factory()->create(['role' => 'super_admin']);
        app(CommissionPolicy::class)->update($owner, ['car_bike_incentive' => ['parameters' => ['bike_target' => '30000.00', 'bike_leg_cap' => '20000.00']]]);
        $leader = User::factory()->create();
        ReferralTreePath::attachUser($leader->id, null);
        $member = User::factory()->create(['sponsor_id' => $leader->id]);
        ReferralTreePath::attachUser($member->id, $leader->id);
        $this->purchase($member, '25000.00', '50000.00');

        $bike = collect($this->progress($leader)->json('data.rewards'))->firstWhere('type', 'bike');
        $milestone = app(CommissionQualification::class)->milestone($leader->id, 'bike');

        $this->assertSame($milestone['qualifying_volume'], $bike['current']);
        $this->assertSame('20000.00', $bike['current']);
        $this->assertSame('30000.00', $bike['target']);
        $this->assertSame('10000.00', $bike['left']);
        $this->assertSame('25000.00', $bike['team_sales']);
        $this->assertSame('20000.00', $bike['line_cap']);
        $this->assertSame('in_progress', $bike['status']);

        $second = User::factory()->create(['sponsor_id' => $leader->id]);
        ReferralTreePath::attachUser($second->id, $leader->id);
        $this->purchase($second, '10000.00', '20000.00');
        $this->assertTrue(app(CommissionQualification::class)->milestone($leader->id, 'bike')['achieved']);
        $this->assertSame('reached', collect($this->progress($leader)->json('data.rewards'))->firstWhere('type', 'bike')['status']);
    }

    public function test_sub_dealer_sees_flat_salary_and_tour_fund_that_settlement_and_reserves_use(): void
    {
        $sub = User::factory()->create(['role' => 'sub_dealer']);
        ReferralTreePath::attachUser($sub->id, null);
        $this->purchase($sub, '12000.00', '20000.00');

        $data = $this->progress($sub, 'subdealer')->assertOk()->json('data');

        $this->assertSame('sub_dealer', $data['role']);
        $this->assertSame('sub_dealer_flat', $data['salary']['kind']);
        $this->assertSame('earning', $data['salary']['status']);
        $this->assertSame('3.00', $data['salary']['rate']);
        $this->assertSame('12000.00', $data['salary']['current']);
        $this->assertSame('360.00', $data['salary']['earned']);
        $this->assertNull($data['rank']);
        $this->assertSame(['bike', 'car'], array_column($data['rewards'], 'type'));
        $this->assertSame('saving', $data['tour']['status']);
        $this->assertSame('360.00', $data['tour']['saved_this_month']);
        $this->assertSame('360.00', $data['tour']['saved_total']);
        $this->assertSame('360.00', $data['tour']['available']);
        $this->assertSame('0.00', $data['tour']['used']);
        $this->assertSame(0, $data['tour']['requests_waiting']);

        $this->travelTo(now()->setDate(2026, 10, 1));
        $paid = collect(app(CommissionService::class)->settleMonthlySalaryPool('2026-09')['distributed'])->firstWhere('user_id', $sub->id);
        $this->assertSame($data['salary']['earned'], $paid['amount']);
    }

    public function test_sub_dealer_tour_fund_shows_money_already_used_for_a_tour(): void
    {
        $sub = User::factory()->create(['role' => 'sub_dealer']);
        $this->purchase($sub, '12000.00', '20000.00');
        $fund = DB::table('commission_allocations')->where('user_id', $sub->id)->where('category', 'sub_dealer_tour')->first();
        DB::table('commission_settlement_payouts')->insert(['allocation_id' => $fund->id, 'user_id' => $sub->id, 'kind' => 'award_1',
            'period_month' => '2026-09', 'amount' => '100.00', 'refunded_basis' => '0.00', 'created_at' => now(), 'updated_at' => now()]);

        $tour = $this->progress($sub, 'subdealer')->json('data.tour');

        $this->assertSame('260.00', $tour['available']);
        $this->assertSame('100.00', $tour['used']);
    }

    public function test_sub_dealer_with_salary_switched_off_on_the_dashboard_sees_no_salary_or_tour(): void
    {
        DashboardManagement::save(['subdealer_salary_enabled' => false]);
        $sub = User::factory()->create(['role' => 'sub_dealer']);

        $data = $this->progress($sub, 'subdealer')->assertOk()->json('data');

        $this->assertNull($data['salary']);
        $this->assertNull($data['tour']);
    }

    public function test_dealer_rewards_switched_off_on_the_dashboard_are_left_out(): void
    {
        DashboardManagement::save(['dealer_rewards_enabled' => false, 'dealer_ranks_enabled' => false]);
        $dealer = User::factory()->create();

        $data = $this->progress($dealer)->assertOk()->json('data');

        $this->assertSame([], $data['rewards']);
        $this->assertNull($data['rank']);
        $this->assertNotNull($data['salary']);
    }

    public function test_home_page_does_not_load_the_progress_until_the_pop_up_asks(): void
    {
        $dealer = User::factory()->create();

        $this->actingAs($dealer)->onPortal('dealer')->get('/')
            ->assertOk()
            ->assertInertia(fn ($page) => $page->missing('data.partner_home.progress'));
        $response = $this->onPortal('dealer')->getJson('/my-progress')->assertOk();
        $this->assertStringContainsString('no-store', (string) $response->headers->get('Cache-Control'));
    }

    private function progress(User $partner, string $portal = 'dealer'): TestResponse
    {
        return $this->actingAs($partner)->onPortal($portal)->getJson('/my-progress');
    }

    private function purchase(User $buyer, string $cash, string $mrp): Order
    {
        $product = Product::factory()->create(['mrp' => $mrp, 'dealer_price' => $cash,
            'sub_dealer_price' => $buyer->role === 'sub_dealer' ? $cash : null]);
        $order = app(OrderService::class)->create($buyer, ['items' => [['product_id' => $product->id, 'quantity' => 1]], 'idempotency_key' => (string) Str::uuid()]);
        $order->update(['paid' => $cash, 'status' => 'delivered']);
        app(CommissionService::class)->processOrder($order);

        return $order;
    }
}
