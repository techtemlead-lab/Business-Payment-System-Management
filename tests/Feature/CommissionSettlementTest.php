<?php

namespace Tests\Feature;

use App\Domain\Commissions\CommissionPolicy;
use App\Domain\Commissions\CommissionQualification;
use App\Domain\Commissions\CommissionService;
use App\Domain\Commissions\CommissionSettlement;
use App\Domain\Commissions\CommissionWallet;
use App\Domain\Commissions\RankRoyaltyFunds;
use App\Domain\Finance\FinancialReadiness;
use App\Domain\Orders\OrderService;
use App\Http\Middleware\HandleInertiaRequests;
use App\Models\DealerRank;
use App\Models\Order;
use App\Models\PlaceCode;
use App\Models\Product;
use App\Models\User;
use App\Support\Money;
use Carbon\CarbonImmutable;
use Database\Seeders\CommissionRuleSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Tests\TestCase;

class CommissionSettlementTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->withoutVite();
        $this->enableFinancialPosting();
        $this->seed(CommissionRuleSeeder::class);
        $this->travelTo(now()->setDate(2026, 9, 15)->startOfDay());
    }

    protected function tearDown(): void
    {
        $this->travelBack();
        parent::tearDown();
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

    /**
     * A union-level dealer in a fresh Rangpur-division tree (union target ৳30,000 a month).
     */
    private function unionDealer(?PlaceCode $upazila = null, string $name = 'Balia'): User
    {
        $upazila ??= PlaceCode::factory()->upazila(PlaceCode::factory()->district(PlaceCode::factory()->division()->create(['name' => 'Rangpur']))->create(['name' => 'Rangpur']))->create(['name' => 'Pirganj']);

        return User::factory()->inPlace(PlaceCode::factory()->union($upazila)->create(['name' => $name]))->create();
    }

    /**
     * Two union dealers in Rangpur division (different unions) and one in Dhaka division.
     *
     * @return array{0: User, 1: User, 2: User}
     */
    private function rangpurAndDhakaDealers(): array
    {
        $rangpur = PlaceCode::factory()->division()->create(['name' => 'Rangpur']);
        $dhaka = PlaceCode::factory()->division()->create(['name' => 'Dhaka']);
        $rangpurUpazila = PlaceCode::factory()->upazila(PlaceCode::factory()->district($rangpur)->create(['name' => 'Rangpur']))->create(['name' => 'Pirganj']);
        $dhakaUpazila = PlaceCode::factory()->upazila(PlaceCode::factory()->district($dhaka)->create(['name' => 'Gazipur']))->create(['name' => 'Kaliganj']);

        return [
            $this->unionDealer($rangpurUpazila, 'Balia'),
            $this->unionDealer($rangpurUpazila, 'Chandan'),
            $this->unionDealer($dhakaUpazila, 'Tumulia'),
        ];
    }

    /** The salary a partner was paid for the month, in paisa. */
    private function salaryPaid(User $user): int
    {
        return (int) DB::table('commission_settlement_payouts')->where('user_id', $user->id)->where('kind', 'salary')
            ->get()->sum(fn ($row) => Money::cents((string) $row->amount));
    }

    /** One ৳10,000 no-area target for dealers with no place, so a small purchase still qualifies them for royalty tests. */
    private function lowerTheNoAreaTarget(string $amount = '10000.00'): void
    {
        app(CommissionPolicy::class)->update(User::factory()->create(['role' => 'super_admin']),
            ['dealer_monthly_salary' => ['parameters' => ['min_monthly_volume' => $amount]]]);
    }

    public function test_salary_pool_is_one_nationwide_pool_shared_by_qualified_dealers_only(): void
    {
        [$rangpurA, $rangpurB, $dhaka] = $this->rangpurAndDhakaDealers();
        $this->purchase($rangpurA, '40000.00', '80000.00');
        $this->purchase($rangpurB, '40000.00', '80000.00');
        $this->purchase($dhaka, '10000.00', '20000.00');
        $this->travelTo(now()->setDate(2026, 10, 1));

        $result = app(CommissionService::class)->settleMonthlySalaryPool('2026-09');

        // One fund for the whole country: 2% of every order (৳800 + ৳800 + ৳200) is split between the two dealers who
        // met their ৳30,000 union target. The Dhaka dealer bought only ৳10,000, so he is paid nothing.
        $this->assertSame(90000, $this->salaryPaid($rangpurA));
        $this->assertSame(90000, $this->salaryPaid($rangpurB));
        $this->assertSame(0, $this->salaryPaid($dhaka));
        $this->assertSame('1800.00', $result['total_distributed']);
        $this->assertSame('0.00', $result['total_to_company_profit']);
        $this->assertSame('0.00', $result['total_to_commission_extra_fund']);
        $this->assertSame(0, DB::table('commission_allocations')->where('category', 'company_profit_fund')
            ->where('metadata->settlement_kind', 'salary')->count());
        $this->assertSame(0, DB::table('commission_allocations')->where('category', 'company_commission_benefits_fund')
            ->where('metadata->settlement_kind', 'salary')->count());
        $this->assertCount(1, $result['divisions']);
        $this->assertSame(__('portal.report_area_nationwide'), $result['divisions'][0]['division']);
        $this->assertSame(2, $result['divisions'][0]['qualifiers']);
    }

    public function test_settlement_credits_each_partner_once_however_many_source_orders(): void
    {
        $upazila = PlaceCode::factory()->upazila(PlaceCode::factory()->district(PlaceCode::factory()->division()->create(['name' => 'Rangpur']))->create())->create();
        $dealers = collect(['Balia', 'Chandan', 'Tumulia'])->map(fn (string $union): User => $this->unionDealer($upazila, $union));
        foreach ($dealers as $dealer) {
            for ($order = 0; $order < 4; $order++) {
                $this->purchase($dealer, '15000.00', '30000.00');
            }
        }
        $walletsBefore = DB::table('wallets')->whereIn('user_id', $dealers->pluck('id'))->pluck('available', 'user_id');
        $this->travelTo(now()->setDate(2026, 10, 1));

        $result = app(CommissionService::class)->settleMonthlySalaryPool('2026-09');

        // 12 orders x 2% = ৳3,600, shared by the three dealers who each reached the ৳30,000 union target.
        $this->assertSame('3600.00', $result['total_distributed']);
        foreach ($dealers as $dealer) {
            $this->assertSame(120000, $this->salaryPaid($dealer));
            // Each source order keeps its own payout row, so a refund can recover from that order's pool.
            $this->assertSame(12, DB::table('commission_settlement_payouts')->where('user_id', $dealer->id)->where('kind', 'salary')->count());
            $this->assertSame(
                Money::cents((string) $walletsBefore[$dealer->id]) + 120000,
                Money::cents((string) DB::table('wallets')->where('user_id', $dealer->id)->value('available')),
            );
            $this->assertSame(1, DB::table('account_notices')->where('user_id', $dealer->id)->where('title', __('portal.notice_settle_title'))->count());
        }
        $entries = DB::table('journal_entries')->where('event_key', 'like', 'commissions.settlement.salary.2026-09.%')->pluck('id');
        $this->assertCount(1, $entries);
        $lines = DB::table('journal_lines')->where('entry_id', $entries->first())->get();
        $this->assertSame(360000, $lines->sum(fn ($line) => Money::cents((string) $line->debit)));
        $this->assertSame(360000, $lines->sum(fn ($line) => Money::cents((string) $line->credit)));
        // The books still balance: journals, wallets and allocations agree (this helper marks orders paid without receipts).
        $failed = array_keys(array_filter(app(FinancialReadiness::class)->inspect()['checks'], fn (bool $passed): bool => ! $passed));
        $this->assertSame([], array_values(array_diff($failed, ['order_receipts'])));
    }

    public function test_salary_preview_shows_exactly_what_settlement_then_pays(): void
    {
        [$rangpurA, $rangpurB, $dhaka] = $this->rangpurAndDhakaDealers();
        $this->purchase($rangpurA, '40000.00', '80000.00');
        $this->purchase($rangpurB, '40000.00', '80000.00');
        $this->purchase($dhaka, '10000.00', '20000.00');
        $this->travelTo(now()->setDate(2026, 10, 1));

        $plan = app(CommissionSettlement::class)->salaryPlan('2026-09');
        $this->assertCount(1, $plan['divisions']);
        $nationwide = $plan['divisions'][0];
        $this->assertTrue($nationwide['nationwide']);
        $this->assertNull($nationwide['division_id']);
        $this->assertSame(__('portal.report_area_nationwide'), $nationwide['division']);
        $this->assertSame(2, $nationwide['qualifiers']);
        $this->assertSame('900.00', $nationwide['per_head']);
        $this->assertSame('0.00', $nationwide['to_company_profit']);
        $this->assertSame('0.00', $plan['to_company_profit']);
        $planned = collect($plan['dealers'])->pluck('share', 'user_id')->all();

        $result = app(CommissionService::class)->settleMonthlySalaryPool('2026-09');

        $paid = collect($result['distributed'])
            ->pluck('amount', 'user_id')->all();
        $this->assertNotEmpty($paid);
        ksort($planned);
        ksort($paid);
        $this->assertSame($planned, $paid);
        $this->assertSame($plan['to_company_profit'], $result['total_to_company_profit']);
    }

    public function test_leftover_paisa_go_to_the_commission_extra_fund_not_to_any_dealer(): void
    {
        $upazila = PlaceCode::factory()->upazila(PlaceCode::factory()->district(PlaceCode::factory()->division()->create(['name' => 'Rangpur']))->create())->create();
        $dealers = collect(['Balia', 'Chandan', 'Tumulia'])->map(fn (string $union): User => $this->unionDealer($upazila, $union));
        foreach ($dealers as $dealer) {
            $this->purchase($dealer, '40000.00', '80000.00');
        }
        $this->travelTo(now()->setDate(2026, 10, 1));

        // Each ৳800 fund split three ways is ৳266.66 each with 2 paisa over: everybody gets the same, the paisa are kept apart.
        $plan = app(CommissionSettlement::class)->salaryPlan('2026-09');
        $result = app(CommissionService::class)->settleMonthlySalaryPool('2026-09');

        $paid = collect($result['distributed'])->pluck('amount', 'user_id')->all();
        foreach ($dealers as $dealer) {
            $this->assertSame('799.98', $paid[$dealer->id]);
        }
        $this->assertSame('2399.94', Money::decimal(array_sum(array_map(fn (string $amount): int => Money::cents($amount), $paid))));
        $this->assertSame('0.00', $result['total_to_company_profit']);
        $this->assertSame('0.06', $result['total_to_commission_extra_fund']);
        $this->assertSame('0.06', $plan['to_commission_extra_fund']);
        $leftovers = DB::table('commission_allocations')->where('category', 'company_commission_benefits_fund')->where('metadata->source', 'split_remainder')->get();
        $this->assertCount(3, $leftovers);
        $this->assertSame(6, $leftovers->sum(fn ($row) => Money::cents((string) $row->amount)));
        $this->assertNull($leftovers->first()->user_id);
        $this->assertSame('reserved', $leftovers->first()->status);
        $planned = collect($plan['dealers'])->pluck('share', 'user_id')->all();
        ksort($paid);
        ksort($planned);
        $this->assertSame($planned, $paid);
    }

    public function test_treasury_counts_settled_pool_leftovers_once(): void
    {
        $owner = User::factory()->staff()->create();
        $dhaka = User::factory()->inPlace(PlaceCode::factory()->division()->create(['name' => 'Dhaka']))->create();
        $this->purchase($dhaka, '5000.00', '10000.00');
        $this->travelTo(now()->setDate(2026, 10, 1));
        $version = (string) (new HandleInertiaRequests)->version(Request::create('/'));
        $treasury = fn () => collect($this->actingAs($owner)->onPortal('sadmin')
            ->withHeaders(['X-Inertia' => 'true', 'X-Inertia-Version' => $version])
            ->get('/treasury/funds')->assertOk()->json('props.cards'))->keyBy('key');

        $before = $treasury();
        $result = app(CommissionService::class)->settleMonthlySalaryPool('2026-09');
        $after = $treasury();

        $this->assertSame('100.00', $result['total_to_company_profit']);
        // The pool row keeps its amount after settling; its ৳100 leftover must not be added again.
        $this->assertSame($before['salary_pool']['money_in'], $after['salary_pool']['money_in']);
        $this->assertSame('0.00', $after['salary_pool']['balance']);
        $this->assertSame(10000, Money::cents($after['company_profit']['money_in']) - Money::cents($before['company_profit']['money_in']));
        // What the funds hold together falls only by what partners were paid.
        $held = fn ($cards): int => $cards->sum(fn (array $card): int => Money::cents($card['balance']));
        $paidToPartners = collect($result['distributed'])->sum(fn (array $share): int => Money::cents($share['amount']));
        $this->assertSame($held($before) - $paidToPartners, $held($after));
    }

    public function test_closed_month_salary_uses_actual_period_volume_and_sub_dealer_receives_flat_three_percent_once(): void
    {
        $dealer = $this->unionDealer();
        $sub = User::factory()->inPlace(PlaceCode::factory()->division()->create())->create(['role' => 'sub_dealer']);
        $this->purchase($dealer, '30000.00', '60000.00');
        $this->purchase($sub, '40000.00', '66000.00');
        $walletBefore = fn (User $user): string => (string) DB::table('wallets')->where('user_id', $user->id)->value('available');
        $dealerBefore = $walletBefore($dealer);
        $subBefore = $walletBefore($sub);
        $this->travelTo(now()->setDate(2026, 10, 1));
        $result = app(CommissionService::class)->settleMonthlySalaryPool('2026-09');

        // The dealer reached his ৳30,000 union target and takes the whole dealer salary fund: 2% of both orders.
        // The sub-dealer's own ৳40,000 reached the top step, so he is paid the full 3% of it, once.
        $this->assertSame('settled', $result['status']);
        $this->assertSame(Money::cents($dealerBefore) + 140000, Money::cents($walletBefore($dealer)));
        $this->assertSame(Money::cents($subBefore) + 120000, Money::cents($walletBefore($sub)));
        $this->assertSame('empty_pool', app(CommissionService::class)->settleMonthlySalaryPool('2026-09')['status']);
        $this->assertSame(Money::cents($subBefore) + 120000, Money::cents($walletBefore($sub)));
        $this->assertDatabaseHas('commission_allocations', ['user_id' => $sub->id, 'category' => 'sub_dealer_tour', 'status' => 'reserved', 'amount' => '1200.00']);
    }

    public function test_settlement_refunds_recover_both_direct_earnings_and_source_pool_payouts_without_repeating(): void
    {
        $dealer = $this->unionDealer();
        $order = $this->purchase($dealer, '30000.00', '60000.00');
        $this->travelTo(now()->setDate(2026, 10, 1));
        $service = app(CommissionService::class);
        $service->settleMonthlySalaryPool('2026-09');
        $this->assertDatabaseHas('wallets', ['user_id' => $dealer->id, 'available' => '2100.00']);
        $service->reverseOrderPartial($order, '15000.00', 'Verified return', 'RETURN-001');
        $service->reverseOrderPartial($order, '15000.00', 'Verified return', 'RETURN-001');

        $this->assertDatabaseHas('wallets', ['user_id' => $dealer->id, 'available' => '1050.00']);
        $this->assertDatabaseCount('commission_refunds', 1);
        $this->assertDatabaseHas('commission_allocations', ['order_id' => $order->id, 'category' => 'own_dealer_cashback', 'original_amount' => '1500.00', 'amount' => '750.00']);
        $service->reverseOrder($order, 'Remaining goods returned');
        $this->assertDatabaseHas('wallets', ['user_id' => $dealer->id, 'available' => '0.00']);
        $this->assertDatabaseHas('commission_batches', ['order_id' => $order->id, 'refunded_amount' => '30000.00']);
    }

    public function test_refund_cannot_exceed_remaining_value_or_reuse_a_reference_for_different_data(): void
    {
        $order = $this->purchase(User::factory()->create(), '100.00', '200.00');
        $service = app(CommissionService::class);
        $service->reverseOrderPartial($order, '60.00', 'Goods returned', 'RETURN-002');
        try {
            $service->reverseOrderPartial($order, '50.00', 'More goods returned', 'RETURN-003');
            $this->fail('An excessive refund must fail.');
        } catch (ValidationException $exception) {
            $this->assertArrayHasKey('refund_amount', $exception->errors());
        }
        $this->assertDatabaseCount('commission_refunds', 1);
        $this->assertDatabaseHas('commission_batches', ['order_id' => $order->id, 'refunded_amount' => '60.00']);
    }

    public function test_royalty_reconciles_pennies_across_rank_buckets_and_repeated_settlement_is_empty(): void
    {
        $this->lowerTheNoAreaTarget('300.00');
        $leader = User::factory()->create();
        DealerRank::create(['user_id' => $leader->id, 'current_rank' => 'mo', 'achieved_at' => now()]);
        $order = $this->purchase($leader, '333.33', '666.66');
        $this->travelTo(now()->setDate(2026, 10, 1));
        $result = app(CommissionService::class)->settleRankRoyaltyPool('2026-09');
        $tiers = $result['distributions'];
        $sum = 0;
        foreach ($tiers as $tier) {
            $sum += Money::cents($tier['allocated']) + Money::cents($tier['rolled_to_reserve']);
        }

        // The 4% of ৳333.33 is ৳13.3332: the order posts four equal ৳3.33 rank funds; the paisa that does not divide
        // went to the Commission extra fund with the order's surplus when the order was posted, so the close has none.
        $this->assertSame(1332, $sum);
        $this->assertSame('0.00', $result['total_to_commission_extra_fund']);
        $this->assertSame('empty_pool', app(CommissionService::class)->settleRankRoyaltyPool('2026-09')['status']);
        foreach (RankRoyaltyFunds::Categories as $category) {
            $this->assertDatabaseHas('commission_allocations', ['order_id' => $order->id, 'category' => $category, 'status' => 'settled', 'amount' => '3.33']);
        }
        $this->assertDatabaseMissing('commission_allocations', ['order_id' => $order->id, 'category' => RankRoyaltyFunds::LegacyCategory]);
        $this->assertSame(333, Money::cents((string) DB::table('commission_settlement_payouts')->where('user_id', $leader->id)->where('kind', 'royalty')->sum('amount')));
        // The AM, SM and ED funds had no holder: one ৳3.33 company profit row each.
        $this->assertSame(3, DB::table('commission_allocations')->where('order_id', $order->id)->where('category', 'company_profit_fund')
            ->where('status', 'reserved')->where('amount', '3.33')->count());
        $this->assertSame('9.99', $result['total_to_company_profit']);
    }

    /**
     * MA-01: a fund row above about ৳3.04 crore made the month close multiply two paisa amounts past
     * PHP's integer limit, so every royalty and salary close failed. A ৳100 crore order must close.
     */
    public function test_a_hundred_crore_order_closes_royalty_and_salary_into_company_profit(): void
    {
        $order = $this->purchase(User::factory()->create(), '1000000000.00', '2000000000.00');
        $this->travelTo(now()->setDate(2026, 10, 1));

        $royalty = app(CommissionService::class)->settleRankRoyaltyPool('2026-09');
        $salary = app(CommissionService::class)->settleMonthlySalaryPool('2026-09');

        // The ৳100 crore buyer is an MO for the month; the three empty rank tiers (৳3 crore) go to company profit.
        $this->assertSame('settled', $royalty['status']);
        $this->assertSame('10000000.00', $royalty['total_distributed']);
        $this->assertSame('30000000.00', $royalty['total_to_company_profit']);
        $this->assertSame('20000000.00', $salary['total_pool']);
        $this->assertSame(Money::cents('20000000.00'), Money::cents($salary['total_distributed']) + Money::cents($salary['total_to_company_profit']));
        // One ৳1 crore company profit row for each of the AM, SM and ED funds.
        $this->assertSame(3, DB::table('commission_allocations')->where('order_id', $order->id)->where('category', 'company_profit_fund')
            ->where('metadata->settlement_kind', 'royalty')->where('amount', '10000000.00')->where('original_amount', '10000000.00')->where('status', 'reserved')->count());
        $this->assertDatabaseMissing('commission_allocations', ['order_id' => $order->id, 'category' => 'rank_royalty_mo_pool', 'status' => 'pending']);
        $this->assertTrue(app(FinancialReadiness::class)->inspect()['checks']['allocation_totals']);
    }

    public function test_rank_incentives_require_real_reserve_funding_and_cannot_be_claimed_twice(): void
    {
        $leader = User::factory()->create();
        DealerRank::create(['user_id' => $leader->id, 'current_rank' => 'mo', 'achieved_at' => now()]);
        $service = app(CommissionService::class);
        // The leader met his ৳30,000 monthly target, but the reserve (2% of his own sale, ৳600) cannot pay ৳5,000 yet.
        $this->purchase($leader, '30000.00', '60000.00');
        $walletBefore = Money::cents((string) DB::table('wallets')->where('user_id', $leader->id)->value('available'));
        $this->assertFalse($service->claimOneTimeRankIncentive($leader->id)['success']);
        // A sub-dealer's ৳2,50,000 purchase (below his own MO target) funds the reserve.
        $this->purchase(User::factory()->create(['role' => 'sub_dealer']), '250000.00', '400000.00');
        $claim = $service->claimOneTimeRankIncentive($leader->id);

        $this->assertTrue($claim['success']);
        $this->assertSame('5000.00', $claim['amount']);
        $this->assertFalse($service->claimOneTimeRankIncentive($leader->id)['success']);
        $this->assertDatabaseCount('rank_incentive_awards', 1);
        $this->assertSame($walletBefore + 500000, Money::cents((string) DB::table('wallets')->where('user_id', $leader->id)->value('available')));
    }

    public function test_a_leader_promoted_twice_before_the_reward_runs_still_gets_the_lower_rank_reward(): void
    {
        $leader = User::factory()->create();
        DealerRank::create(['user_id' => $leader->id, 'current_rank' => 'am', 'achieved_at' => now(), 'qualification_history' => [
            ['rank' => 'mo', 'achieved_at' => now()->subMinutes(20)->toIso8601String()],
            ['rank' => 'am', 'achieved_at' => now()->toIso8601String()],
        ]]);
        // Enough volume to keep AM this month and a reserve that funds both rewards (2% of ৳30 lakh = ৳60,000).
        $this->purchase($leader, '3000000.00', '6000000.00');
        $service = app(CommissionService::class);

        $first = $service->claimOneTimeRankIncentive($leader->id);
        $second = $service->claimOneTimeRankIncentive($leader->id);
        $third = $service->claimOneTimeRankIncentive($leader->id);

        $this->assertSame(['mo', '5000.00'], [$first['rank'], $first['amount']]);
        $this->assertSame(['am', '50000.00'], [$second['rank'], $second['amount']]);
        $this->assertFalse($third['success']);
        $this->assertSame(['am', 'mo'], DB::table('rank_incentive_awards')->where('user_id', $leader->id)->orderBy('rank')->pluck('rank')->all());
    }

    public function test_the_rank_reward_is_paid_the_moment_the_rank_is_reached(): void
    {
        $dealer = User::factory()->create();

        // ৳5,00,000 of own purchases reaches MO; this sale's 2% reward reserve (৳10,000) funds the ৳5,000 MO reward.
        $this->purchase($dealer, '500000.00', '1000000.00');

        $this->assertSame('mo', DealerRank::where('user_id', $dealer->id)->value('current_rank'));
        $this->assertDatabaseHas('rank_incentive_awards', ['user_id' => $dealer->id, 'rank' => 'mo', 'amount' => '5000.00']);
    }

    public function test_royalty_is_paid_only_for_the_latest_rank_reached_in_the_month(): void
    {
        $leader = User::factory()->create();
        DealerRank::create(['user_id' => $leader->id, 'current_rank' => 'am', 'achieved_at' => now(), 'qualification_history' => [
            ['rank' => 'mo', 'achieved_at' => now()->subDays(2)->toIso8601String()],
            ['rank' => 'am', 'achieved_at' => now()->toIso8601String()],
        ]]);
        $mo = User::factory()->create();
        DealerRank::create(['user_id' => $mo->id, 'current_rank' => 'mo', 'achieved_at' => now()]);
        $this->purchase($leader, '3000000.00', '6000000.00');
        $this->purchase($mo, '30000.00', '60000.00');
        $this->travelTo(now()->setDate(2026, 10, 1));

        app(CommissionService::class)->settleRankRoyaltyPool('2026-09');

        // 4% of ৳30.3 lakh = ৳1,21,200 in four ৳30,300 rank buckets: the AM bucket goes to the leader, the MO bucket
        // to the MO dealer only (he met his ৳30,000 target). The leader is not paid as MO as well.
        $royalty = fn (User $user): int => (int) DB::table('commission_settlement_payouts')->where('user_id', $user->id)->where('kind', 'royalty')
            ->get()->sum(fn ($row) => Money::cents((string) $row->amount));
        $this->assertSame(3030000, $royalty($leader));
        $this->assertSame(3030000, $royalty($mo));
    }

    public function test_a_rank_never_reached_is_not_rewarded(): void
    {
        // Promoted to AM by team size without ever buying the MO target: no MO reward.
        $leader = User::factory()->create();
        DealerRank::create(['user_id' => $leader->id, 'current_rank' => 'am', 'achieved_at' => now(), 'qualification_history' => [
            ['rank' => 'am', 'achieved_at' => now()->toIso8601String()],
        ]]);
        $this->purchase($leader, '3000000.00', '6000000.00');
        $service = app(CommissionService::class);

        $this->assertSame('am', $service->claimOneTimeRankIncentive($leader->id)['rank']);
        $this->assertFalse($service->claimOneTimeRankIncentive($leader->id)['success']);
        $this->assertDatabaseMissing('rank_incentive_awards', ['user_id' => $leader->id, 'rank' => 'mo']);
    }

    public function test_rank_incentive_is_blocked_when_maintenance_demotes_the_leader(): void
    {
        $leader = User::factory()->create();
        DealerRank::create([
            'user_id' => $leader->id,
            'current_rank' => 'mo',
            'achieved_at' => now()->subDays(60),
        ]);
        $this->purchase(User::factory()->create(['role' => 'sub_dealer']), '250000.00', '400000.00');
        $claim = app(CommissionService::class)->claimOneTimeRankIncentive($leader->id);

        $this->assertFalse($claim['success']);
        $this->assertStringContainsString('monthly target', $claim['message']);
        $this->assertDatabaseCount('rank_incentive_awards', 0);
    }

    public function test_salary_pool_with_no_qualifiers_moves_cash_into_company_profit(): void
    {
        $buyer = User::factory()->create();
        $order = $this->purchase($buyer, '5000.00', '10000.00');
        $this->travelTo(now()->setDate(2026, 10, 1));
        $result = app(CommissionService::class)->settleMonthlySalaryPool('2026-09');

        $this->assertSame('rolled_over', $result['status']);
        $this->assertSame('100.00', $result['total_to_company_profit']);
        $this->assertSame('0.00', $result['total_rolled_to_reserve']);
        $this->assertDatabaseHas('commission_allocations', [
            'order_id' => $order->id,
            'category' => 'company_profit_fund',
            'status' => 'reserved',
            'amount' => '100.00',
        ]);
        $this->assertDatabaseMissing('commission_settlement_payouts', ['user_id' => $buyer->id, 'kind' => 'salary']);
    }

    public function test_reconciliation_still_balances_after_purchase_surplus_and_unclaimed_settlement(): void
    {
        // MRP 200 paid 120 leaves a ৳12 purchase surplus in the owner's benefits fund.
        $this->purchase(User::factory()->create(), '120.00', '200.00');
        $this->purchase(User::factory()->create(), '5000.00', '10000.00');
        $this->assertDatabaseHas('commission_allocations', ['category' => 'company_commission_benefits_fund']);
        $this->assertTrue(app(FinancialReadiness::class)->inspect()['checks']['allocation_totals']);

        $this->travelTo(now()->setDate(2026, 10, 1));
        $this->assertSame('rolled_over', app(CommissionService::class)->settleMonthlySalaryPool('2026-09')['status']);
        $this->assertDatabaseHas('commission_allocations', ['category' => 'company_profit_fund']);

        $this->assertTrue(app(FinancialReadiness::class)->inspect()['checks']['allocation_totals']);
    }

    public function test_open_month_cannot_be_settled_early(): void
    {
        $this->purchase(User::factory()->create(), '10000.00', '20000.00');
        $this->expectException(ValidationException::class);
        app(CommissionService::class)->settleMonthlySalaryPool('2026-09');
    }

    public function test_business_month_uses_dhaka_at_the_utc_month_boundary(): void
    {
        $this->travelTo(CarbonImmutable::parse('2026-09-30T19:00:00Z'));
        $buyer = User::factory()->create();
        $order = $this->purchase($buyer, '100.00', '200.00');
        $this->assertDatabaseHas('commission_batches', ['order_id' => $order->id, 'period_month' => '2026-10']);
        $this->actingAs($buyer)->getJson('/api/v1/earnings')->assertOk()->assertJsonPath('data.monthly_purchase_value', '100.00');
        $this->assertSame(0, app(CommissionQualification::class)->monthlyVolume($buyer->id, '2026-09'));
    }

    public function test_monthly_salary_retains_the_qualification_rule_captured_by_its_source_order(): void
    {
        // A dealer with no appointed place has the no-area target (৳30,000); the order keeps the target it was captured with.
        $buyer = User::factory()->create();
        $this->purchase($buyer, '30000.00', '60000.00');
        $this->travelTo(now()->setDate(2026, 10, 1));
        $this->actingAs(User::factory()->create(['role' => 'super_admin']))->patchJson('/api/v1/finance/policy',
            ['rules' => ['dealer_monthly_salary' => ['parameters' => ['min_monthly_volume' => '50000.00']]]])->assertOk();
        app(CommissionService::class)->settleMonthlySalaryPool('2026-09');
        $this->assertDatabaseHas('wallets', ['user_id' => $buyer->id, 'available' => '2100.00']);
    }

    public function test_a_rank_achieved_after_month_end_does_not_receive_retroactive_royalty(): void
    {
        $this->purchase(User::factory()->create(), '10000.00', '20000.00');
        $this->travelTo(now()->setDate(2026, 10, 2));
        $leader = User::factory()->create();
        DealerRank::create(['user_id' => $leader->id, 'current_rank' => 'mo', 'achieved_at' => now()]);
        $result = app(CommissionService::class)->settleRankRoyaltyPool('2026-09');
        $this->assertSame('0.00', $result['total_distributed']);
        $this->assertSame('400.00', $result['total_to_company_profit']);
        $this->assertDatabaseMissing('commission_settlement_payouts', ['user_id' => $leader->id]);
        // Each of the four ৳100 rank funds goes to company profit on its own row.
        $this->assertSame(4, DB::table('commission_allocations')->where('category', 'company_profit_fund')->where('status', 'reserved')
            ->where('metadata->settlement_kind', 'royalty')->where('amount', '100.00')->count());
    }

    public function test_mo_requires_five_lakh_in_qualifying_own_purchases(): void
    {
        $buyer = User::factory()->create();
        $this->purchase($buyer, '490000.00', '980000.00');
        $this->assertDatabaseHas('dealer_ranks', ['user_id' => $buyer->id, 'current_rank' => 'dealer']);
        $this->purchase($buyer, '10000.00', '20000.00');
        $this->assertDatabaseHas('dealer_ranks', ['user_id' => $buyer->id, 'current_rank' => 'mo', 'lifetime_purchase_total' => '500000.00']);
        // Owner rule: the MO reward is paid the moment MO is reached.
        $this->assertDatabaseHas('rank_incentive_awards', ['user_id' => $buyer->id, 'rank' => 'mo', 'amount' => '5000.00']);
    }

    public function test_a_refund_blocks_an_unpaid_withdrawal_and_rejection_clears_its_clawback(): void
    {
        $buyer = User::factory()->create();
        $order = $this->purchase($buyer, '10000.00', '20000.00');
        $withdrawal = $this->actingAs($buyer)->postJson('/api/v1/withdrawals', [
            'amount' => '500.00', 'channel' => 'Bank', 'destination' => 'TEST-BANK-1234', 'idempotency_key' => (string) Str::uuid(),
        ])->assertCreated()->json('data.id');
        app(CommissionService::class)->reverseOrder($order, 'Returned before payout');
        $finance = User::factory()->create(['role' => 'finance']);
        $this->actingAs($finance)->postJson('/commands/withdrawals/'.$withdrawal.'/review', [
            'decision' => 'approved', 'reason' => 'Check refund exposure',
        ])->assertUnprocessable();
        $this->postJson('/commands/withdrawals/'.$withdrawal.'/review', [
            'decision' => 'rejected', 'reason' => 'Offset refunded earnings before payment',
        ])->assertRedirect();
        $this->assertDatabaseHas('wallets', ['user_id' => $buyer->id, 'available' => '0.00', 'held' => '0.00']);
        $this->assertFalse(CommissionWallet::hasDebt($buyer->id));
        $this->assertDatabaseHas('withdrawals', ['id' => $withdrawal, 'status' => 'rejected']);
        // MA-26: the partner is told that the returned money paid off the refund debt.
        $this->assertDatabaseHas('account_notices', ['user_id' => $buyer->id, 'title' => __('money_core.notice_debt_recovered_title', [], $buyer->fresh()->preferredLocale())]);
    }
}
