<?php

namespace Tests\Feature;

use App\Domain\Accounts\PartnerCertificates;
use App\Domain\Accounts\PartnerFullReport;
use App\Domain\Commissions\CommissionPosting;
use App\Domain\Commissions\CommissionService;
use App\Domain\Finance\ReserveService;
use App\Domain\Orders\OrderService;
use App\Models\AreaDealerAssignment;
use App\Models\CommissionAllocation;
use App\Models\Order;
use App\Models\PlaceCode;
use App\Models\Product;
use App\Models\User;
use Database\Seeders\CommissionRuleSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Tests\TestCase;

/**
 * Owner rule: every month closes in that month. Money nobody earned goes to the company
 * profit fund with its source; only the rank reward reserve, the sub-dealer tour fund and
 * the bike/car fund roll over.
 */
class MonthlyCloseProfitFundTest extends TestCase
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

    private function purchase(User $buyer, string $cash = '100.00', string $mrp = '200.00'): Order
    {
        $product = Product::factory()->create(['mrp' => $mrp, 'dealer_price' => $cash, 'sub_dealer_price' => $buyer->role === 'sub_dealer' ? $cash : null]);
        $order = app(OrderService::class)->create($buyer, ['items' => [['product_id' => $product->id, 'quantity' => 1]], 'idempotency_key' => (string) Str::uuid()]);
        $order->update(['status' => 'delivered', 'paid' => $cash]);
        app(CommissionPosting::class)->process($order->fresh());

        return $order;
    }

    private function seat(User $dealer, string $quota = '100.00'): AreaDealerAssignment
    {
        return AreaDealerAssignment::create(['user_id' => $dealer->id, 'level' => 'division', 'place_code_id' => $this->division->id,
            'division' => 'Rangpur', 'zone_name' => 'Rangpur', 'monthly_quota' => $quota, 'is_active' => true]);
    }

    private function divisionShare(Order $order): CommissionAllocation
    {
        return CommissionAllocation::where('order_id', $order->id)->where('category', 'area_division')->sole();
    }

    public function test_an_area_level_with_no_seat_is_company_profit_at_order_time_with_its_source(): void
    {
        $order = $this->purchase(User::factory()->inPlace($this->division)->create());

        $share = $this->divisionShare($order);
        $this->assertNull($share->user_id);
        $this->assertSame('company_profit_fund', $share->metadata['retained_by']);
        $this->assertSame('retained_reason_profit_no_area_seat', $share->metadata['reason_key']);
        $this->assertSame('area_division', $share->metadata['source_category']);
        $this->assertSame($this->division->id, $share->metadata['place_code_id']);
        $this->assertSame('2026-11', $share->metadata['period_month']);
    }

    public function test_a_dealer_seated_mid_month_earns_only_on_orders_after_the_seat(): void
    {
        $buyer = User::factory()->inPlace($this->division)->create();
        $before = $this->purchase($buyer);
        $dealer = User::factory()->inPlace($this->division)->create();
        $this->seat($dealer, '0.00');
        $after = $this->purchase($buyer);

        $this->assertSame('company_profit_fund', $this->divisionShare($before)->metadata['retained_by']);
        $this->assertSame($dealer->id, $this->divisionShare($after)->user_id);
        $this->assertSame('available', $this->divisionShare($after)->status);
    }

    public function test_a_missed_quota_goes_to_company_profit_at_month_close_and_a_rerun_moves_nothing(): void
    {
        $dealer = User::factory()->inPlace($this->division)->create();
        $this->seat($dealer, '100000.00');
        $order = $this->purchase(User::factory()->inPlace($this->division)->create());
        $hold = $this->divisionShare($order);
        $this->assertSame('held', $hold->status);
        $this->assertSame('quota', $hold->metadata['hold_reason']);

        $this->assertSame('month_open', app(ReserveService::class)->forfeitUnmetQuotaHolds('2026-11')['status']);
        $this->travelTo(now()->setDate(2026, 12, 1)->setTime(1, 0));
        $first = app(ReserveService::class)->forfeitUnmetQuotaHolds('2026-11');
        $second = app(ReserveService::class)->forfeitUnmetQuotaHolds('2026-11');

        $this->assertSame(1, $first['forfeited_count']);
        $this->assertSame(0, $second['forfeited_count']);
        $this->assertSame('forfeited', $hold->fresh()->status);
        $profit = CommissionAllocation::where('category', 'company_profit_fund')->sole();
        $this->assertSame($hold->amount, $profit->amount);
        $this->assertSame($hold->id, $profit->metadata['source_allocation_id']);
        $this->assertSame($dealer->id, $profit->metadata['intended_user_id']);
        $this->assertSame('retained_reason_profit_quota_missed', $profit->metadata['reason_key']);
        $this->assertDatabaseHas('audit_events', ['action' => 'commission.quota_holds_forfeited', 'subject' => '2026-11']);
    }

    public function test_a_quota_met_later_in_the_month_is_released_not_forfeited(): void
    {
        config(['madina.auto_release_quota_holds' => true]);
        $dealer = User::factory()->inPlace($this->division)->create();
        $this->seat($dealer, '100.00');
        $hold = $this->divisionShare($this->purchase(User::factory()->inPlace($this->division)->create()));
        $this->assertSame('held', $hold->status);
        $this->purchase($dealer);
        $this->travelTo(now()->setDate(2026, 12, 1)->setTime(1, 0));

        $result = app(ReserveService::class)->forfeitUnmetQuotaHolds('2026-11');

        $this->assertSame(0, $result['forfeited_count']);
        $this->assertContains($hold->fresh()->status, ['available', 'maturing']);
    }

    public function test_unclaimed_sub_dealer_salary_goes_to_company_profit_not_a_reserve(): void
    {
        $sub = User::factory()->inPlace($this->division)->create(['role' => 'sub_dealer']);
        $this->purchase($sub, '120.00', '200.00');
        $sub->forceFill(['status' => 'suspended'])->save();
        $this->travelTo(now()->setDate(2026, 12, 1)->setTime(1, 0));

        app(CommissionService::class)->settleMonthlySalaryPool('2026-11');

        $profit = CommissionAllocation::where('category', 'company_profit_fund')->where('metadata->source_category', 'sub_dealer_salary')->sole();
        $this->assertSame('3.60', (string) $profit->amount);
        $this->assertSame('retained_reason_unclaimed_sub_dealer_salary', $profit->metadata['reason_key']);
        $this->assertSame($sub->id, $profit->metadata['intended_user_id']);
        $this->assertDatabaseMissing('commission_allocations', ['category' => 'sub_dealer_salary_settlement_reserve']);
    }

    public function test_the_additional_rank_fund_goes_to_profit_at_close_and_marketing_and_management_stay(): void
    {
        $sub = User::factory()->inPlace($this->division)->create(['role' => 'sub_dealer']);
        $order = $this->purchase($sub, '120.00', '200.00');
        $this->travelTo(now()->setDate(2026, 12, 1)->setTime(1, 0));

        $this->artisan('finance:auto-approve')->assertSuccessful();
        $this->artisan('finance:auto-approve')->assertSuccessful();

        // 4% of ৳120 = ৳4.80, moved once with its source.
        $profit = CommissionAllocation::where('category', 'company_profit_fund')->where('metadata->source_category', 'additional_rank_pool')->sole();
        $this->assertSame('4.80', (string) $profit->amount);
        $this->assertSame('retained_reason_unclaimed_additional_rank', $profit->metadata['reason_key']);
        $this->assertSame('2026-11', $profit->metadata['period_month']);
        $this->assertDatabaseHas('commission_allocations', ['order_id' => $order->id, 'category' => 'additional_rank_pool', 'status' => 'settled']);
        // Owner decision: the management share and the marketing budget are kept and carry over.
        $this->assertDatabaseHas('commission_allocations', ['order_id' => $order->id, 'category' => 'area_management', 'status' => 'pending']);
        $this->assertDatabaseHas('commission_allocations', ['order_id' => $order->id, 'category' => 'company_marketing_fund', 'status' => 'pending']);
    }

    public function test_a_closed_additional_rank_fund_can_no_longer_be_spent(): void
    {
        $sub = User::factory()->inPlace($this->division)->create(['role' => 'sub_dealer']);
        $order = $this->purchase($sub, '120.00', '200.00');
        $reserves = app(ReserveService::class);
        $this->assertSame(480, $reserves->remainingByCategory()['additional_rank_pool']);
        $this->travelTo(now()->setDate(2026, 12, 1)->setTime(1, 0));

        $reserves->closeAdditionalRankFund('2026-11');

        $this->assertSame(0, $reserves->remainingByCategory()['additional_rank_pool'] ?? 0);
        $this->assertSame('settled', CommissionAllocation::where('order_id', $order->id)->where('category', 'additional_rank_pool')->sole()->status);
        $this->expectException(ValidationException::class);
        $this->awardAdditionalRank($sub, '1.00');
    }

    public function test_the_additional_rank_close_moves_only_what_was_not_already_paid_out(): void
    {
        $sub = User::factory()->inPlace($this->division)->create(['role' => 'sub_dealer']);
        $order = $this->purchase($sub, '120.00', '200.00');
        $this->awardAdditionalRank($sub, '1.00');
        $this->travelTo(now()->setDate(2026, 12, 1)->setTime(1, 0));

        $result = app(ReserveService::class)->closeAdditionalRankFund('2026-11');

        $this->assertSame('3.80', $result['total_amount']);
        $profit = CommissionAllocation::where('order_id', $order->id)->where('category', 'company_profit_fund')->sole();
        $this->assertSame('3.80', (string) $profit->amount);
        $this->assertSame('1.00', CommissionAllocation::where('order_id', $order->id)->where('category', 'additional_rank_pool')->sole()->metadata['distributed']);
    }

    public function test_a_refund_after_the_close_takes_back_only_a_fair_share_when_an_earlier_refund_came_first(): void
    {
        $order = $this->purchase(User::factory()->inPlace($this->division)->create());
        $commissions = app(CommissionService::class);
        $commissions->reverseOrderPartial($order->fresh(), '20.00', 'First part returned', 'RETURN-1');
        $this->travelTo(now()->setDate(2026, 12, 1)->setTime(1, 0));
        $commissions->settleMonthlySalaryPool('2026-11');
        $fund = CommissionAllocation::where('order_id', $order->id)->where('category', 'dealer_monthly_salary_pool')->sole();
        $profit = CommissionAllocation::where('order_id', $order->id)->where('category', 'company_profit_fund')->sole();
        $this->assertSame((string) $fund->amount, (string) $profit->amount);

        $commissions->reverseOrderPartial($order->fresh(), '20.00', 'Second part returned', 'RETURN-2');

        $this->assertSame((string) $fund->fresh()->amount, (string) $profit->fresh()->amount);
    }

    public function test_a_forfeited_share_is_not_counted_as_the_dealers_income(): void
    {
        $dealer = User::factory()->inPlace($this->division)->create();
        $this->seat($dealer, '100000.00');
        $order = $this->purchase(User::factory()->inPlace($this->division)->create());
        $this->assertGreaterThan(0, (float) $this->divisionShare($order)->amount);
        $this->travelTo(now()->setDate(2026, 12, 1)->setTime(1, 0));
        app(ReserveService::class)->forfeitUnmetQuotaHolds('2026-11');

        $certificate = app(PartnerCertificates::class)->income($dealer, 2026);
        $report = app(PartnerFullReport::class)->for($dealer, null, null);

        $this->assertSame('0.00', $certificate['total']);
        $this->assertNotContains('area_division', array_column($certificate['lines'], 'category'));
        $this->assertNotContains('area_division', array_column($report['commissions'], 'category'));
    }

    private function awardAdditionalRank(User $partner, string $amount): void
    {
        $reserves = app(ReserveService::class);
        $id = $reserves->request(User::factory()->staff()->create(), ['program' => 'additional_rank', 'user_id' => $partner->id, 'amount' => $amount,
            'request_key' => (string) Str::uuid(), 'reason' => 'Additional rank reward']);
        $reserves->review(User::factory()->staff()->create(), $id, 'approved', 'Checked');
    }

    public function test_the_rollover_funds_still_roll_over_after_the_close(): void
    {
        $sub = User::factory()->inPlace($this->division)->create(['role' => 'sub_dealer']);
        $order = $this->purchase($sub, '120.00', '200.00');
        $this->travelTo(now()->setDate(2026, 12, 1)->setTime(1, 0));

        $this->artisan('finance:auto-approve')->assertSuccessful();

        foreach (['rank_incentive_pool' => 'pending', 'sub_dealer_tour' => 'reserved', 'car_bike_incentive_fund' => 'pending'] as $category => $status) {
            $this->assertDatabaseHas('commission_allocations', ['order_id' => $order->id, 'category' => $category, 'status' => $status]);
        }
    }
}
