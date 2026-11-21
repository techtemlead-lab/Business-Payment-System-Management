<?php

namespace Tests\Feature;

use App\Domain\Accounts\PartnerDashboardHome;
use App\Domain\Commissions\CommissionPolicy;
use App\Domain\Commissions\CommissionQualification;
use App\Domain\Commissions\CommissionService;
use App\Domain\Orders\OrderService;
use App\Models\DealerRank;
use App\Models\Order;
use App\Models\Product;
use App\Models\RewardMilestoneProgress;
use App\Models\ReferralTreePath;
use App\Models\User;
use App\Support\DashboardManagement;
use Database\Seeders\CommissionRuleSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\TestCase;

/**
 * The home "Targets" card shows each partner only the rules that really apply to them,
 * and opening the home page never writes to the database.
 */
class PartnerHomeTargetsCardTest extends TestCase
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

    public function test_sub_dealer_card_shows_this_months_purchase_then_salary_then_bike_with_no_tour_fund_or_rank(): void
    {
        $sub = User::factory()->create(['role' => 'sub_dealer']);
        ReferralTreePath::attachUser($sub->id, null);
        $this->purchase($sub, '12000.00', '20000.00');

        app()->setLocale('en');
        $targets = collect(app(PartnerDashboardHome::class)->for($sub->fresh())['targets'])->keyBy('key');

        $this->assertSame(['monthly_purchase', 'sub_dealer_salary', 'bike_incentive'], $targets->keys()->all());
        $this->assertFalse($targets->has('tour_fund'), 'The tour fund stays hidden while PartnerProgress::ShowTourFund is off.');
        $this->assertSame('amount', $targets['monthly_purchase']['kind']);
        $this->assertSame('12000.00', $targets['monthly_purchase']['value']);
        $this->assertSame('amount', $targets['sub_dealer_salary']['kind']);
        $this->assertSame('360.00', $targets['sub_dealer_salary']['value']);
        $this->assertSame(__('portal.target_subdealer_salary_hint', ['rate' => '3']), $targets['sub_dealer_salary']['hint']);
        $this->assertStringContainsString('No minimum', $targets['sub_dealer_salary']['hint']);

        // The card and the "My progress" pop-up show the same money.
        $popup = $this->actingAs($sub)->onPortal('subdealer')->getJson('/my-progress')->json('data');
        $this->assertSame($popup['salary']['earned'], $targets['sub_dealer_salary']['value']);
        $this->assertSame($popup['salary']['current'], $targets['monthly_purchase']['value']);
    }
    public function test_sub_dealer_card_says_when_the_company_switched_the_salary_off(): void
    {
        $owner = User::factory()->create(['role' => 'super_admin']);
        app(CommissionPolicy::class)->update($owner, ['sub_dealer_salary' => ['is_active' => false]]);
        $sub = User::factory()->create(['role' => 'sub_dealer']);

        $salary = collect(app(PartnerDashboardHome::class)->for($sub)['targets'])->firstWhere('key', 'sub_dealer_salary');

        $this->assertSame(__('portal.target_switched_off_hint'), $salary['hint']);
    }

    public function test_sub_dealer_card_leaves_salary_out_when_the_salary_page_is_switched_off(): void
    {
        DashboardManagement::save(['subdealer_salary_enabled' => false]);
        $sub = User::factory()->create(['role' => 'sub_dealer']);

        $this->assertSame(['bike_incentive'], array_column(app(PartnerDashboardHome::class)->for($sub)['targets'], 'key'));
    }

    public function test_dealer_card_lists_monthly_progress_then_salary_then_rank_then_bike(): void
    {
        $dealer = User::factory()->create();
        ReferralTreePath::attachUser($dealer->id, null);

        app()->setLocale('en');
        $targets = collect(app(PartnerDashboardHome::class)->for($dealer)['targets'])->keyBy('key');

        $this->assertSame(['monthly_salary', 'salary_state', 'rank_reward', 'bike_incentive'], $targets->keys()->all());
        $this->assertSame(__('portal.target_monthly_progress'), $targets['monthly_salary']['label']);
        $this->assertSame('status', $targets['salary_state']['kind']);
        $this->assertSame('not_met', $targets['salary_state']['state']);
        $this->assertSame(__('portal.target_salary_state_not_met'), $targets['salary_state']['value']);

        $this->purchase($dealer, '12000.00', '20000.00');
        $met = collect(app(PartnerDashboardHome::class)->for($dealer->fresh())['targets'])->keyBy('key');

        $this->assertSame('met', $met['salary_state']['state']);
        $this->assertSame(__('portal.target_salary_state_met_hint'), $met['salary_state']['hint']);
    }
    public function test_the_card_shows_the_bike_first_and_the_car_only_once_the_bike_target_is_full(): void
    {
        $dealer = User::factory()->create();
        ReferralTreePath::attachUser($dealer->id, null);
        $keys = fn (): array => array_column(app(PartnerDashboardHome::class)->for($dealer->fresh())['targets'], 'key');

        $this->assertContains('bike_incentive', $keys());
        $this->assertNotContains('car_incentive', $keys());

        RewardMilestoneProgress::query()->forceCreate(['user_id' => $dealer->id, 'milestone_type' => 'bike', 'target_amount' => '5000000.00', 'leg_cap_amount' => '800000.00', 'qualifying_volume' => '4999999.00']);
        $this->assertContains('bike_incentive', $keys(), 'Bike progress remains, so no car row yet.');
        $this->assertNotContains('car_incentive', $keys());

        RewardMilestoneProgress::query()->where('user_id', $dealer->id)->where('milestone_type', 'bike')->update(['qualifying_volume' => '5000000.00']);
        RewardMilestoneProgress::query()->forceCreate(['user_id' => $dealer->id, 'milestone_type' => 'car', 'target_amount' => '250000000.00', 'leg_cap_amount' => '20000000.00', 'qualifying_volume' => '1000000.00']);
        $targets = collect(app(PartnerDashboardHome::class)->for($dealer->fresh())['targets'])->keyBy('key');

        $this->assertNotContains('bike_incentive', $keys());
        $this->assertSame('1000000.00', $targets['car_incentive']['current']);
        $this->assertSame('250000000.00', $targets['car_incentive']['target']);
    }
    public function test_opening_the_home_page_does_not_write_a_rank_row(): void
    {
        $dealer = User::factory()->create();
        $sub = User::factory()->create(['role' => 'sub_dealer']);

        $this->actingAs($dealer)->onPortal('dealer')->get('/')->assertOk();
        $this->actingAs($sub)->onPortal('subdealer')->get('/')->assertOk();

        $this->assertSame(0, DB::table('dealer_ranks')->whereIn('user_id', [$dealer->id, $sub->id])->count());
        $rank = collect(app(PartnerDashboardHome::class)->for($dealer)['targets'])->firstWhere('key', 'rank_reward');
        $this->assertSame('0.00', $rank['current']);
        $this->assertSame('250000.00', $rank['target']);
    }

    public function test_mo_dealer_card_counts_team_leaders_the_same_way_promotion_does(): void
    {
        $leader = User::factory()->create();
        ReferralTreePath::attachUser($leader->id, null);
        DealerRank::create(['user_id' => $leader->id, 'current_rank' => 'mo', 'achieved_at' => now()]);
        foreach (['mo', 'mo', 'am'] as $memberRank) {
            $member = User::factory()->create(['sponsor_id' => $leader->id]);
            ReferralTreePath::attachUser($member->id, $leader->id);
            DealerRank::create(['user_id' => $member->id, 'current_rank' => $memberRank, 'achieved_at' => now()]);
        }
        $suspended = User::factory()->create(['sponsor_id' => $leader->id, 'status' => 'suspended']);
        ReferralTreePath::attachUser($suspended->id, $leader->id);
        DealerRank::create(['user_id' => $suspended->id, 'current_rank' => 'mo', 'achieved_at' => now()]);

        $rank = collect(app(PartnerDashboardHome::class)->for($leader)['targets'])->firstWhere('key', 'rank_reward');
        $shared = CommissionQualification::leadersAtOrAbove(app(CommissionQualification::class)->teamRankCounts([$leader->id], 3)[$leader->id], 'mo');

        $this->assertSame(3, $shared);
        $this->assertSame((string) $shared, $rank['current']);
        $this->assertSame('10', $rank['target']);
        $this->assertSame('7', $rank['remaining']);
        $this->assertSame(__('portal.target_named_rank_reward', ['rank' => 'AM']), $rank['label']);
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
