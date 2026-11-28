<?php

namespace Tests\Feature;

use App\Domain\Accounts\PartnerPendingMoney;
use App\Domain\Commissions\CommissionPosting;
use App\Domain\Commissions\CommissionSettlement;
use App\Domain\Commissions\RankRoyaltyFunds;
use App\Domain\Commissions\RecipientFunding;
use App\Domain\Finance\FinancialReadiness;
use App\Domain\Finance\FundCatalog;
use App\Domain\Finance\OrderMoneyReport;
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
use Tests\TestCase;

/**
 * The owner's rule: the rank royalty (4% of each order) goes into FOUR funds, 1% each: MO, AM, SM and ED (stored key
 * me). Each fund is paid after the month ends, equally, to the holders of that rank who met their monthly target; a
 * fund nobody qualifies for goes to the company profit fund. Orders posted before this rule kept one row in the old
 * single fund; the month close still splits those rows by the share percents, with no paisa counted twice.
 */
class RankRoyaltyFourFundsTest extends TestCase
{
    use RefreshDatabase;

    private PlaceCode $division;

    private PlaceCode $union;

    protected function setUp(): void
    {
        parent::setUp();
        $this->withoutVite();
        $this->enableFinancialPosting();
        $this->seed(CommissionRuleSeeder::class);
        $this->travelTo(now()->setDate(2026, 12, 10)->setTime(11, 0));
        $this->division = PlaceCode::factory()->division()->create(['name' => 'Rangpur']);
        $district = PlaceCode::factory()->district($this->division)->create(['name' => 'Dinajpur']);
        $upazila = PlaceCode::factory()->upazila($district)->create(['name' => 'Nawabganj']);
        $this->union = PlaceCode::factory()->union($upazila)->create(['name' => 'Balia']);
    }

    protected function tearDown(): void
    {
        $this->travelBack();
        parent::tearDown();
    }

    public function test_an_order_posts_four_rank_funds_of_one_percent_each_and_no_single_fund(): void
    {
        $order = $this->purchase($this->partner('dealer', $this->union), '30000.00');

        foreach (RankRoyaltyFunds::Categories as $rank => $category) {
            $row = CommissionAllocation::where('order_id', $order->id)->where('category', $category)->sole();
            $this->assertSame('300.00', (string) $row->amount, $rank);
            $this->assertSame('1.00', (string) $row->rate_percentage, $rank);
            $this->assertSame('pending', $row->status);
            $this->assertNull($row->user_id);
            $this->assertSame($rank, $row->metadata['rank']);
            $this->assertSame('2026-12', $row->metadata['period_month']);
        }
        $this->assertSame(0, CommissionAllocation::where('category', RankRoyaltyFunds::LegacyCategory)->count());
        $this->assertSame(1200_00, $this->royaltyCents($order));
        $this->assertBooksAddUp();
    }

    public function test_the_four_funds_stay_equal_and_the_paisa_that_do_not_divide_go_to_the_commission_extra_fund(): void
    {
        $order = $this->purchase($this->partner('dealer', $this->union), '333.33');

        // 4% of ৳333.33 is ৳13.3332: four funds of ৳3.33; the paisa left is in the order's Commission extra fund row.
        foreach (RankRoyaltyFunds::Categories as $category) {
            $this->assertSame('3.33', (string) CommissionAllocation::where('order_id', $order->id)->where('category', $category)->sole()->amount);
        }
        $batch = DB::table('commission_batches')->where('order_id', $order->id)->first();
        $allocated = (int) CommissionAllocation::where('order_id', $order->id)->where('category', '!=', 'company_commission_benefits_fund')->get()
            ->sum(fn (CommissionAllocation $row): int => Money::cents((string) $row->amount));
        $extra = Money::cents((string) CommissionAllocation::where('order_id', $order->id)->where('category', 'company_commission_benefits_fund')->value('amount'));
        $this->assertSame(Money::cents((string) $batch->cash_basis), Money::cents((string) $batch->company_share) + $allocated + $extra, 'Every paisa of the order is placed.');
        $this->assertSame(Money::cents((string) $batch->total_allocated), $allocated);
        $this->assertBooksAddUp();
    }

    public function test_edited_shares_change_the_four_amounts_and_shares_that_do_not_add_up_are_refused(): void
    {
        $owner = User::factory()->create(['role' => 'super_admin']);
        $this->actingAs($owner)->patchJson('/api/v1/finance/policy', ['rules' => ['rank_royalty' => ['parameters' => [
            'mo_share_pct' => '0.50', 'am_share_pct' => '1.00', 'sm_share_pct' => '1.00', 'me_share_pct' => '1.50',
        ]]]])->assertOk();
        $this->actingAs($owner)->patchJson('/api/v1/finance/policy', ['rules' => ['rank_royalty' => ['parameters' => [
            'mo_share_pct' => '2.00',
        ]]]])->assertStatus(422);

        $order = $this->purchase($this->partner('dealer', $this->union), '10000.00');

        $amounts = CommissionAllocation::where('order_id', $order->id)->whereIn('category', RankRoyaltyFunds::Categories)->pluck('amount', 'category')->map(fn ($amount): string => (string) $amount)->all();
        $this->assertSame(['rank_royalty_mo_pool' => '50.00', 'rank_royalty_am_pool' => '100.00', 'rank_royalty_sm_pool' => '100.00', 'rank_royalty_me_pool' => '150.00'], $this->sorted($amounts));
        $this->assertSame(400_00, $this->royaltyCents($order), 'Still the 4% total.');
    }

    public function test_each_fund_pays_only_its_own_rank_holders_and_an_empty_fund_goes_to_company_profit(): void
    {
        $mo = $this->partner('dealer', $this->union, 'mo');
        $am = $this->partner('dealer', $this->union, 'am');
        $amShort = $this->partner('dealer', $this->union, 'am');
        $first = $this->purchase($mo, '30000.00');
        $this->purchase($am, '30000.00');
        $this->purchase($amShort, '29999.00');
        $funds = CommissionAllocation::whereIn('category', RankRoyaltyFunds::Categories)->get()
            ->groupBy('category')->map(fn ($rows): int => (int) $rows->sum(fn ($row): int => Money::cents((string) $row->amount)));

        $result = $this->closeMonth();

        $this->assertSame($funds['rank_royalty_mo_pool'], $this->paidTo($mo), 'The whole MO fund to the one MO.');
        $this->assertSame($funds['rank_royalty_am_pool'], $this->paidTo($am), 'The AM below his target gets nothing, so the other AM takes the AM fund.');
        $this->assertSame(0, $this->paidTo($amShort));
        $this->assertSame(1, $result['distributions']['mo']['qualifiers']);
        $this->assertSame(1, $result['distributions']['am']['qualifiers']);
        $this->assertSame(0, $result['distributions']['sm']['qualifiers']);
        foreach (['rank_royalty_sm_pool', 'rank_royalty_me_pool'] as $category) {
            $moved = (int) CommissionAllocation::where('category', 'company_profit_fund')->where('metadata->source_category', $category)->get()
                ->sum(fn ($row): int => Money::cents((string) $row->amount));
            $this->assertSame($funds[$category], $moved, $category.' had no qualified holder.');
        }
        $this->assertSame(0, CommissionAllocation::where('category', 'company_profit_fund')->whereIn('metadata->source_category', ['rank_royalty_mo_pool', 'rank_royalty_am_pool'])->count());
        $this->assertSame(0, CommissionAllocation::whereIn('category', RankRoyaltyFunds::Categories)->where('status', 'pending')->count());
        $this->assertSame(Money::decimal($funds->sum()), $result['total_pool']);
        $this->assertSame('empty_pool', app(CommissionSettlement::class)->settle('2026-12', 'royalty')['status'], 'A second close pays nothing.');
        $this->assertSame($funds['rank_royalty_mo_pool'], $this->paidTo($mo));
        $this->assertNotNull($first);
        $this->assertBooksAddUp();
    }

    public function test_with_nobody_qualified_all_four_funds_go_to_company_profit(): void
    {
        $order = $this->purchase($this->partner('dealer', $this->union), '30000.00');

        $result = $this->closeMonth();

        $this->assertSame('0.00', $result['total_distributed']);
        $this->assertSame('1200.00', $result['total_to_company_profit']);
        foreach (RankRoyaltyFunds::Categories as $category) {
            $this->assertSame('300.00', (string) CommissionAllocation::where('order_id', $order->id)->where('category', 'company_profit_fund')
                ->where('metadata->source_category', $category)->sole()->amount);
        }
        $this->assertBooksAddUp();
    }

    public function test_older_single_fund_rows_and_new_rank_fund_rows_close_together_without_counting_twice(): void
    {
        $mo = $this->partner('dealer', $this->union, 'mo');
        $this->purchase($mo, '30000.00');
        $older = $this->purchase($this->partner('dealer', $this->union), '10000.00');
        $this->asOlderOrder($older);
        $legacy = CommissionAllocation::where('order_id', $older->id)->where('category', RankRoyaltyFunds::LegacyCategory)->sole();
        $this->assertSame('400.00', (string) $legacy->amount);
        $newFunds = (int) CommissionAllocation::whereIn('category', RankRoyaltyFunds::Categories)->get()->sum(fn ($row): int => Money::cents((string) $row->amount));

        $result = $this->closeMonth();

        // The MO gets the new MO fund (৳300) and the MO quarter of the older single fund (৳100).
        $this->assertSame(300_00 + 100_00, $this->paidTo($mo));
        $this->assertSame('settled', $legacy->fresh()->status);
        $this->assertSame('100.00', $legacy->fresh()->metadata['distributed']);
        $this->assertSame('300.00', $legacy->fresh()->metadata['reserved']);
        $moved = (int) CommissionAllocation::where('category', 'company_profit_fund')->where('metadata->settlement_kind', 'royalty')->get()->sum(fn ($row): int => Money::cents((string) $row->amount));
        $extra = (int) CommissionAllocation::where('category', 'company_commission_benefits_fund')->where('metadata->settlement_kind', 'royalty')->get()->sum(fn ($row): int => Money::cents((string) $row->amount));
        $this->assertSame($newFunds + 400_00, $this->paidTo($mo) + $moved + $extra, 'Every paisa of both kinds of row is placed once.');
        $this->assertSame(Money::decimal($newFunds + 400_00), $result['total_pool']);
        $this->assertSame(1, DB::table('commission_settlement_payouts')->where('allocation_id', $legacy->id)->count());
        $this->assertSame('empty_pool', app(CommissionSettlement::class)->settle('2026-12', 'royalty')['status']);
        $this->assertBooksAddUp();
    }

    public function test_treasury_shows_four_rank_royalty_funds_that_add_up_and_the_older_fund_only_while_it_holds_money(): void
    {
        $treasury = app(TreasuryFunds::class);
        $this->purchase($this->partner('dealer', $this->union, 'mo'), '30000.00');

        $cards = collect($treasury->cards(true))->keyBy('key');
        foreach (['royalty_mo' => 'Marketing Officer', 'royalty_am' => 'Area Manager', 'royalty_sm' => 'Sales Manager', 'royalty_me' => 'ED'] as $key => $rank) {
            $this->assertSame('300.00', $cards[$key]['money_in'], $key);
            $this->assertSame('300.00', $cards[$key]['balance'], $key);
            $this->assertFalse($cards[$key]['spendable'], $key);
            $this->assertStringStartsWith($rank.' ', $cards[$key]['name']);
            $this->assertSame('300.00', $treasury->moneyIn($this->allRows(), $key)['totals']['sums']['amount'], $key.' rows in');
        }
        $this->assertSame('ED rank royalty fund', (string) __('treasury.fund_royalty_me', [], 'en'));
        $this->assertFalse($cards->has('royalty_pool'), 'No older single-fund money yet.');

        $older = $this->purchase($this->partner('dealer', $this->union), '10000.00');
        $this->asOlderOrder($older);
        $this->assertSame('400.00', collect($treasury->cards(true))->firstWhere('key', 'royalty_pool')['money_in']);

        $this->closeMonth();
        foreach (['royalty_mo', 'royalty_am', 'royalty_sm', 'royalty_me', 'royalty_pool'] as $key) {
            $card = $treasury->card($key, true);
            $this->assertSame('0.00', $card['balance'], $key);
            $this->assertSame($card['money_in'], $card['money_out'], $key);
            $this->assertSame($card['money_out'], $treasury->moneyOut($this->allRows(), $key)['totals']['sums']['amount'], $key.' rows out');
        }
    }

    public function test_the_order_money_breakdown_shows_four_rank_royalty_lines(): void
    {
        $order = $this->purchase($this->partner('dealer', $this->union), '30000.00');

        $detail = app(OrderMoneyReport::class)->detail($order->fresh(), true);
        $royalty = collect($detail['lines'])->filter(fn (array $line): bool => in_array($line['to']['key'] ?? null, RankRoyaltyFunds::FundKeys, true))->values();

        $this->assertCount(4, $royalty);
        $this->assertSame(array_values(RankRoyaltyFunds::FundKeys), $royalty->pluck('to.key')->all());
        $this->assertSame(['300.00', '300.00', '300.00', '300.00'], $royalty->pluck('amount')->all());
        $this->assertSame(['1.00', '1.00', '1.00', '1.00'], $royalty->pluck('percent')->all());
        $this->assertSame([FundCatalog::name('royalty_mo'), FundCatalog::name('royalty_am'), FundCatalog::name('royalty_sm'), FundCatalog::name('royalty_me')], $royalty->pluck('to.name')->all());
        $this->assertTrue($detail['adds_up']);
    }

    public function test_a_special_rate_funded_from_the_rank_royalty_takes_it_from_the_four_rank_funds(): void
    {
        $rows = [['category' => 'direct_referral', 'rule_key' => 'direct_referral', 'user_id' => 7, 'status' => 'available', 'rate_percentage' => '10.00', 'cents' => 1000_00, 'metadata' => []]];
        foreach (RankRoyaltyFunds::Categories as $category) {
            $rows[] = ['category' => $category, 'rule_key' => 'rank_royalty', 'user_id' => null, 'status' => 'pending', 'rate_percentage' => '1.00', 'cents' => 100_00, 'metadata' => []];
        }
        $snapshot = ['recipient_overrides' => [7 => ['schedule_id' => 1, 'rates' => ['direct_referral' => [
            'percentage' => '12.00', 'funding_mode' => 'allocations', 'funding_sources' => [RankRoyaltyFunds::LegacyCategory => '2.00'],
        ]]]]];

        $funded = app(RecipientFunding::class)->apply($rows, $snapshot, 10000_00, 3000_00);

        $this->assertSame(1200_00, $funded['rows'][0]['cents']);
        foreach (range(1, 4) as $index) {
            $this->assertSame(50_00, $funded['rows'][$index]['cents'], 'Each rank fund gives half of its ৳100.');
        }
        $this->assertSame(array_sum(array_column($rows, 'cents')), array_sum(array_column($funded['rows'], 'cents')));
    }

    public function test_a_new_orders_area_share_waits_for_the_month_end_and_is_not_in_the_wallet(): void
    {
        $dealer = $this->partner('dealer', $this->division);
        AreaDealerAssignment::create(['user_id' => $dealer->id, 'level' => 'division', 'place_code_id' => $this->division->id,
            'division' => 'Rangpur', 'zone_name' => 'Rangpur', 'monthly_quota' => '100.00', 'is_active' => true]);
        $this->purchase($dealer, '100000.00');
        $walletBefore = (string) DB::table('wallets')->where('user_id', $dealer->id)->value('available');

        $order = $this->purchase($this->partner('dealer', $this->division), '1000.00');
        $share = CommissionAllocation::where('order_id', $order->id)->where('category', 'area_division')->sole();

        $this->assertSame('held', $share->status);
        $this->assertSame('quota', $share->metadata['hold_reason']);
        $this->assertTrue($share->metadata['monthly_close']);
        $this->assertSame($walletBefore, (string) DB::table('wallets')->where('user_id', $dealer->id)->value('available'), 'Not credited at order time.');
        $waiting = collect(app(PartnerPendingMoney::class)->for($dealer->fresh())['month_end'])->firstWhere('id', 'area-'.$share->id);
        $this->assertNotNull($waiting, 'Shown as a waiting month-end line in the wallet.');
        $this->assertSame('month_end_area_met', $waiting['reason']);
        $this->assertSame('area_division', $waiting['head']);
        $this->assertSame((string) $share->amount, $waiting['amount']);
    }

    // ---- helpers ----

    private function partner(string $role, ?PlaceCode $place = null, string $rank = 'dealer'): User
    {
        $user = User::factory()->inPlace($place ?? $this->division)->create(['role' => $role]);
        ReferralTreePath::attachUser($user->id, null);
        if ($rank !== 'dealer') {
            DealerRank::create(['user_id' => $user->id, 'current_rank' => $rank, 'achieved_at' => now()->subMonth()]);
        }

        return $user;
    }

    private function purchase(User $buyer, string $cash): Order
    {
        $product = Product::factory()->create(['mrp' => bcmul($cash, '2', 2), 'dealer_price' => $cash]);
        $order = app(OrderService::class)->create($buyer, ['items' => [['product_id' => $product->id, 'quantity' => 1]], 'idempotency_key' => (string) Str::uuid()]);
        $order->update(['paid' => $cash, 'status' => 'delivered']);
        app(CommissionPosting::class)->process($order->fresh());

        return $order;
    }

    /**
     * Makes a posted order look like one posted before the four funds: its four rank rows become one row in the old
     * single fund, with the same total, as live orders of that time were stored.
     */
    private function asOlderOrder(Order $order): void
    {
        $rows = CommissionAllocation::where('order_id', $order->id)->whereIn('category', RankRoyaltyFunds::Categories)->get();
        $total = (int) $rows->sum(fn ($row): int => Money::cents((string) $row->amount));
        $first = $rows->first();
        CommissionAllocation::whereIn('id', $rows->pluck('id'))->delete();
        CommissionAllocation::create(['order_id' => $order->id, 'user_id' => null, 'category' => RankRoyaltyFunds::LegacyCategory,
            'rate_percentage' => '4.00', 'cash_basis' => $first->cash_basis, 'amount' => Money::decimal($total), 'original_amount' => Money::decimal($total),
            'status' => 'pending', 'metadata' => ['basis' => 'purchase_value', 'period_month' => $first->metadata['period_month']]]);
    }

    private function royaltyCents(Order $order): int
    {
        return (int) CommissionAllocation::where('order_id', $order->id)->whereIn('category', RankRoyaltyFunds::Categories)->get()
            ->sum(fn ($row): int => Money::cents((string) $row->amount));
    }

    /** @return array<string, mixed> */
    private function closeMonth(): array
    {
        $this->travelTo(now()->setDate(2027, 1, 2)->setTime(1, 0));

        return app(CommissionSettlement::class)->settle('2026-12', 'royalty');
    }

    private function paidTo(User $partner): int
    {
        return Money::cents((string) DB::table('commission_settlement_payouts')->where('user_id', $partner->id)->where('kind', 'royalty')->sum('amount'));
    }

    /** @param array<string, string> $amounts @return array<string, string> */
    private function sorted(array $amounts): array
    {
        $order = array_flip(array_values(RankRoyaltyFunds::Categories));
        uksort($amounts, fn (string $a, string $b): int => $order[$a] <=> $order[$b]);

        return $amounts;
    }

    private function allRows(): Request
    {
        return Request::create('/x', 'GET', ['period' => '']);
    }

    private function assertBooksAddUp(): void
    {
        // The orders here are marked paid without a payment record, so the payment-receipt check is not one of ours.
        $checks = array_diff_key(app(FinancialReadiness::class)->inspect()['checks'], ['order_receipts' => true]);
        $this->assertSame([], FinancialReadiness::failedNames($checks));
        $this->assertTrue($checks['allocation_totals']);
        $this->assertTrue($checks['settlement_wallets']);
    }
}
