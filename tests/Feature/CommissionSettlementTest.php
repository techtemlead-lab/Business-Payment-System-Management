<?php

namespace Tests\Feature;

use App\Domain\Commissions\CommissionQualification;
use App\Domain\Commissions\CommissionService;
use App\Domain\Commissions\CommissionWallet;
use App\Domain\Orders\OrderService;
use App\Models\DealerRank;
use App\Models\Order;
use App\Models\Product;
use App\Models\User;
use App\Support\Money;
use Carbon\CarbonImmutable;
use Database\Seeders\CommissionRuleSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
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
        config(['madina.financial_activation' => true]);
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

    public function test_closed_month_salary_uses_actual_period_volume_and_sub_dealer_receives_flat_three_percent_once(): void
    {
        $dealer = User::factory()->create();
        $sub = User::factory()->create(['role' => 'sub_dealer']);
        $this->purchase($dealer, '10000.00', '20000.00');
        $this->purchase($sub, '12000.00', '20000.00');
        $this->travelTo(now()->setDate(2026, 10, 1));
        $result = app(CommissionService::class)->settleMonthlySalaryPool('2026-09');

        $this->assertSame('settled', $result['status']);
        $this->assertDatabaseHas('wallets', ['user_id' => $dealer->id, 'available' => '940.00']);
        $this->assertDatabaseHas('wallets', ['user_id' => $sub->id, 'available' => '960.00']);
        $this->assertSame('empty_pool', app(CommissionService::class)->settleMonthlySalaryPool('2026-09')['status']);
        $this->assertDatabaseHas('wallets', ['user_id' => $sub->id, 'available' => '960.00']);
        $this->assertDatabaseHas('commission_allocations', ['user_id' => $sub->id, 'category' => 'sub_dealer_tour', 'status' => 'reserved', 'amount' => '360.00']);
    }

    public function test_settlement_refunds_recover_both_direct_earnings_and_source_pool_payouts_without_repeating(): void
    {
        $dealer = User::factory()->create();
        $order = $this->purchase($dealer, '10000.00', '20000.00');
        $this->travelTo(now()->setDate(2026, 10, 1));
        $service = app(CommissionService::class);
        $service->settleMonthlySalaryPool('2026-09');
        $this->assertDatabaseHas('wallets', ['user_id' => $dealer->id, 'available' => '700.00']);
        $service->reverseOrderPartial($order, '5000.00', 'Verified return', 'RETURN-001');
        $service->reverseOrderPartial($order, '5000.00', 'Verified return', 'RETURN-001');

        $this->assertDatabaseHas('wallets', ['user_id' => $dealer->id, 'available' => '350.00']);
        $this->assertDatabaseCount('commission_refunds', 1);
        $this->assertDatabaseHas('commission_allocations', ['order_id' => $order->id, 'category' => 'own_dealer_cashback', 'original_amount' => '500.00', 'amount' => '250.00']);
        $service->reverseOrder($order, 'Remaining goods returned');
        $this->assertDatabaseHas('wallets', ['user_id' => $dealer->id, 'available' => '0.00']);
        $this->assertDatabaseHas('commission_batches', ['order_id' => $order->id, 'refunded_amount' => '10000.00']);
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
        $leader = User::factory()->create();
        DealerRank::create(['user_id' => $leader->id, 'current_rank' => 'mo', 'achieved_at' => now()]);
        $order = $this->purchase(User::factory()->create(), '333.33', '666.66');
        $this->travelTo(now()->setDate(2026, 10, 1));
        $result = app(CommissionService::class)->settleRankRoyaltyPool('2026-09');
        $tiers = $result['distributions'];
        $sum = 0;
        foreach ($tiers as $tier) {
            $sum += Money::cents($tier['allocated']) + Money::cents($tier['rolled_to_reserve']);
        }

        $this->assertSame(1333, $sum);
        $this->assertSame('empty_pool', app(CommissionService::class)->settleRankRoyaltyPool('2026-09')['status']);
        $this->assertDatabaseHas('commission_allocations', ['order_id' => $order->id, 'category' => 'rank_royalty_pool', 'status' => 'settled']);
        $this->assertSame('3.34', DB::table('wallets')->where('user_id', $leader->id)->value('available'));
        $this->assertDatabaseHas('commission_allocations', [
            'order_id' => $order->id,
            'category' => 'rank_royalty_settlement_reserve',
            'status' => 'reserved',
            'amount' => '9.99',
        ]);
        $this->assertSame('9.99', $result['total_rolled_to_reserve']);
    }

    public function test_rank_incentives_require_real_reserve_funding_and_cannot_be_claimed_twice(): void
    {
        $leader = User::factory()->create();
        DealerRank::create(['user_id' => $leader->id, 'current_rank' => 'mo', 'achieved_at' => now()]);
        $service = app(CommissionService::class);
        $this->assertFalse($service->claimOneTimeRankIncentive($leader->id)['success']);
        $this->purchase(User::factory()->create(), '250000.00', '500000.00');
        $claim = $service->claimOneTimeRankIncentive($leader->id);

        $this->assertTrue($claim['success']);
        $this->assertSame('5000.00', $claim['amount']);
        $this->assertFalse($service->claimOneTimeRankIncentive($leader->id)['success']);
        $this->assertDatabaseCount('rank_incentive_awards', 1);
        $this->assertDatabaseHas('wallets', ['user_id' => $leader->id, 'available' => '5000.00']);
    }

    public function test_rank_incentive_is_blocked_when_maintenance_demotes_the_leader(): void
    {
        $leader = User::factory()->create();
        DealerRank::create([
            'user_id' => $leader->id,
            'current_rank' => 'mo',
            'achieved_at' => now()->subDays(60),
        ]);
        $this->purchase(User::factory()->create(), '250000.00', '500000.00');
        $claim = app(CommissionService::class)->claimOneTimeRankIncentive($leader->id);

        $this->assertFalse($claim['success']);
        $this->assertStringContainsString('maintenance', $claim['message']);
        $this->assertDatabaseCount('rank_incentive_awards', 0);
    }

    public function test_salary_pool_with_no_qualifiers_moves_cash_into_settlement_reserve(): void
    {
        $buyer = User::factory()->create();
        $order = $this->purchase($buyer, '5000.00', '10000.00');
        $this->travelTo(now()->setDate(2026, 10, 1));
        $result = app(CommissionService::class)->settleMonthlySalaryPool('2026-09');

        $this->assertSame('rolled_over', $result['status']);
        $this->assertSame('100.00', $result['total_rolled_to_reserve']);
        $this->assertDatabaseHas('commission_allocations', [
            'order_id' => $order->id,
            'category' => 'dealer_salary_settlement_reserve',
            'status' => 'reserved',
            'amount' => '100.00',
        ]);
        $this->assertDatabaseMissing('commission_settlement_payouts', ['user_id' => $buyer->id, 'kind' => 'salary']);
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
        $buyer = User::factory()->create();
        $this->purchase($buyer, '10000.00', '20000.00');
        $this->travelTo(now()->setDate(2026, 10, 1));
        $this->actingAs(User::factory()->create(['role' => 'super_admin']))->patchJson('/api/v1/finance/policy',
            ['rules' => ['dealer_monthly_salary' => ['parameters' => ['min_monthly_volume' => '20000.00']]]])->assertOk();
        app(CommissionService::class)->settleMonthlySalaryPool('2026-09');
        $this->assertDatabaseHas('wallets', ['user_id' => $buyer->id, 'available' => '700.00']);
    }

    public function test_a_rank_achieved_after_month_end_does_not_receive_retroactive_royalty(): void
    {
        $this->purchase(User::factory()->create(), '10000.00', '20000.00');
        $this->travelTo(now()->setDate(2026, 10, 2));
        $leader = User::factory()->create();
        DealerRank::create(['user_id' => $leader->id, 'current_rank' => 'mo', 'achieved_at' => now()]);
        $result = app(CommissionService::class)->settleRankRoyaltyPool('2026-09');
        $this->assertSame('0.00', $result['total_distributed']);
        $this->assertSame('400.00', $result['total_rolled_to_reserve']);
        $this->assertDatabaseMissing('commission_settlement_payouts', ['user_id' => $leader->id]);
        $this->assertDatabaseHas('commission_allocations', [
            'category' => 'rank_royalty_settlement_reserve',
            'status' => 'reserved',
            'amount' => '400.00',
        ]);
    }

    public function test_mo_requires_two_point_five_lakh_in_qualifying_own_purchases(): void
    {
        $buyer = User::factory()->create();
        $this->purchase($buyer, '240000.00', '480000.00');
        $this->assertDatabaseHas('dealer_ranks', ['user_id' => $buyer->id, 'current_rank' => 'dealer']);
        $this->purchase($buyer, '10000.00', '20000.00');
        $this->assertDatabaseHas('dealer_ranks', ['user_id' => $buyer->id, 'current_rank' => 'mo', 'lifetime_purchase_total' => '250000.00']);
        $this->assertDatabaseCount('rank_incentive_awards', 0);
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
    }
}
