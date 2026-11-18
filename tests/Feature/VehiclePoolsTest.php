<?php

namespace Tests\Feature;

use App\Domain\Commissions\CommissionPolicy;
use App\Domain\Commissions\CommissionReversal;
use App\Domain\Commissions\CommissionService;
use App\Domain\Finance\FinancialReadiness;
use App\Domain\Finance\ReserveService;
use App\Domain\Finance\RewardFundCheck;
use App\Domain\Finance\VehiclePools;
use App\Models\CommissionAllocation;
use App\Models\CommissionRuleParameter;
use App\Models\Order;
use App\Models\Product;
use App\Models\ReferralTreePath;
use App\Models\User;
use Database\Seeders\CommissionRuleSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Tests\TestCase;

/** MA-10 / owner decision D10: the bike and car prizes draw on separate pools, so a bike prize never spends the car money. */
class VehiclePoolsTest extends TestCase
{
    use RefreshDatabase;

    private User $finance;

    private User $owner;

    private User $leader;

    private Order $order;

    protected function setUp(): void
    {
        parent::setUp();
        $this->withoutVite();
        $this->seed(CommissionRuleSeeder::class);
        $this->finance = User::factory()->staff('finance')->create();
        $this->owner = User::factory()->staff('super_admin')->create();
        $this->enableFinancialPosting();
        $this->setRule(['bike_target' => '100.00', 'bike_leg_cap' => '100.00', 'car_target' => '100.00', 'car_leg_cap' => '100.00',
            'bike_award_amount' => '1.00', 'car_award_amount' => '1.00']);

        $this->leader = User::factory()->create(['role' => 'dealer', 'status' => 'active']);
        ReferralTreePath::attachUser($this->leader->id, null);
        $buyer = User::factory()->create(['role' => 'dealer', 'status' => 'active', 'sponsor_id' => $this->leader->id]);
        ReferralTreePath::attachUser($buyer->id, $this->leader->id);
        $this->order = Order::create(['idempotency_key' => (string) Str::uuid(), 'payload_hash' => hash('sha256', 'pools'), 'number' => 'ORD-POOLS',
            'user_id' => $buyer->id, 'status' => 'delivered', 'total' => '100.00', 'paid' => '100.00']);
        $product = Product::factory()->create(['mrp' => '200.00', 'dealer_price' => '100.00']);
        $this->order->lines()->create(['product_id' => $product->id, 'name' => $product->name, 'sku' => $product->sku, 'quantity' => 1, 'mrp' => $product->mrp, 'unit_price' => '100.00', 'total' => '100.00']);
        app(CommissionService::class)->processOrder($this->order);
    }

    /** @param array<string, string|null> $parameters */
    private function setRule(array $parameters): void
    {
        $rule = CommissionRuleParameter::getRule('car_bike_incentive');
        $rule?->update(['is_active' => true, 'parameters' => [...($rule->parameters ?? []), ...$parameters]]);
    }

    /** @return array<string, mixed> */
    private function award(string $program, string $amount): array
    {
        return ['program' => $program, 'user_id' => $this->leader->id, 'amount' => $amount, 'request_key' => (string) Str::uuid(), 'reason' => 'Target reached this month', 'payee_name' => null];
    }

    private function approve(string $program, string $amount): int
    {
        $id = app(ReserveService::class)->request($this->finance, $this->award($program, $amount));
        app(ReserveService::class)->review($this->owner, $id, 'approved', 'Approve the reward now');

        return $id;
    }

    /** @return array{bike: int, car: int} */
    private function pools(): array
    {
        return app(VehiclePools::class)->balances();
    }

    /** @return array<string, int> what the award paid, per pool tag of the fund rows it drew on */
    private function drawnBy(int $disbursementId): array
    {
        $drawn = [];
        foreach (DB::table('commission_settlement_payouts')->where('kind', 'award_'.$disbursementId)->get() as $payout) {
            $tag = CommissionAllocation::findOrFail($payout->allocation_id)->metadata['vehicle_pool'] ?? 'untagged';
            $drawn[$tag] = ($drawn[$tag] ?? 0) + (int) round(((float) $payout->amount) * 100);
        }

        return $drawn;
    }

    public function test_a_new_order_puts_half_of_the_two_percent_in_each_pool(): void
    {
        $rows = CommissionAllocation::where('order_id', $this->order->id)->where('category', 'car_bike_incentive_fund')->get()->keyBy(fn ($row) => $row->metadata['vehicle_pool']);

        $this->assertSame(['bike', 'car'], $rows->keys()->sort()->values()->all());
        $this->assertSame('1.00', $rows['bike']->rate_percentage);
        $this->assertSame('1.00', $rows['car']->rate_percentage);
        $this->assertSame('1.00', $rows['bike']->amount);
        $this->assertSame('1.00', $rows['car']->amount);
        $this->assertSame(['bike' => 100, 'car' => 100], $this->pools());
    }

    public function test_the_split_follows_the_rule_and_always_adds_up_to_the_reserve_rate(): void
    {
        $defaults = CommissionPolicy::vehiclePoolRates(CommissionPolicy::defaults());
        $this->assertSame(['1.00', '1.00'], [$defaults['bike']->decimal(), $defaults['car']->decimal()]);

        $rules = CommissionPolicy::defaults();
        $rules['car_bike_incentive']['parameters']['bike_pool_share_pct'] = '25.00';
        $quarter = CommissionPolicy::vehiclePoolRates($rules);
        $this->assertSame(['0.50', '1.50'], [$quarter['bike']->decimal(), $quarter['car']->decimal()]);

        $rules['car_bike_incentive']['percentage'] = '2.50';
        $rules['car_bike_incentive']['parameters']['bike_pool_share_pct'] = '33.33';
        $odd = CommissionPolicy::vehiclePoolRates($rules);
        $this->assertSame(250, $odd['bike']->basisPoints + $odd['car']->basisPoints);

        $this->actingAs($this->owner);
        app(CommissionPolicy::class)->update($this->owner, ['car_bike_incentive' => ['parameters' => ['bike_pool_share_pct' => '25.00']]]);
        $this->assertSame('25.00', app(CommissionPolicy::class)->snapshot()['rules']['car_bike_incentive']['parameters']['bike_pool_share_pct']);
        $check = app(RewardFundCheck::class)->check();
        $this->assertSame('0.50', $check['bike']['rate']);
        $this->assertSame('1.50', $check['car']['rate']);
    }

    public function test_a_bike_prize_draws_only_on_the_bike_pool(): void
    {
        $id = $this->approve('bike', '1.00');

        $this->assertSame(['bike' => 100], $this->drawnBy($id));
        $this->assertSame(['bike' => 0, 'car' => 100], $this->pools());
    }

    public function test_a_car_prize_draws_only_on_the_car_pool(): void
    {
        $id = $this->approve('car', '1.00');

        $this->assertSame(['car' => 100], $this->drawnBy($id));
        $this->assertSame(['bike' => 100, 'car' => 0], $this->pools());
    }

    public function test_a_bike_prize_larger_than_the_bike_pool_is_refused_and_the_car_pool_stays_whole(): void
    {
        $this->setRule(['bike_award_amount' => '1.50']);
        $id = app(ReserveService::class)->request($this->finance, $this->award('bike', '1.50'));

        try {
            app(ReserveService::class)->review($this->owner, $id, 'approved', 'Approve the reward now');
            $this->fail('The bike prize must not take the car money.');
        } catch (ValidationException $exception) {
            $this->assertSame([__('money_core.vehicle_pool_short', ['program' => __('portal.prc_reserve_program_bike')])], $exception->errors()['amount']);
        }

        $this->assertSame(['bike' => 100, 'car' => 100], $this->pools());
        $this->assertDatabaseHas('reserve_disbursements', ['id' => $id, 'status' => 'requested']);
        $this->assertDatabaseCount('commission_settlement_payouts', 0);
    }

    public function test_a_car_prize_cannot_be_asked_for_or_approved_while_the_car_amount_is_blank(): void
    {
        $this->setRule(['car_award_amount' => null]);
        $blank = [__('portal.err_reserve_rule_amount_blank', ['program' => __('portal.prc_reserve_program_car')])];

        try {
            app(ReserveService::class)->request($this->finance, $this->award('car', '1.00'));
            $this->fail('A car prize with no amount in the rule must be refused.');
        } catch (ValidationException $exception) {
            $this->assertSame($blank, $exception->errors()['amount']);
        }

        // A request made before the amount was cleared cannot be approved either.
        $id = DB::table('reserve_disbursements')->insertGetId(['program' => 'car', 'user_id' => $this->leader->id, 'amount' => '1.00', 'status' => 'requested',
            'request_key' => (string) Str::uuid(), 'reason' => 'Older request', 'created_by' => $this->finance->id, 'created_at' => now(), 'updated_at' => now()]);
        try {
            app(ReserveService::class)->review($this->owner, $id, 'approved', 'Approve the reward now');
            $this->fail('A car prize with no amount in the rule must not be approved.');
        } catch (ValidationException $exception) {
            $this->assertSame($blank, $exception->errors()['amount']);
        }
        $this->assertSame(['bike' => 100, 'car' => 100], $this->pools());
    }

    public function test_the_bike_prize_is_refused_the_same_way_while_its_amount_is_blank(): void
    {
        $this->setRule(['bike_award_amount' => null]);

        $this->expectException(ValidationException::class);
        app(ReserveService::class)->request($this->finance, $this->award('bike', '1.00'));
    }

    public function test_a_refund_takes_back_the_same_share_from_both_pools(): void
    {
        app(CommissionReversal::class)->refund($this->order, 5000, 'Half of the order returned', 'POOL-REFUND');

        $this->assertSame(['bike' => 50, 'car' => 50], $this->pools());
        $this->assertTrue(app(FinancialReadiness::class)->inspect()['checks']['allocation_totals']);
    }

    public function test_the_posting_still_reconciles_with_two_fund_rows(): void
    {
        $this->assertTrue(app(FinancialReadiness::class)->inspect()['checks']['allocation_totals']);
        $this->assertTrue(app(FinancialReadiness::class)->inspect()['checks']['funded_batches']);
        $this->assertSame('2.00', number_format((float) DB::table('commission_allocations')->where('order_id', $this->order->id)->where('category', 'car_bike_incentive_fund')->sum('amount'), 2, '.', ''));
    }

    /** Rows posted before the split carry no tag: each counts half bike and half car, and nothing in the old rows is changed. */
    private function makeOrderRowsOld(): void
    {
        $bike = CommissionAllocation::where('order_id', $this->order->id)->where('category', 'car_bike_incentive_fund')->get()->first(fn ($row) => $row->metadata['vehicle_pool'] === 'bike');
        CommissionAllocation::where('order_id', $this->order->id)->where('category', 'car_bike_incentive_fund')->get()->first(fn ($row) => $row->metadata['vehicle_pool'] === 'car')->delete();
        $metadata = $bike->metadata;
        unset($metadata['vehicle_pool']);
        $bike->update(['amount' => '2.00', 'original_amount' => '2.00', 'rate_percentage' => '2.00', 'metadata' => $metadata]);
    }

    public function test_an_old_untagged_row_counts_half_for_each_pool_and_the_answer_never_changes(): void
    {
        $this->makeOrderRowsOld();

        $this->assertSame(['bike' => 100, 'car' => 100], $this->pools());
        $this->assertSame(['bike' => 100, 'car' => 100], $this->pools());
        $this->assertSame('2.00', CommissionAllocation::where('category', 'car_bike_incentive_fund')->first()->amount);
    }

    public function test_a_prize_from_an_old_row_is_limited_to_its_own_half(): void
    {
        $this->makeOrderRowsOld();
        $id = $this->approve('bike', '1.00');

        $this->assertSame(['untagged' => 100], $this->drawnBy($id));
        $this->assertSame(['bike' => 0, 'car' => 100], $this->pools());

        $this->setRule(['car_award_amount' => '1.50']);
        $carId = app(ReserveService::class)->request($this->finance, $this->award('car', '1.50'));
        try {
            app(ReserveService::class)->review($this->owner, $carId, 'approved', 'Approve the reward now');
            $this->fail('The car pool only holds its own half of the old row.');
        } catch (ValidationException $exception) {
            $this->assertArrayHasKey('amount', $exception->errors());
        }
    }

    public function test_when_a_bike_prize_once_took_car_money_from_an_old_row_the_pools_still_add_up_to_the_row(): void
    {
        $this->makeOrderRowsOld();
        $row = CommissionAllocation::where('category', 'car_bike_incentive_fund')->firstOrFail();
        $id = DB::table('reserve_disbursements')->insertGetId(['program' => 'bike', 'user_id' => $this->leader->id, 'amount' => '1.50', 'status' => 'credited',
            'request_key' => (string) Str::uuid(), 'reason' => 'Paid before the split', 'created_by' => $this->finance->id, 'created_at' => now(), 'updated_at' => now()]);
        DB::table('commission_settlement_payouts')->insert(['allocation_id' => $row->id, 'user_id' => $this->leader->id, 'kind' => 'award_'.$id, 'period_month' => '2026-11',
            'amount' => '1.50', 'refunded_basis' => '0.00', 'created_at' => now(), 'updated_at' => now()]);

        $this->assertSame(['bike' => 0, 'car' => 50], $this->pools());
    }

    public function test_finance_sees_each_pool_and_how_many_prizes_it_can_pay(): void
    {
        $this->setRule(['bike_award_amount' => '0.30', 'car_award_amount' => null]);
        $summary = app(VehiclePools::class)->summary();

        $this->assertSame(['balance' => '1.00', 'award' => '0.30', 'prizes_payable' => 3], $summary['bike']);
        $this->assertSame(['balance' => '1.00', 'award' => null, 'prizes_payable' => null], $summary['car']);

        $this->actingAs($this->owner)->onPortal('sadmin')->get('/treasury/funds/car_bike?view=bike')->assertOk()
            ->assertInertia(fn ($page) => $page->where('leaders.pools.bike.balance', '1.00')->where('leaders.pools.bike.prizes_payable', 3)
                ->where('leaders.pools.car.award', null));
    }

    public function test_the_partner_sees_the_prize_target_deadline_cap_and_how_to_claim(): void
    {
        $this->setRule(['car_award_amount' => null]);
        $rewards = collect($this->actingAs($this->leader)->onPortal('dealer')->getJson('/my-progress')->assertOk()->json('data.rewards'))->keyBy('type');

        $this->assertSame('1.00', $rewards['bike']['award']);
        $this->assertNull($rewards['car']['award']);
        $this->assertSame('100.00', $rewards['bike']['target']);
        $this->assertSame('100.00', $rewards['bike']['line_cap']);
        foreach (['pr_prize', 'pr_prize_unset', 'pr_target', 'pr_deadline', 'pr_deadline_none', 'pr_cap_example', 'pr_claim', 'vp_title', 'vp_payable', 'vehicle_pool_short', 'cs_param_bike_pool_share_pct'] as $key) {
            foreach (['bn', 'en'] as $locale) {
                $this->assertNotSame('money_core.'.$key, __('money_core.'.$key, [], $locale));
            }
        }
    }
}
