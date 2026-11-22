<?php

namespace Tests\Feature;

use App\Domain\Accounts\PartnerDashboardHome;
use App\Domain\Accounts\PartnerPendingMoney;
use App\Domain\Commissions\CommissionPolicy;
use App\Domain\Commissions\CommissionPosting;
use App\Domain\Commissions\CommissionSettlement;
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

    /**
     * Since the owner's rule of 2026-11-21 the area target is the dealer's one monthly target by kind (a division
     * dealer: the division target), not the seat's own quota, so the tests set that number.
     */
    private function seatedDealer(string $target = '100000.00'): User
    {
        app(CommissionPolicy::class)->update(User::factory()->staff('super_admin')->create(), ['dealer_monthly_salary' => ['parameters' => ['division_target' => $target]]]);
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

    /**
     * The month-end group's lines of one head kind ("area_" for the area shares), as the wallet page receives them.
     *
     * @param  array<string, mixed>  $pending
     * @return list<array<string, mixed>>
     */
    private function monthEnd(array $pending, string $headPrefix): array
    {
        return array_values(array_filter($pending['month_end'], fn (array $line): bool => str_starts_with($line['head'], $headPrefix)));
    }

    /** @return array<string, mixed> */
    private function pendingPage(User $partner): array
    {
        return $this->actingAs($partner)->onPortal($partner->role === 'sub_dealer' ? 'subdealer' : 'dealer')->get('/my-money')->assertOk()->viewData('page')['props']['pending'];
    }

    public function test_an_area_share_waiting_for_the_target_is_pending_with_its_reason_and_not_in_the_balance(): void
    {
        $dealer = $this->seatedDealer();
        $share = $this->areaShare($this->purchase($this->buyer()));
        $this->assertSame('held', $share->status);

        $this->myMoney($dealer, fn (Assert $page) => $page
            ->where('pending.total', (string) $share->amount)
            ->where('pending.count', 1)
            // A new order's area share is a month-end item, listed with the salary and royalty, not as an older hold.
            ->where('pending.lines', [])
            ->where('summary.available', '0.00')
            ->where('summary.lifetime.earned', '0.00')
            ->where('held_commission.lines', []));
        $area = $this->monthEnd($this->pendingPage($dealer), 'area_');
        $this->assertSame([['area_division', 'month_end_area', (string) $share->amount, false]], array_map(fn (array $line): array => [$line['head'], $line['reason'], $line['amount'], $line['estimate']], $area));
        $this->assertSame('0.00', $this->available($dealer));
    }

    public function test_an_older_orders_area_share_keeps_the_release_when_met_wording(): void
    {
        $dealer = $this->seatedDealer('100.00');
        $share = $this->areaShare($this->purchase($this->buyer()));
        // An order posted before the owner's rule of 2026-11-21 has no monthly_close flag: it is released once the target is met.
        $metadata = $share->metadata;
        unset($metadata['monthly_close']);
        $share->update(['metadata' => $metadata]);

        $this->myMoney($dealer, fn (Assert $page) => $page->where('pending.lines.0.id', 'area-'.$share->id)->where('pending.lines.0.reason', 'target_not_met'));
        $this->purchase($dealer);
        $this->myMoney($dealer, fn (Assert $page) => $page->where('pending.lines.0.id', 'area-'.$share->id)->where('pending.lines.0.reason', 'target_met_waiting'));
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

        // A new order's share waits for the month-end day even with the target met, and says so. The dealer's bonus has
        // no amount, so the pending total is exactly the area shares.
        $pending = $this->pendingPage($dealer);
        $area = $this->monthEnd($pending, 'area_');
        $this->assertNotEmpty($area);
        $this->assertSame(['month_end_area_met'], array_values(array_unique(array_column($area, 'reason'))));
        $this->assertSame($held, number_format(array_sum(array_map(fn (array $line): float => (float) $line['amount'], $area)), 2, '.', ''));
        $this->assertSame($held, $pending['total']);
        $this->assertSame([], $pending['lines']);
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
            ->where('pending.month_end', [])
            ->where('pending.paid.0.amount', (string) $share->amount)
            ->where('not_earned_area', []));
    }

    /**
     * Owner's rule of 2026-11-21: a month's area shares are decided once, when the month ends. A month that never meets
     * the target is lost at its month end and never paid later; a month that meets it is paid in full at its close,
     * including shares posted after the target was reached. November is the missed month, December meets the target
     * on the 15th.
     */
    public function test_only_the_month_that_meets_its_target_is_released_and_a_missed_month_is_never_pending_again(): void
    {
        config(['madina.auto_release_quota_holds' => true]);
        $dealer = $this->seatedDealer('100.00');
        $november = [$this->areaShare($this->purchase($this->buyer()))];
        $this->travelTo(now()->setDate(2026, 11, 20)->startOfDay());
        $november[] = $this->areaShare($this->purchase($this->buyer()));
        $novemberTotal = number_format((float) $november[0]->amount + (float) $november[1]->amount, 2, '.', '');

        // Month ended, close not run yet: already not pending, shown as not received.
        $this->travelTo(now()->setDate(2026, 12, 1)->setTime(0, 30));
        $this->assertSame('held', $november[0]->fresh()->status);
        $this->myMoney($dealer, fn (Assert $page) => $page
            ->where('pending.total', '0.00')->where('pending.lines', [])
            ->where('not_earned_area.0.month', '2026-11')->where('not_earned_area.0.amount', $novemberTotal));

        $this->artisan('finance:auto-approve')->assertSuccessful();
        foreach ($november as $share) {
            $this->assertSame('forfeited', $share->fresh()->status);
        }

        $this->travelTo(now()->setDate(2026, 12, 3)->startOfDay());
        $december = [$this->areaShare($this->purchase($this->buyer()))];
        $this->travelTo(now()->setDate(2026, 12, 8)->startOfDay());
        $december[] = $this->areaShare($this->purchase($this->buyer()));
        $this->artisan('finance:release-quota-holds')->assertSuccessful();
        foreach ($december as $share) {
            $this->assertSame('held', $share->fresh()->status);
        }
        $decemberTotal = number_format((float) $december[0]->amount + (float) $december[1]->amount, 2, '.', '');
        $this->myMoney($dealer, fn (Assert $page) => $page
            ->where('pending.total', $decemberTotal)->where('pending.count', 2)
            ->where('not_earned_area.0.month', '2026-11'));

        // The dealer reaches the target on the 15th: December's shares are still held, now only waiting for the month end.
        $this->travelTo(now()->setDate(2026, 12, 15)->startOfDay());
        $own = $this->areaShare($this->purchase($dealer));
        $this->artisan('finance:release-quota-holds')->assertSuccessful();
        foreach ([...$december, $own] as $share) {
            $this->assertSame('held', $share->fresh()->status);
        }
        $this->myMoney($dealer, fn (Assert $page) => $page->where('pending.lines', [])->where('pending.paid', []));
        $this->assertSame(['month_end_area_met'], array_values(array_unique(array_column($this->monthEnd($this->pendingPage($dealer), 'area_'), 'reason'))));

        // A later order of December is held too: nothing is paid straight in any more.
        $this->travelTo(now()->setDate(2026, 12, 16)->startOfDay());
        $later = $this->areaShare($this->purchase($this->buyer()));
        $this->assertSame('held', $later->status);
        $this->assertSame(0, DB::table('journal_entries')->where('event_key', 'like', 'commission.hold_release.%')->count());

        // The close of December pays every one of its shares once; November stays lost and is never moved twice.
        $this->travelTo(now()->setDate(2027, 1, 1)->setTime(1, 0));
        $this->artisan('finance:auto-approve')->assertSuccessful();
        $this->artisan('finance:auto-approve')->assertSuccessful();
        foreach ([...$december, $own, $later] as $share) {
            $this->assertContains($share->fresh()->status, ['available', 'maturing']);
            $this->assertSame(1, DB::table('journal_entries')->where('event_key', 'commission.hold_release.'.$share->id)->count());
        }
        $this->myMoney($dealer, fn (Assert $page) => $page
            ->where('pending.total', '0.00')->where('pending.lines', [])
            ->where('pending.paid', fn ($paid) => collect($paid)->pluck('amount')->sort()->values()->all() === collect([...$december, $own, $later])->map(fn ($share) => (string) $share->amount)->sort()->values()->all())
            ->where('not_earned_area.0.month', '2026-11')->where('not_earned_area.0.amount', $novemberTotal));
        foreach ($november as $share) {
            $this->assertSame('forfeited', $share->fresh()->status);
            $this->assertSame(1, CommissionAllocation::where('metadata->source_allocation_id', $share->id)->count());
            $this->assertSame(0, DB::table('journal_entries')->where('event_key', 'commission.hold_release.'.$share->id)->count());
        }
        $this->myMoney($dealer, fn (Assert $page) => $page->where('pending.total', '0.00')->where('not_earned_area.0.month', '2026-11'));
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
        $this->assertSame([], $pending['lines']);
        // Only the area share carries an amount; the dealer's bonus line has none and no royalty line is shown without a rank.
        $withAmount = array_values(array_filter($pending['month_end'], fn (array $line): bool => $line['amount'] !== null));
        $this->assertSame(['area_division'], array_column($withAmount, 'head'));
        $this->assertSame(['salary'], array_column(array_values(array_filter($pending['month_end'], fn (array $line): bool => $line['amount'] === null)), 'head'));
    }

    public function test_a_sub_dealers_salary_is_pending_until_the_month_close_pays_it(): void
    {
        // ৳40,000 in the month reaches the top salary step (3%), so the whole amount set aside is his.
        $subDealer = User::factory()->subDealer()->inPlace($this->division)->create();
        $this->purchase($subDealer, '42000.00', '70000.00');
        $set = number_format((float) DB::table('commission_allocations')->where('user_id', $subDealer->id)->where('category', 'sub_dealer_salary')->where('status', 'pending')->sum('amount'), 2, '.', '');
        $this->assertSame('1260.00', $set);
        // The sub-dealer's own cashback is already in the wallet; the salary is not.
        $before = $this->available($subDealer);

        // His salary depends only on his own purchases, so he keeps seeing his amount (an estimate) and the footnote.
        $this->myMoney($subDealer, fn (Assert $page) => $page
            ->where('pending.total', $set)
            ->where('pending.role', 'sub_dealer')
            ->where('pending.lines', [])
            ->where('pending.month_end.0.head', 'salary')
            ->where('pending.month_end.0.amount', $set)
            ->where('pending.month_end.0.reason', 'month_end')
            ->where('pending.month_end.0.estimate', true)
            ->where('pending.has_estimate', true)
            ->where('summary.available', $before));

        $this->closeNovember();

        $after = number_format((float) $before + (float) $set, 2, '.', '');
        $this->assertSame($after, $this->available($subDealer));
        $this->myMoney($subDealer, fn (Assert $page) => $page
            ->where('pending.total', '0.00')
            ->where('pending.lines', [])
            ->where('pending.month_end', [])
            ->where('summary.available', $after));
    }

    public function test_a_sub_dealer_sees_no_area_line(): void
    {
        $subDealer = User::factory()->subDealer()->inPlace($this->division)->create();
        $order = $this->purchase($subDealer, '15000.00', '25000.00');
        // Area income is a dealer's only: even a stray held area row in his name is not listed for him.
        DB::table('commission_allocations')->insert([
            'order_id' => $order->id, 'user_id' => $subDealer->id, 'category' => 'area_union', 'rate_percentage' => '2.00', 'cash_basis' => '100.00',
            'amount' => '55.00', 'status' => 'held', 'created_at' => now(), 'updated_at' => now(),
            'metadata' => json_encode(['hold_reason' => 'quota', 'period_month' => '2026-11', 'monthly_quota' => '30000.00', 'monthly_close' => true], JSON_THROW_ON_ERROR),
        ]);

        $pending = $this->pendingPage($subDealer);

        $this->assertSame([], $pending['lines']);
        $this->assertSame([], $this->monthEnd($pending, 'area_'));
        $this->assertSame('150.00', $pending['total']);
    }

    public function test_a_sub_dealer_is_shown_and_paid_only_the_salary_step_his_month_reached(): void
    {
        // ৳15,000 reaches the first step (1% of 3%): a third of the ৳450 set aside is his, the rest goes to company profit.
        $subDealer = User::factory()->subDealer()->inPlace($this->division)->create();
        $this->purchase($subDealer, '15000.00', '25000.00');
        $set = (float) DB::table('commission_allocations')->where('user_id', $subDealer->id)->where('category', 'sub_dealer_salary')->where('status', 'pending')->sum('amount');
        $this->assertSame('450.00', number_format($set, 2, '.', ''));
        $before = $this->available($subDealer);

        $this->myMoney($subDealer, fn (Assert $page) => $page
            ->where('pending.total', '150.00')->where('pending.month_end.0.amount', '150.00')->where('pending.month_end.0.estimate', true));

        $this->closeNovember();

        $this->assertSame(number_format((float) $before + 150, 2, '.', ''), $this->available($subDealer));
        $profit = CommissionAllocation::where('category', 'company_profit_fund')->where('metadata->reason_key', 'retained_reason_sub_dealer_salary_tier')->sole();
        $this->assertSame('300.00', (string) $profit->amount);
        $this->myMoney($subDealer, fn (Assert $page) => $page->where('pending.total', '0.00')->where('pending.lines', []));
    }

    /**
     * Owner's rule of 2026-11-22: the Dealer Acquisition Target Bonus is one nationwide fund shared equally by every dealer who
     * qualifies in the month, so a dealer is shown no amount and no estimate before the month closes, not in a line and
     * not in the pending total. The month close still pays his share as before.
     */
    public function test_a_dealers_bonus_shows_no_amount_or_estimate_until_the_month_close_pays_it(): void
    {
        // A dealer with no seat and no appointed place has the general target (৳30,000).
        $dealer = User::factory()->create();
        $this->purchase($dealer, '30000.00');
        $share = collect(app(CommissionSettlement::class)->salaryPlan('2026-11')['dealers'])->firstWhere('user_id', $dealer->id)['share'];
        $this->assertGreaterThan(0, (float) $share, 'He qualifies, so the close will pay him a share.');

        $pending = app(PartnerPendingMoney::class)->for($dealer);
        $this->assertSame([], $pending['lines']);
        $this->assertSame([['salary', null, 'salary_shared_month_end', false]], array_map(fn (array $line): array => [$line['head'], $line['amount'], $line['reason'], $line['estimate']], $pending['month_end']));
        $this->assertFalse($pending['has_estimate']);
        $this->assertSame('0.00', $pending['total']);
        $this->assertSame(0, $pending['count']);
        // The same on the wallet page and the home screen data.
        $this->myMoney($dealer, fn (Assert $page) => $page->where('pending.total', '0.00')->where('pending.has_estimate', false)->where('pending.month_end.0.amount', null));
        $this->assertSame('0.00', app(PartnerDashboardHome::class)->for($dealer)['wallet']['pending']['total']);
        // The dealer's own cashback is already in the wallet; the bonus is not.
        $before = $this->available($dealer);

        $this->closeNovember();

        $this->assertSame([], app(PartnerPendingMoney::class)->for($dealer)['month_end']);
        $this->assertSame(number_format((float) $before + (float) $share, 2, '.', ''), $this->available($dealer));
    }

    public function test_a_partner_with_nothing_pending_sees_nothing_new(): void
    {
        $dealer = User::factory()->dealer()->create();
        $subDealer = User::factory()->subDealer()->create();

        foreach ([$dealer, $subDealer] as $partner) {
            $this->myMoney($partner, fn (Assert $page) => $page
                ->where('pending.total', '0.00')->where('pending.count', 0)->where('pending.lines', [])->where('pending.month_end', [])->where('pending.paid', [])
                ->where('not_earned_area', [])->where('held_commission.lines', []));
        }
    }

    public function test_the_month_end_group_has_the_owners_title_and_plain_reasons_in_both_languages(): void
    {
        $bn = require lang_path('bn/my_money.php');
        $this->assertSame('মাস শেষে হিসাব হবে (একসাথে, একই দিনে)', $bn['month_end_heading']);
        $this->assertStringContainsString('এই তিনটি প্রতি মাসের শেষে একই দিনে হিসাব হয়; যাঁরা শর্ত পূরণ করেন শুধু তাঁরাই পান', $bn['month_end_intro']);
        $this->assertSame('মাস শেষে হিসাব হবে। মাসিক টার্গেট পূরণ করলে ব্যালেন্সে জমা হবে; না করলে কোম্পানির ফান্ডে যাবে।', $bn['pending_reason_month_end_area']);
        $this->assertStringNotContainsString('শীঘ্রই', $bn['pending_reason_month_end_area_met'], 'A new order\'s share is paid on the month-end day, not "soon".');
        foreach (['bn', 'en'] as $locale) {
            $words = require lang_path($locale.'/my_money.php');
            foreach (['month_end_heading', 'month_end_intro', 'month_end_intro_sub', 'month_end_no_amount', 'pending_reason_month_end_area', 'pending_reason_month_end_area_met', 'pending_reason_salary_shared_month_end', 'pending_reason_royalty_month_end', 'pending_reason_month_end'] as $key) {
                $this->assertNotSame('', trim((string) ($words[$key] ?? '')), "{$locale}/my_money.{$key}");
            }
        }
        // Both money screens draw the month-end group from these words.
        foreach (['components/mymoney/PendingMoney.tsx', 'components/PartnerDashboard.tsx'] as $file) {
            $source = (string) file_get_contents(resource_path('js/'.$file));
            $this->assertStringContainsString("'month_end_heading'", $source, $file);
            $this->assertStringContainsString("'month_end_no_amount'", $source, $file);
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
            ->where('pending.month_end', fn ($lines) => collect($lines)->firstWhere('head', 'area_division')['reason'] === 'month_end_area'));
    }
}
