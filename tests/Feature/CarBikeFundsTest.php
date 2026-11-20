<?php

namespace Tests\Feature;

use App\Domain\Commissions\CommissionPolicy;
use App\Domain\Commissions\CommissionReversal;
use App\Domain\Commissions\CommissionService;
use App\Domain\Finance\CompanyFunds;
use App\Domain\Finance\FinancialReadiness;
use App\Domain\Finance\FundCatalog;
use App\Domain\Finance\ReserveService;
use App\Domain\Finance\TreasuryFunds;
use App\Domain\Finance\VehiclePools;
use App\Models\CommissionAllocation;
use App\Models\CommissionRuleParameter;
use App\Models\Order;
use App\Models\Product;
use App\Models\ReferralTreePath;
use App\Models\User;
use App\Support\AuditChain;
use App\Support\Money;
use Database\Seeders\CommissionRuleSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Tests\TestCase;

/** The car and bike money is two company funds, a Bike fund and a Car fund; the old combined fund keeps only rows posted before the pools were tagged. */
class CarBikeFundsTest extends TestCase
{
    use RefreshDatabase;

    private User $finance;

    private User $owner;

    private User $leader;

    private User $buyer;

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
        $this->buyer = User::factory()->create(['role' => 'dealer', 'status' => 'active', 'sponsor_id' => $this->leader->id]);
        ReferralTreePath::attachUser($this->buyer->id, $this->leader->id);
        $this->order = $this->deliveredOrder('ORD-CBF-1');
    }

    /** A delivered dealer order of 100.00 whose commission has been posted. */
    private function deliveredOrder(string $number): Order
    {
        $order = Order::create(['idempotency_key' => (string) Str::uuid(), 'payload_hash' => hash('sha256', $number), 'number' => $number,
            'user_id' => $this->buyer->id, 'status' => 'delivered', 'total' => '100.00', 'paid' => '100.00']);
        $product = Product::factory()->create(['mrp' => '200.00', 'dealer_price' => '100.00']);
        $order->lines()->create(['product_id' => $product->id, 'name' => $product->name, 'sku' => $product->sku, 'quantity' => 1, 'mrp' => $product->mrp, 'unit_price' => '100.00', 'total' => '100.00']);
        // The money was received in full, so the books' order-receipts check holds.
        $claim = DB::table('payment_claims')->insertGetId(['order_id' => $order->id, 'channel' => 'bank', 'reference' => 'R'.Str::random(10), 'amount' => '100.00', 'status' => 'verified', 'created_at' => now(), 'updated_at' => now()]);
        DB::table('receipts')->insert(['order_id' => $order->id, 'claim_id' => $claim, 'channel' => 'bank', 'reference' => 'R'.Str::random(10), 'amount' => '100.00', 'created_at' => now(), 'updated_at' => now()]);
        app(CommissionService::class)->processOrder($order);

        return $order;
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

    /** What an order's car and bike rows were before the pools were tagged: one row of the whole amount, no tag. */
    private function makeRowsOld(Order $order): void
    {
        $rows = CommissionAllocation::where('order_id', $order->id)->where('category', 'car_bike_incentive_fund')->get();
        $bike = $rows->first(fn ($row) => $row->metadata['vehicle_pool'] === 'bike');
        $rows->first(fn ($row) => $row->metadata['vehicle_pool'] === 'car')->delete();
        $metadata = $bike->metadata;
        unset($metadata['vehicle_pool']);
        $bike->update(['amount' => '2.00', 'original_amount' => '2.00', 'rate_percentage' => '2.00', 'metadata' => $metadata]);
    }

    /** @return array{money_in: int, money_out: int, pending: int, balance: int, available: int} */
    private function fund(string $key): array
    {
        return app(CompanyFunds::class)->balances()[$key];
    }

    /** @return array<string, int> what an award paid, per pool tag of the fund rows it drew on */
    private function drawnBy(int $disbursementId): array
    {
        $drawn = [];
        foreach (DB::table('commission_settlement_payouts')->where('kind', 'award_'.$disbursementId)->get() as $payout) {
            $tag = CommissionAllocation::findOrFail($payout->allocation_id)->metadata['vehicle_pool'] ?? 'untagged';
            $drawn[$tag] = ($drawn[$tag] ?? 0) + (int) round(((float) $payout->amount) * 100);
        }

        return $drawn;
    }

    private function assertBooksAddUp(): void
    {
        $checks = app(FinancialReadiness::class)->inspect()['checks'];
        $this->assertCount(18, $checks);
        $this->assertSame([], FinancialReadiness::failedNames($checks));
        $this->assertTrue(app(FinancialReadiness::class)->booksMatch());
        app(AuditChain::class)->seal();
        $this->assertTrue(app(AuditChain::class)->verify()['ok']);
        // The three car and bike funds hold exactly what the car and bike rows hold.
        $rows = CommissionAllocation::where('category', VehiclePools::Category)->where('status', '!=', 'reversed')->get();
        $held = array_sum(array_map(fn (string $key): int => $this->fund($key)['money_in'], ['bike', 'car', 'car_bike']));
        $this->assertSame((int) round($rows->sum(fn ($row) => (float) $row->amount) * 100), $held);
    }

    public function test_a_dealer_order_posts_the_bike_share_to_the_bike_fund_and_the_car_share_to_the_car_fund(): void
    {
        $rates = CommissionPolicy::vehiclePoolRates(CommissionPolicy::defaults());

        $this->assertSame(100, $this->fund('bike')['money_in']);
        $this->assertSame(100, $this->fund('car')['money_in']);
        $this->assertSame(0, $this->fund('car_bike')['money_in']);
        // The amounts are what the rule always paid: the two together are the whole car and bike percentage of the order.
        $this->assertSame(200, intdiv(Money::cents('100.00') * ($rates['bike']->basisPoints + $rates['car']->basisPoints), 10000));
        $oldSingleAmount = (string) DB::table('commission_allocations')->where('order_id', $this->order->id)->where('category', 'car_bike_incentive_fund')->sum('amount');
        $this->assertSame('2.00', number_format((float) $oldSingleAmount, 2, '.', ''));
        $this->assertSame(200, $this->fund('bike')['money_in'] + $this->fund('car')['money_in']);
        // Nothing about the rows changed: same category, same tag, same rates.
        $rows = CommissionAllocation::where('order_id', $this->order->id)->where('category', 'car_bike_incentive_fund')->get()->keyBy(fn ($row) => $row->metadata['vehicle_pool']);
        $this->assertSame('1.00', $rows['bike']->rate_percentage);
        $this->assertSame('1.00', $rows['car']->rate_percentage);
        $this->assertBooksAddUp();
    }

    public function test_the_treasury_shows_the_two_cards_next_to_each_other_and_no_old_card_while_there_is_no_old_money(): void
    {
        $cards = collect(app(TreasuryFunds::class)->cards(true));
        $keys = $cards->pluck('key')->values();

        $this->assertSame($keys->search('bike') + 1, $keys->search('car'));
        $this->assertNotContains('car_bike', $keys->all());
        $bike = $cards->firstWhere('key', 'bike');
        $this->assertSame(['money_in' => '1.00', 'money_out' => '0.00', 'balance' => '1.00', 'spendable' => false], array_intersect_key($bike, array_flip(['money_in', 'money_out', 'balance', 'spendable'])));
        $this->assertSame('Bike fund', FundCatalog::name('bike'));
        $this->assertSame('Car fund', FundCatalog::name('car'));
        $this->assertSame('বাইক ফান্ড', (string) __('treasury.fund_bike', [], 'bn'));
        $this->assertSame('কার ফান্ড', (string) __('treasury.fund_car', [], 'bn'));
        $this->assertSame('কার ও বাইক (পুরনো)', (string) __('treasury.fund_car_bike', [], 'bn'));

        $this->actingAs($this->owner)->onPortal('sadmin')->get('/treasury/funds/bike')->assertOk()
            ->assertInertia(fn ($page) => $page->where('card.key', 'bike')->where('card.name', 'Bike fund')->where('leaders.shown', ['bike'])
                ->where('leaders.pools.bike.own', '1.00')->where('leaders.pools.bike.waiting_count', 0)
                ->where('table.rows.data.0.type_label', __('treasury.type_bike_pool')));
        $this->actingAs($this->owner)->onPortal('sadmin')->get('/treasury/funds/car')->assertOk()
            ->assertInertia(fn ($page) => $page->where('card.key', 'car')->where('leaders.shown', ['car']));
        $this->actingAs($this->owner)->onPortal('sadmin')->get('/treasury/funds/car_bike')->assertNotFound();
    }

    public function test_a_bike_prize_is_paid_from_the_bike_fund_and_the_car_fund_stays_whole(): void
    {
        $id = $this->approve('bike', '1.00');

        $this->assertSame(['bike' => 100], $this->drawnBy($id));
        $this->assertSame(['money_in' => 100, 'money_out' => 100, 'balance' => 0], array_intersect_key($this->fund('bike'), array_flip(['money_in', 'money_out', 'balance'])));
        $this->assertSame(['money_in' => 100, 'money_out' => 0, 'balance' => 100], array_intersect_key($this->fund('car'), array_flip(['money_in', 'money_out', 'balance'])));
        $this->actingAs($this->owner)->onPortal('sadmin')->get('/treasury/funds/bike?view=out')->assertOk()
            ->assertInertia(fn ($page) => $page->has('table.rows.data', 1)->where('table.rows.data.0.amount', '1.00'));
        $this->actingAs($this->owner)->onPortal('sadmin')->get('/treasury/funds/car?view=out')->assertOk()
            ->assertInertia(fn ($page) => $page->has('table.rows.data', 0));

        $carId = $this->approve('car', '1.00');
        $this->assertSame(['car' => 100], $this->drawnBy($carId));
        $this->assertSame(100, $this->fund('car')['money_out']);
        $this->assertBooksAddUp();
    }

    public function test_a_bike_prize_larger_than_the_bike_fund_is_refused_and_never_takes_car_fund_money(): void
    {
        $this->setRule(['bike_award_amount' => '1.50']);
        $id = app(ReserveService::class)->request($this->finance, $this->award('bike', '1.50'));

        try {
            app(ReserveService::class)->review($this->owner, $id, 'approved', 'Approve the reward now');
            $this->fail('The bike prize must not use the Car fund.');
        } catch (ValidationException $exception) {
            $this->assertArrayHasKey('amount', $exception->errors());
        }

        $this->assertSame(100, $this->fund('car')['balance']);
        $this->assertSame(100, $this->fund('bike')['balance']);
        $this->assertDatabaseCount('commission_settlement_payouts', 0);
    }

    public function test_a_bike_prize_for_old_combined_money_is_still_paid_and_shows_on_the_old_card(): void
    {
        $this->makeRowsOld($this->order);

        $this->assertSame(['bike' => 0, 'car' => 0, 'car_bike' => 200], array_map(fn (string $key): int => $this->fund($key)['money_in'], array_combine(['bike', 'car', 'car_bike'], ['bike', 'car', 'car_bike'])));
        $id = $this->approve('bike', '1.00');

        $this->assertSame(['untagged' => 100], $this->drawnBy($id));
        $this->assertSame(['money_in' => 200, 'money_out' => 100, 'balance' => 100], array_intersect_key($this->fund('car_bike'), array_flip(['money_in', 'money_out', 'balance'])));
        $this->assertContains('car_bike', collect(app(TreasuryFunds::class)->cards(true))->pluck('key')->all());
        $card = collect(app(TreasuryFunds::class)->cards(true))->firstWhere('key', 'car_bike');
        $this->assertSame('Car and bike (old)', $card['name']);
        $this->actingAs($this->owner)->onPortal('sadmin')->get('/treasury/funds/car_bike')->assertOk()
            ->assertInertia(fn ($page) => $page->where('leaders.shown', ['bike', 'car'])->where('leaders.pools.bike.old', '0.00')->where('leaders.pools.car.old', '1.00'));
        $this->assertBooksAddUp();
    }

    public function test_a_prize_uses_its_own_fund_first_then_old_money_and_never_the_other_prize_fund(): void
    {
        $this->makeRowsOld($this->order);
        $this->deliveredOrder('ORD-CBF-2');
        $this->setRule(['bike_award_amount' => '1.50']);

        $this->assertSame(['bike' => 100, 'car' => 100, 'car_bike' => 200], array_map(fn (string $key): int => $this->fund($key)['money_in'], array_combine(['bike', 'car', 'car_bike'], ['bike', 'car', 'car_bike'])));
        $id = $this->approve('bike', '1.50');

        // 1.00 from the Bike fund first, the missing 0.50 from the old combined money, nothing from the Car fund.
        $this->assertSame(['bike' => 100, 'untagged' => 50], $this->drawnBy($id));
        $this->assertSame(100, $this->fund('bike')['money_out']);
        $this->assertSame(50, $this->fund('car_bike')['money_out']);
        $this->assertSame(0, $this->fund('car')['money_out']);
        $this->assertBooksAddUp();
    }

    public function test_a_refund_takes_back_the_same_share_from_the_bike_and_car_funds_and_the_funds_add_up(): void
    {
        $this->approve('bike', '1.00');
        $second = $this->deliveredOrder('ORD-CBF-2');
        app(CommissionReversal::class)->refund($second, 5000, 'Half of the order returned', 'CBF-REFUND');

        $this->assertSame(150, $this->fund('bike')['money_in']);
        $this->assertSame(150, $this->fund('car')['money_in']);
        $this->assertSame(0, $this->fund('car_bike')['money_in']);
        $this->assertBooksAddUp();
    }

    public function test_rows_posted_before_the_change_still_read_reverse_and_display(): void
    {
        $this->makeRowsOld($this->order);
        app(CommissionReversal::class)->refund($this->order, 5000, 'Half of the order returned', 'CBF-OLD-REFUND');

        // The old row loses half of its money and stays in the old fund; its pools still read half and half.
        $this->assertSame(100, $this->fund('car_bike')['money_in']);
        $this->assertSame(['bike' => 50, 'car' => 50], app(VehiclePools::class)->balances());
        $this->actingAs($this->owner)->onPortal('sadmin')->get('/treasury/funds/car_bike?view=in')->assertOk()
            ->assertInertia(fn ($page) => $page->has('table.rows.data', 1)->where('table.rows.data.0.amount', '1.00'));
        $this->assertBooksAddUp();
    }

    public function test_neither_new_fund_can_be_spent_by_hand(): void
    {
        foreach (['bike', 'car', 'car_bike'] as $fund) {
            $this->assertFalse(CompanyFunds::isSpendable($fund));
            $this->assertContains($fund, CompanyFunds::AwardOnly);
            $this->assertContains($fund, CompanyFunds::visibleKeys(false));
        }
    }
}
