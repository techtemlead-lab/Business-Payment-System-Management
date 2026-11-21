<?php

namespace Tests\Feature;

use App\Domain\Commissions\CommissionPosting;
use App\Domain\Commissions\CommissionQualification;
use App\Domain\Commissions\CommissionSettlement;
use App\Domain\Finance\ReserveService;
use App\Domain\Finance\TreasuryFunds;
use App\Domain\Orders\OrderService;
use App\Models\AreaDealerAssignment;
use App\Models\CommissionAllocation;
use App\Models\DealerRank;
use App\Models\Order;
use App\Models\PlaceCode;
use App\Models\Product;
use App\Models\ReferralTreePath;
use App\Models\User;
use App\Support\Money;
use Database\Seeders\CommissionRuleSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Tests\TestCase;

/**
 * The owner's rank and monthly salary rules of 2026-11-21: ranks for dealers and sub-dealers, the team counted one
 * direct-referral line at a time, one monthly target per dealer by kind, sub-dealer salary tiers, rank funds that go to
 * company profit when nobody qualifies, and area income decided once a month.
 */
class RankAndMonthlyTargetRulesTest extends TestCase
{
    use RefreshDatabase;

    private PlaceCode $division;

    private PlaceCode $district;

    private PlaceCode $upazila;

    private PlaceCode $union;

    protected function setUp(): void
    {
        parent::setUp();
        $this->withoutVite();
        $this->enableFinancialPosting();
        $this->seed(CommissionRuleSeeder::class);
        $this->travelTo(now()->setDate(2026, 12, 10)->setTime(11, 0));
        $this->division = PlaceCode::factory()->division()->create(['name' => 'Rangpur']);
        $this->district = PlaceCode::factory()->district($this->division)->create(['name' => 'Dinajpur']);
        $this->upazila = PlaceCode::factory()->upazila($this->district)->create(['name' => 'Nawabganj']);
        $this->union = PlaceCode::factory()->union($this->upazila)->create(['name' => 'Balia']);
    }

    protected function tearDown(): void
    {
        $this->travelBack();
        parent::tearDown();
    }

    // ---- The team, counted one direct-referral line at a time ----

    public function test_a_qualifying_person_deeper_in_a_line_counts_for_that_line(): void
    {
        // I referred A; A referred B; B referred C. A is not MO, C is: the line counts once.
        $leader = $this->member(null);
        $a = $this->member($leader);
        $b = $this->member($a);
        $this->member($b, 'mo');

        $this->assertSame(1, $this->linesAtOrAbove($leader, 'mo'));
    }

    public function test_two_qualifying_people_in_one_line_still_count_once(): void
    {
        $leader = $this->member(null);
        $a = $this->member($leader, 'mo');
        $b = $this->member($a, 'mo');
        $this->member($b, 'mo');

        $this->assertSame(1, $this->linesAtOrAbove($leader, 'mo'));
    }

    public function test_a_line_counts_only_within_three_levels_of_the_leader(): void
    {
        $leader = $this->member(null);
        $a = $this->member($leader);
        $b = $this->member($a);
        $c = $this->member($b);
        $this->member($c, 'mo');

        $this->assertSame(0, $this->linesAtOrAbove($leader, 'mo'), 'The fourth level is outside the team count.');
    }

    public function test_inactive_people_do_not_count_and_sub_dealers_do(): void
    {
        $leader = $this->member(null);
        $this->member($leader, 'mo', 'dealer', 'suspended');
        $this->member($leader, 'mo', 'sub_dealer');

        $this->assertSame(1, $this->linesAtOrAbove($leader, 'mo'));
    }

    public function test_a_line_counts_at_its_best_rank(): void
    {
        $leader = $this->member(null);
        $a = $this->member($leader, 'mo');
        $this->member($a, 'am');

        $this->assertSame(1, $this->linesAtOrAbove($leader, 'am'));
        $this->assertSame(1, $this->linesAtOrAbove($leader, 'mo'), 'An AM line is also an MO-or-above line, but still one line.');
    }

    public function test_ten_lines_make_am_and_nine_lines_with_extra_people_do_not(): void
    {
        $leader = $this->member(null, 'mo');
        for ($line = 1; $line <= 9; $line++) {
            $head = $this->member($leader);
            $this->member($this->member($head), 'mo');
            $this->member($head, 'mo');
        }
        $this->assertSame(9, $this->linesAtOrAbove($leader, 'mo'));
        $this->assertSame([], app(CommissionQualification::class)->recheckRanks(false));

        $this->member($leader, 'mo');
        $planned = app(CommissionQualification::class)->recheckRanks(false);
        $this->assertSame([['user_id' => $leader->id, 'role' => 'dealer', 'from' => 'mo', 'to' => 'am', 'steps' => ['am']]], $planned);
        $this->assertSame('mo', DealerRank::where('user_id', $leader->id)->value('current_rank'), 'A dry run writes nothing.');

        $this->artisan('madina:recheck-ranks', ['--apply' => true])->assertSuccessful();
        $this->assertSame('am', DealerRank::where('user_id', $leader->id)->value('current_rank'));
        $this->assertSame([], app(CommissionQualification::class)->recheckRanks(true), 'Nothing is promoted twice.');
    }

    public function test_a_purchase_in_the_team_promotes_the_leader_by_lines(): void
    {
        $leader = $this->member(null, 'mo');
        $heads = [];
        for ($line = 1; $line <= 10; $line++) {
            $heads[] = $this->member($leader);
        }
        foreach (array_slice($heads, 0, 9) as $head) {
            $this->member($head, 'mo');
        }
        $this->purchase($heads[9], '1000.00');
        $this->assertSame('mo', DealerRank::where('user_id', $leader->id)->value('current_rank'));

        DealerRank::where('user_id', $heads[9]->id)->update(['current_rank' => 'mo', 'achieved_at' => now()]);
        $this->purchase($heads[9], '1000.00');

        $this->assertSame('am', DealerRank::where('user_id', $leader->id)->value('current_rank'));
    }

    // ---- MO for dealers and sub-dealers ----

    public function test_a_sub_dealer_becomes_mo_at_five_lakh_of_own_purchases(): void
    {
        $sub = $this->partner('sub_dealer');
        $this->purchase($sub, '499999.00');
        $this->assertSame('dealer', DealerRank::where('user_id', $sub->id)->value('current_rank'));

        $this->purchase($sub, '1.00');

        $this->assertSame('mo', DealerRank::where('user_id', $sub->id)->value('current_rank'));
    }

    public function test_a_dealer_is_not_mo_at_the_old_two_and_a_half_lakh(): void
    {
        $dealer = $this->partner('dealer');
        $this->purchase($dealer, '250000.00');

        $this->assertSame('dealer', DealerRank::where('user_id', $dealer->id)->value('current_rank'));
    }

    // ---- Sub-dealer salary tiers ----

    public function test_a_sub_dealer_is_paid_the_tier_his_month_reached_and_the_rest_goes_to_company_profit(): void
    {
        $expected = ['9999.00' => '0.00', '10000.00' => '100.00', '20000.00' => '400.00', '39999.00' => '799.98', '40000.00' => '1200.00'];
        $subs = [];
        foreach (array_keys($expected) as $cash) {
            $subs[$cash] = $this->partner('sub_dealer');
            $this->purchase($subs[$cash], $cash);
        }

        $this->closeMonth('salary');

        foreach ($expected as $cash => $paid) {
            $sub = $subs[$cash];
            $this->assertSame($paid, Money::decimal($this->paidTo($sub, 'salary')), "Own purchase {$cash}");
            $fund = CommissionAllocation::where('user_id', $sub->id)->where('category', 'sub_dealer_salary')->sole();
            $this->assertSame('settled', $fund->status);
            $toCompany = CommissionAllocation::where('category', 'company_profit_fund')->where('metadata->source_allocation_id', $fund->id)->value('amount');
            $this->assertSame(Money::cents((string) $fund->amount) - Money::cents($paid), Money::cents((string) ($toCompany ?? '0')), 'The rest of the 3% goes to company profit.');
        }
        $this->assertSame(4, CommissionAllocation::where('metadata->reason_key', 'retained_reason_sub_dealer_salary_tier')->count());
    }

    public function test_an_order_captured_before_the_tiers_keeps_paying_the_whole_salary(): void
    {
        $sub = $this->partner('sub_dealer');
        $order = $this->purchase($sub, '5000.00', function (Order $order): void {
            $snapshot = $order->commission_policy_snapshot;
            $snapshot['rules']['sub_dealer_salary']['parameters'] = [];
            $snapshot['rules']['dealer_monthly_salary']['parameters'] = ['min_monthly_volume' => '10000.00'];
            $order->update(['commission_policy_snapshot' => $snapshot]);
        });

        $this->closeMonth('salary');

        $this->assertSame('150.00', Money::decimal($this->paidTo($sub, 'salary')), 'Old rules: the whole 3% is his, with no minimum.');
        $this->assertNotNull($order->fresh());
    }

    // ---- One dealer target by kind gates the salary ----

    public function test_each_dealer_needs_the_target_of_his_kind_for_the_salary(): void
    {
        $union = $this->partner('dealer', $this->union);
        $upazila = $this->partner('dealer', $this->upazila);
        $district = $this->partner('dealer', $this->district);
        $division = $this->partner('dealer', $this->division);
        $this->purchase($union, '30000.00');
        $this->purchase($upazila, '49999.00');
        $this->purchase($district, '100000.00');
        $this->purchase($division, '99999.00');

        $this->closeMonth('salary');

        $this->assertGreaterThan(0, $this->paidTo($union, 'salary'));
        $this->assertSame(0, $this->paidTo($upazila, 'salary'));
        $this->assertGreaterThan(0, $this->paidTo($district, 'salary'));
        $this->assertSame(0, $this->paidTo($division, 'salary'));
        $this->assertSame($this->paidTo($union, 'salary'), $this->paidTo($district, 'salary'), 'Shared equally.');
    }

    public function test_the_salary_is_one_fund_for_all_of_bangladesh_with_the_odd_paisa_in_the_commission_extra_fund(): void
    {
        $dhaka = PlaceCode::factory()->division()->create(['name' => 'Dhaka']);
        $here = $this->partner('dealer', $this->union);
        $there = $this->partner('dealer', $dhaka);
        $alsoThere = $this->partner('dealer', $dhaka);
        $this->purchase($here, '30000.00');
        $this->purchase($there, '100000.00');
        $this->purchase($alsoThere, '100000.00');
        $this->purchase($this->partner('dealer', $this->union), '1000.01');
        $before = app(TreasuryFunds::class)->card('benefits', true);

        $result = $this->closeMonth('salary');

        $funds = CommissionAllocation::where('category', 'dealer_monthly_salary_pool')->get();
        $pool = (int) $funds->sum(fn ($fund): int => Money::cents((string) $fund->amount));
        $paid = $this->paidTo($here, 'salary');
        $this->assertGreaterThan(0, $paid, 'A Rangpur dealer shares the money of Dhaka purchases too.');
        $this->assertSame($paid, $this->paidTo($there, 'salary'), 'Exactly equal to the paisa.');
        $this->assertSame($paid, $this->paidTo($alsoThere, 'salary'));
        $leftover = (int) CommissionAllocation::where('category', 'company_commission_benefits_fund')->where('metadata->source', 'split_remainder')->get()->sum(fn ($row): int => Money::cents((string) $row->amount));
        $this->assertGreaterThan(0, $leftover, 'The ৳20.0002 fund of the 1000.01 order does not divide by three.');
        $this->assertSame($pool, 3 * $paid + $leftover, 'Nothing goes to company profit while someone qualified, and every paisa is placed.');
        $this->assertSame(Money::decimal($leftover), $result['total_to_commission_extra_fund']);
        $row = CommissionAllocation::where('metadata->source', 'split_remainder')->first();
        $this->assertSame('owner_commission_benefits_fund', $row->metadata['retained_by']);
        $this->assertSame('money_core.reason_split_remainder', $row->metadata['reason_key']);
        // The leftover is in the Commission extra fund's balance and its money-in list, which still add up.
        $card = app(TreasuryFunds::class)->card('benefits', true);
        $this->assertSame(Money::cents($before['money_in']) + $leftover, Money::cents($card['money_in']));
        $this->assertSame($card['money_in'], app(TreasuryFunds::class)->moneyIn(Request::create('/x', 'GET', ['period' => '']), 'benefits')['totals']['sums']['amount']);
        // The salary fund's money-out list shows the leftover as its own line, never as "moved to profit", and still adds up.
        app()->setLocale('en');
        $out = app(TreasuryFunds::class)->moneyOut(Request::create('/x', 'GET', ['period' => '']), 'salary_pool');
        $lines = collect($out['rows']->items());
        $this->assertSame(Money::decimal($leftover), Money::decimal(Money::cents((string) $lines->firstWhere('what', __('treasury.out_split_remainder'))['amount'])));
        $this->assertNull($lines->firstWhere('what', __('treasury.out_moved_to_profit')), 'Someone qualified, so nothing moved to profit.');
        $this->assertSame(Money::cents((string) app(TreasuryFunds::class)->card('salary_pool', true)['money_out']), Money::cents((string) $out['totals']['sums']['amount']));
    }

    public function test_the_owners_own_target_for_one_dealer_replaces_his_kind_target_for_that_month(): void
    {
        $excepted = $this->partner('dealer', $this->union, 'mo');
        $other = $this->partner('dealer', $this->union, 'mo');
        DB::table('partner_monthly_targets')->insert(['user_id' => $excepted->id, 'month' => '2026-12', 'kind' => 'salary', 'amount' => '20000.00',
            'set_by' => User::factory()->create(['role' => 'super_admin'])->id, 'created_at' => now(), 'updated_at' => now()]);
        $this->purchase($excepted, '20000.00');
        $this->purchase($other, '20000.00');

        $this->closeMonth('salary');
        app(CommissionSettlement::class)->settle('2026-12', 'royalty');

        $this->assertGreaterThan(0, $this->paidTo($excepted, 'salary'), 'His own target of 20,000 is met.');
        $this->assertGreaterThan(0, $this->paidTo($excepted, 'royalty'), 'The same exception unlocks his royalty.');
        $this->assertSame(0, $this->paidTo($other, 'salary'), 'The union target of 30,000 is not met.');
        $this->assertSame(0, $this->paidTo($other, 'royalty'));
    }

    public function test_a_salary_fund_nobody_in_bangladesh_qualified_for_goes_to_company_profit(): void
    {
        $this->purchase($this->partner('dealer', $this->union), '1000.00');

        $this->closeMonth('salary');

        $fund = CommissionAllocation::where('category', 'dealer_monthly_salary_pool')->sole();
        $profit = CommissionAllocation::where('category', 'company_profit_fund')->where('metadata->source_allocation_id', $fund->id)->sole();
        $this->assertSame((string) $fund->amount, (string) $profit->amount);
        $this->assertSame('retained_reason_unclaimed_national_salary', $profit->metadata['reason_key']);
        $this->assertSame(0, CommissionAllocation::where('metadata->source', 'split_remainder')->count());
    }

    // ---- Rank royalty: four 1% funds, monthly target, unclaimed to company profit ----

    public function test_each_rank_fund_goes_to_its_qualified_holders_and_an_empty_one_to_company_profit(): void
    {
        $dealerMo = $this->partner('dealer', $this->union, 'mo');
        $subMo = $this->partner('sub_dealer', null, 'mo');
        $subMoShort = $this->partner('sub_dealer', null, 'mo');
        $this->purchase($dealerMo, '30000.00');
        $this->purchase($subMo, '30000.00');
        $this->purchase($subMoShort, '29999.00');
        $pool = (int) CommissionAllocation::where('category', 'rank_royalty_pool')->get()->sum(fn ($fund): int => Money::cents((string) $fund->amount));

        $result = $this->closeMonth('royalty');

        $this->assertSame(2, $result['distributions']['mo']['qualifiers']);
        $this->assertSame(0, $this->paidTo($subMoShort, 'royalty'), 'Below his à§³30,000 target.');
        $this->assertSame($this->paidTo($dealerMo, 'royalty'), $this->paidTo($subMo, 'royalty'), 'Equal to the paisa.');
        $moBucket = $this->paidTo($dealerMo, 'royalty') + $this->paidTo($subMo, 'royalty');
        $this->assertEqualsWithDelta(intdiv($pool, 4), $moBucket, 3, 'The MO fund is one of four equal funds.');
        $sum = fn (string $category): int => (int) CommissionAllocation::where('category', $category)->where('metadata->settlement_kind', 'royalty')->get()->sum(fn ($row): int => Money::cents((string) $row->amount));
        $toCompany = $sum('company_profit_fund');
        $leftover = $sum('company_commission_benefits_fund');
        $this->assertEqualsWithDelta(3 * intdiv($pool, 4), $toCompany, 3, 'AM, SM and ME funds had no holder: company profit at month end.');
        $this->assertSame($pool, $moBucket + $toCompany + $leftover, 'Every paisa is placed: paid, company profit or Commission extra fund.');
    }

    public function test_reaching_a_rank_pays_no_royalty_before_the_month_closes_and_a_second_close_pays_nothing_more(): void
    {
        $dealerMo = $this->partner('dealer', $this->union, 'mo');
        $this->purchase($dealerMo, '30000.00');
        $this->assertSame(0, $this->paidTo($dealerMo, 'royalty'));
        $this->assertSame(0, (int) DB::table('commission_settlement_payouts')->count());

        $this->closeMonth('royalty');
        $paid = $this->paidTo($dealerMo, 'royalty');
        $wallet = DB::table('wallets')->where('user_id', $dealerMo->id)->value('available');
        $profitRows = CommissionAllocation::where('category', 'company_profit_fund')->where('metadata->settlement_kind', 'royalty')->count();
        $second = app(CommissionSettlement::class)->settle('2026-12', 'royalty');
        $this->assertSame($wallet, DB::table('wallets')->where('user_id', $dealerMo->id)->value('available'));
        $this->artisan('finance:auto-approve')->assertSuccessful();

        $this->assertSame('empty_pool', $second['status']);
        $this->assertGreaterThan(0, $paid);
        $this->assertSame($paid, $this->paidTo($dealerMo, 'royalty'));
        $this->assertSame($profitRows, CommissionAllocation::where('category', 'company_profit_fund')->where('metadata->settlement_kind', 'royalty')->count());
    }

    // ---- Area income decided once a month ----

    public function test_area_income_waits_for_month_end_even_when_the_target_is_met(): void
    {
        config(['madina.auto_release_quota_holds' => true]);
        $dealer = $this->partner('dealer', $this->division);
        AreaDealerAssignment::create(['user_id' => $dealer->id, 'level' => 'division', 'place_code_id' => $this->division->id,
            'division' => 'Rangpur', 'zone_name' => 'Rangpur', 'monthly_quota' => '100.00', 'is_active' => true]);
        $this->purchase($dealer, '100000.00');
        $order = $this->purchase($this->partner('dealer', $this->division), '1000.00');
        $share = CommissionAllocation::where('order_id', $order->id)->where('category', 'area_division')->sole();

        $this->assertSame('held', $share->status);
        $this->assertSame('100000.00', $share->metadata['monthly_quota'], 'The dealer\'s one target, not the seat\'s old figure.');
        $this->assertTrue($share->metadata['monthly_close']);
        $this->artisan('finance:release-quota-holds')->assertSuccessful();
        $this->assertSame('held', $share->fresh()->status, 'Not paid before the month ends.');
        try {
            app(ReserveService::class)->release(User::factory()->staff('finance')->create(), $share->id, 'Target reached');
            $this->fail('A share of an open month cannot be released by hand.');
        } catch (ValidationException $exception) {
            $this->assertSame(__('portal.err_hold_month_open'), $exception->errors()['hold'][0]);
        }

        $this->travelTo(now()->setDate(2027, 1, 1)->setTime(1, 0));
        $this->artisan('finance:release-quota-holds')->assertSuccessful();
        $this->assertSame('available', $share->fresh()->status);
        $wallet = DB::table('wallets')->where('user_id', $dealer->id)->value('available');
        $this->artisan('finance:release-quota-holds')->assertSuccessful();
        $this->assertSame($wallet, DB::table('wallets')->where('user_id', $dealer->id)->value('available'), 'Paid once.');
        $this->artisan('finance:auto-approve')->assertSuccessful();
        $this->assertSame('available', $share->fresh()->status);
        $this->assertSame(0, CommissionAllocation::where('metadata->source_allocation_id', $share->id)->count(), 'A met target sends nothing to company profit.');
        $this->assertSame(1, DB::table('journal_entries')->where('event_key', 'commission.hold_release.'.$share->id)->count());
    }

    public function test_area_income_of_a_month_without_the_target_goes_to_company_profit(): void
    {
        config(['madina.auto_release_quota_holds' => true]);
        $dealer = $this->partner('dealer', $this->union);
        AreaDealerAssignment::create(['user_id' => $dealer->id, 'level' => 'union', 'place_code_id' => $this->union->id,
            'division' => 'Rangpur', 'zone_name' => 'Balia', 'monthly_quota' => '100.00', 'is_active' => true]);
        $this->purchase($dealer, '29999.00');
        $share = CommissionAllocation::where('user_id', $dealer->id)->where('category', 'area_union')->first();
        $this->assertSame('30000.00', $share->metadata['monthly_quota']);

        $this->travelTo(now()->setDate(2027, 1, 1)->setTime(1, 0));
        $this->artisan('finance:release-quota-holds')->assertSuccessful();
        $this->artisan('finance:auto-approve')->assertSuccessful();
        $this->artisan('finance:auto-approve')->assertSuccessful();

        $this->assertSame('forfeited', $share->fresh()->status);
        $this->assertSame(1, CommissionAllocation::where('metadata->source_allocation_id', $share->id)->count());
    }

    // ---- helpers ----

    private function linesAtOrAbove(User $leader, string $rank): int
    {
        return CommissionQualification::leadersAtOrAbove(app(CommissionQualification::class)->teamRankCounts([$leader->id], 3)[$leader->id], $rank);
    }

    private function member(?User $sponsor, string $rank = 'dealer', string $role = 'dealer', string $status = 'active'): User
    {
        $user = User::factory()->create(['role' => $role, 'status' => $status, 'sponsor_id' => $sponsor?->id]);
        ReferralTreePath::attachUser($user->id, $sponsor?->id);
        DealerRank::create(['user_id' => $user->id, 'current_rank' => $rank, 'achieved_at' => now()->subMonth()]);

        return $user;
    }

    private function partner(string $role, ?PlaceCode $place = null, string $rank = 'dealer'): User
    {
        $factory = User::factory();
        $user = ($place ? $factory->inPlace($place) : $factory->inPlace($this->division))->create(['role' => $role]);
        ReferralTreePath::attachUser($user->id, null);
        if ($rank !== 'dealer') {
            DealerRank::create(['user_id' => $user->id, 'current_rank' => $rank, 'achieved_at' => now()->subMonth()]);
        }

        return $user;
    }

    private function purchase(User $buyer, string $cash, ?callable $beforePosting = null): Order
    {
        $mrp = bcmul($cash, $buyer->role === 'sub_dealer' ? '1.5' : '2', 2);
        $product = Product::factory()->create(['mrp' => $mrp, 'dealer_price' => $cash, 'sub_dealer_price' => $buyer->role === 'sub_dealer' ? $cash : null]);
        $order = app(OrderService::class)->create($buyer, ['items' => [['product_id' => $product->id, 'quantity' => 1]], 'idempotency_key' => (string) Str::uuid()]);
        $order->update(['paid' => $cash, 'status' => 'delivered']);
        if ($beforePosting !== null) {
            $beforePosting($order->fresh());
        }
        app(CommissionPosting::class)->process($order->fresh());

        return $order;
    }

    /** @return array<string, mixed> */
    private function closeMonth(string $kind): array
    {
        $this->travelTo(now()->setDate(2027, 1, 2)->setTime(1, 0));

        return app(CommissionSettlement::class)->settle('2026-12', $kind);
    }

    private function paidTo(User $partner, string $kind): int
    {
        return Money::cents((string) DB::table('commission_settlement_payouts')->where('user_id', $partner->id)->where('kind', $kind)->sum('amount'));
    }
}
