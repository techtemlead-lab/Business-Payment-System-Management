<?php

namespace Tests\Feature;

use App\Domain\Commissions\CommissionService;
use App\Domain\Finance\CompanyFunds;
use App\Domain\Finance\FinancialReadiness;
use App\Domain\Finance\UnownedShareSweeps;
use App\Domain\Geography\ManagementReferral;
use App\Domain\Orders\OrderService;
use App\Models\AreaDealerAssignment;
use App\Models\CommissionAllocation;
use App\Models\Order;
use App\Models\PlaceCode;
use App\Models\Product;
use App\Models\User;
use Database\Seeders\CommissionRuleSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use Tests\TestCase;

/**
 * MA-04 (D1), MA-03 (D3) and MA-26: commission with nobody to receive it ends up somewhere the
 * owner can see, and a partner who is active again is paid what was held for them.
 */
class UnownedShareSweepsTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->withoutVite();
        $this->enableFinancialPosting();
        $this->seed(CommissionRuleSeeder::class);
        $this->seedClassicPlaceCodes();
    }

    private function purchase(User $buyer, string $cash = '10000.00', string $mrp = '20000.00'): Order
    {
        $product = Product::factory()->create(['mrp' => $mrp, 'dealer_price' => $cash, 'sub_dealer_price' => $buyer->role === 'sub_dealer' ? $cash : null]);
        $order = app(OrderService::class)->create($buyer, ['items' => [['product_id' => $product->id, 'quantity' => 1]], 'idempotency_key' => (string) Str::uuid()]);
        $order->update(['paid' => $cash, 'status' => 'delivered']);
        app(CommissionService::class)->processOrder($order->fresh());

        return $order;
    }

    private function routeReferralToManagement(bool $route): void
    {
        $management = PlaceCode::query()->where('code', 'MNHC100')->firstOrFail();
        DB::table('business_settings')->updateOrInsert(['key' => ManagementReferral::SettingKey], [
            'value' => json_encode(['enabled' => true, 'auto_blank_reference' => true, 'route_referral_slices' => $route, 'place_code_id' => $management->id], JSON_THROW_ON_ERROR),
            'version' => 1, 'updated_by' => User::factory()->create(['role' => 'super_admin'])->id, 'created_at' => now(), 'updated_at' => now(),
        ]);
    }

    private function companyProfitIn(): float
    {
        return app(CompanyFunds::class)->balances()['company_profit']['money_in'] / 100;
    }

    public function test_by_default_a_referral_with_no_upline_is_company_profit_at_posting(): void
    {
        $order = $this->purchase(User::factory()->dealer()->create(['status' => 'active']));

        $direct = CommissionAllocation::query()->where('order_id', $order->id)->where('category', 'direct_referral_breakage')->sole();
        $this->assertSame('company_profit_fund', $direct->metadata['retained_by']);
        $this->assertDatabaseMissing('commission_allocations', ['order_id' => $order->id, 'category' => 'direct_referral', 'status' => 'pending']);
        // 10% + 2% + 1% of ৳10,000 with no upline at any level, plus 4 × 2% area shares with no area dealer.
        $this->assertEquals(2100.0, $this->companyProfitIn());
    }

    public function test_old_management_fund_referral_rows_move_once_to_company_profit(): void
    {
        $this->routeReferralToManagement(true);
        $order = $this->purchase(User::factory()->dealer()->create(['status' => 'active']));
        $this->assertSame(3, CommissionAllocation::query()->where('order_id', $order->id)->whereNull('user_id')
            ->whereIn('category', ['direct_referral', 'd2d_gen2', 'd2d_gen3'])->where('status', 'pending')->count());
        $sweeps = app(UnownedShareSweeps::class);

        // While the owner keeps the switch on, their choice stands.
        $this->assertSame('owner_routes_to_management', $sweeps->moveNoUplineReferrals()['status']);

        $this->routeReferralToManagement(false);
        $before = app(CompanyFunds::class)->balances();
        $moved = $sweeps->moveNoUplineReferrals();

        $this->assertSame(['status' => 'moved', 'moved_count' => 3, 'kept_count' => 0, 'total_amount' => '1300.00'], $moved);
        $after = app(CompanyFunds::class)->balances();
        $this->assertSame($before['company_profit']['money_in'] + 130000, $after['company_profit']['money_in']);
        $this->assertSame($before['management']['money_in'] - 130000, $after['management']['money_in']);
        $profit = CommissionAllocation::query()->where('order_id', $order->id)->where('category', 'company_profit_fund')
            ->where('metadata->reason_key', UnownedShareSweeps::NoUplineReason)->get();
        $this->assertCount(3, $profit);
        $this->assertSame('nothing_to_move', $sweeps->moveNoUplineReferrals()['status']);
        $this->assertTrue(app(FinancialReadiness::class)->inspect()['checks']['allocation_totals']);
    }

    public function test_held_commission_is_paid_when_a_suspended_upline_is_made_active_again(): void
    {
        $upline = User::factory()->dealer()->create(['status' => 'suspended']);
        $buyer = User::factory()->dealer()->create(['status' => 'active', 'sponsor_id' => $upline->id]);
        $order = $this->purchase($buyer);
        $held = CommissionAllocation::query()->where('order_id', $order->id)->where('category', 'direct_referral')->sole();
        $this->assertSame('held', $held->status);
        $this->assertSame($upline->id, $held->user_id);

        $owner = User::factory()->create(['role' => 'super_admin']);
        $this->actingAs($owner)->onPortal('sadmin')->patch('/commands/accounts/'.$upline->id, [
            'name' => $upline->name, 'status' => 'active', 'reason' => 'Partner came back',
        ])->assertRedirect();

        $this->assertSame('available', $held->fresh()->status);
        $this->assertSame('1000.00', DB::table('wallets')->where('user_id', $upline->id)->value('available'));
        // Running the hourly sweep again pays nothing twice.
        $this->assertSame(0, app(UnownedShareSweeps::class)->releaseHeldForActivePartners()['released_count']);
    }

    public function test_a_closed_accounts_held_commission_goes_to_company_profit_after_three_months(): void
    {
        $upline = User::factory()->dealer()->create(['status' => 'suspended', 'closed_at' => now()]);
        $order = $this->purchase(User::factory()->dealer()->create(['status' => 'active', 'sponsor_id' => $upline->id]));
        $held = CommissionAllocation::query()->where('order_id', $order->id)->where('category', 'direct_referral')->sole();
        $sweeps = app(UnownedShareSweeps::class);

        $this->assertSame('nothing_to_move', $sweeps->closedAccountHoldsToCompanyProfit()['status']);
        $this->travel(3)->months();
        $this->travel(1)->days();
        $profitBefore = $this->companyProfitIn();

        $this->assertSame('moved', $sweeps->closedAccountHoldsToCompanyProfit()['status']);
        $this->assertSame('forfeited', $held->fresh()->status);
        $this->assertEquals($profitBefore + 1000.0, $this->companyProfitIn());
        $this->assertSame('nothing_to_move', $sweeps->closedAccountHoldsToCompanyProfit()['status']);
    }

    public function test_two_seats_for_one_place_alert_and_pay_neither(): void
    {
        $division = PlaceCode::query()->where('code', 'MNHC102')->firstOrFail();
        foreach ([1, 2] as $seat) {
            AreaDealerAssignment::create(['user_id' => User::factory()->dealer()->create(['status' => 'active'])->id, 'level' => 'division',
                'place_code_id' => $division->id, 'division' => 'Rangpur', 'zone_name' => 'Rangpur '.$seat, 'monthly_quota' => '0.00', 'is_active' => true]);
        }
        Log::spy();

        $order = $this->purchase(User::factory()->dealer()->inPlace($division)->create(['status' => 'active']));

        $share = CommissionAllocation::query()->where('order_id', $order->id)->where('category', 'area_division')->sole();
        $this->assertNull($share->user_id);
        $this->assertSame('company_profit_fund', $share->metadata['retained_by']);
        $this->assertSame('money_core.reason_two_area_seats', $share->metadata['reason_key']);
        Log::shouldHaveReceived('critical')->withArgs(fn (string $message): bool => str_contains($message, 'Two active area seats'))->once();
    }
}
