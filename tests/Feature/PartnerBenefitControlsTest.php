<?php

namespace Tests\Feature;

use App\Domain\Accounts\PartnerBenefitControls;
use App\Domain\Commissions\CommissionPolicy;
use App\Domain\Commissions\CommissionPosting;
use App\Domain\Commissions\CommissionQualification;
use App\Domain\Commissions\CommissionSettlement;
use App\Domain\Orders\OrderService;
use App\Models\AreaDealerAssignment;
use App\Models\CommissionAllocation;
use App\Models\DealerRank;
use App\Models\Order;
use App\Models\PlaceCode;
use App\Models\Product;
use App\Models\User;
use Database\Seeders\CommissionRuleSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\TestCase;

class PartnerBenefitControlsTest extends TestCase
{
    use RefreshDatabase;

    public function test_admin_can_save_unconditional_benefit_controls_directly(): void
    {
        $admin = User::factory()->create(['role' => 'super_admin', 'status' => 'active']);
        $dealer = User::factory()->create(['role' => 'dealer', 'status' => 'active']);

        $this->actingAs($admin)->onPortal('sadmin')
            ->post('/commands/accounts/'.$dealer->id.'/benefit-controls', [
                'unconditional_salary' => 1,
                'unconditional_rank_royalty' => 1,
                'forced_royalty_rank' => 'mo',
                'unconditional_rank_incentive' => 1,
                'note' => 'Founder exception',
                'reason' => 'Approved by owner for launch support',
            ])
            ->assertRedirect()
            ->assertSessionHas('success', 'Unconditional benefit controls saved for '.$dealer->name.'.');

        $dealer->refresh();
        $controls = PartnerBenefitControls::for($dealer);
        $this->assertTrue($controls['unconditional_salary']);
        $this->assertTrue($controls['unconditional_rank_royalty']);
        $this->assertSame('mo', $controls['forced_royalty_rank']);
        $this->assertTrue($controls['unconditional_rank_incentive']);
        $this->assertDatabaseHas('dealer_ranks', ['user_id' => $dealer->id]);
    }

    public function test_benefit_control_flash_follows_admin_bangla_locale(): void
    {
        $admin = User::factory()->create(['role' => 'super_admin', 'status' => 'active', 'locale' => 'bn']);
        $dealer = User::factory()->create(['role' => 'dealer', 'status' => 'active', 'name' => 'Rahim Dealer']);

        $this->actingAs($admin)->onPortal('sadmin')
            ->post('/commands/accounts/'.$dealer->id.'/benefit-controls', [
                'unconditional_salary' => 1,
                'reason' => 'Owner override for Bangla flash coverage',
            ])
            ->assertRedirect()
            ->assertSessionHas('success', __('portal.pbc_saved', ['name' => 'Rahim Dealer'], 'bn'));
    }

    public function test_unconditional_rank_royalty_forces_paid_as_rank_without_volume(): void
    {
        $dealer = User::factory()->create(['role' => 'dealer', 'status' => 'active']);
        DealerRank::query()->create(['user_id' => $dealer->id, 'current_rank' => 'dealer']);
        app(PartnerBenefitControls::class)->save(
            User::factory()->create(['role' => 'super_admin']),
            $dealer,
            [
                'unconditional_rank_royalty' => true,
                'forced_royalty_rank' => 'am',
                'reason' => 'Owner override for AM royalty bucket',
            ],
        );

        $paidAs = app(CommissionQualification::class)->paidAs($dealer->id);
        $this->assertSame('am', $paidAs['paid_as_rank']);
        $this->assertSame('admin_unconditional', $paidAs['status']);
    }

    public function test_salary_settlement_includes_volume_waived_dealer(): void
    {
        $this->enableFinancialPosting();
        $admin = User::factory()->create(['role' => 'super_admin', 'status' => 'active']);
        $dealer = User::factory()->create(['role' => 'dealer', 'status' => 'active']);
        app(PartnerBenefitControls::class)->save($admin, $dealer, [
            'unconditional_salary' => true,
            'reason' => 'Waive salary volume for this partner',
        ]);

        $this->assertTrue(PartnerBenefitControls::waivesSalaryVolume($dealer->fresh()));
    }

    public function test_custom_monthly_target_governs_dealer_salary_royalty_and_area_income_for_higher_and_lower(): void
    {
        $this->enableFinancialPosting();
        $this->seed(CommissionRuleSeeder::class);
        $this->travelTo(now()->setDate(2026, 11, 10)->startOfDay());

        $admin = User::factory()->staff('super_admin')->create();
        $division = PlaceCode::factory()->division()->create(['name' => 'Rangpur']);
        $district = PlaceCode::factory()->district($division)->create(['name' => 'Rangpur']);
        $upazila = PlaceCode::factory()->upazila($district)->create(['name' => 'Pirganj']);
        $unionLow = PlaceCode::factory()->union($upazila)->create(['name' => 'Balia']);
        $unionHigh = PlaceCode::factory()->union($upazila)->create(['name' => 'Kabilpur']);

        $lowDealer = User::factory()->inPlace($unionLow)->create(['role' => 'dealer', 'status' => 'active']);
        $highDealer = User::factory()->inPlace($unionHigh)->create(['role' => 'dealer', 'status' => 'active']);
        AreaDealerAssignment::create(['user_id' => $lowDealer->id, 'level' => 'union', 'place_code_id' => $unionLow->id, 'division' => 'Rangpur', 'zone_name' => 'Balia', 'is_active' => true]);
        AreaDealerAssignment::create(['user_id' => $highDealer->id, 'level' => 'union', 'place_code_id' => $unionHigh->id, 'division' => 'Rangpur', 'zone_name' => 'Kabilpur', 'is_active' => true]);

        DealerRank::create(['user_id' => $lowDealer->id, 'current_rank' => 'mo', 'achieved_at' => now()->subMonths(2)]);
        DealerRank::create(['user_id' => $highDealer->id, 'current_rank' => 'mo', 'achieved_at' => now()->subMonths(2)]);

        // Default union target is 30,000. Set custom lower (15,000) and higher (50,000).
        app(PartnerBenefitControls::class)->save($admin, $lowDealer, [
            'custom_conditions' => ['monthly_target' => '15000.00'],
            'reason' => 'Lower monthly target for new rural hub',
        ]);
        app(PartnerBenefitControls::class)->save($admin, $highDealer, [
            'custom_conditions' => ['monthly_target' => '50000.00'],
            'reason' => 'Higher monthly target for flagship hub',
        ]);

        // Give lowDealer 20,000 cash (passes 15k custom, below 30k default)
        $this->deliverPurchase($lowDealer, '20000.00', '40000.00');
        // Give highDealer 35,000 cash (passes 30k default, fails 50k custom)
        $this->deliverPurchase($highDealer, '35000.00', '70000.00');

        $qualification = app(CommissionQualification::class);
        $lowPaidAs = $qualification->paidAs($lowDealer->id, '2026-11');
        $highPaidAs = $qualification->paidAs($highDealer->id, '2026-11');

        $this->assertTrue($lowPaidAs['is_qualified']);
        $this->assertSame('15000.00', $lowPaidAs['required_volume']);
        $this->assertFalse($highPaidAs['is_qualified']);
        $this->assertSame('50000.00', $highPaidAs['required_volume']);

        $salaryPlan = app(CommissionSettlement::class)->salaryPlan('2026-11');
        $qualifierIds = collect($salaryPlan['dealers'])->pluck('user_id')->all();
        $this->assertContains($lowDealer->id, $qualifierIds);
        $this->assertNotContains($highDealer->id, $qualifierIds);

        // Downline purchase in each union tests area quota hold against custom targets
        $lowBuyer = User::factory()->inPlace($unionLow)->create(['role' => 'sub_dealer', 'status' => 'active']);
        $highBuyer = User::factory()->inPlace($unionHigh)->create(['role' => 'sub_dealer', 'status' => 'active']);
        $this->deliverPurchase($lowBuyer, '1000.00', '2000.00');
        $this->deliverPurchase($highBuyer, '1000.00', '2000.00');

        $lowAreaRow = CommissionAllocation::where('user_id', $lowDealer->id)
            ->where('category', 'area_union')
            ->latest('id')
            ->first();
        $highAreaRow = CommissionAllocation::where('user_id', $highDealer->id)
            ->where('category', 'area_union')
            ->latest('id')
            ->first();

        $this->assertNotNull($lowAreaRow);
        $this->assertNotNull($highAreaRow);
        $this->assertNotSame('quota_hold', $lowAreaRow->status);
        $this->assertSame('quota_hold', $highAreaRow->status);
    }

    public function test_custom_conditions_govern_sub_dealer_tiers_rank_mo_bike_and_cooling_off(): void
    {
        $this->enableFinancialPosting();
        $this->seed(CommissionRuleSeeder::class);
        $this->travelTo(now()->setDate(2026, 11, 10)->startOfDay());

        $admin = User::factory()->staff('super_admin')->create();
        $rules = app(CommissionPolicy::class);
        $rules->update($admin, [
            'commission_cooling_off' => ['parameters' => ['cooling_off_days' => 5]],
        ]);

        $subDealer = User::factory()->create(['role' => 'sub_dealer', 'status' => 'active']);
        $dealer = User::factory()->create(['role' => 'dealer', 'status' => 'active']);
        $defaultDealer = User::factory()->create(['role' => 'dealer', 'status' => 'active']);

        // Lower sub-dealer tier1 to 5,000 @ 0.50% (default 10,000 @ 1.00%), lower mo_target to 100,000 (default 500,000), lower bike_target to 50,000, cooling_off_days to 0
        app(PartnerBenefitControls::class)->save($admin, $subDealer, [
            'custom_conditions' => [
                'tier1_volume' => '5000.00',
                'tier1_pct' => '0.50',
                'cooling_off_days' => 0,
            ],
            'reason' => 'Custom sub-dealer tier1 and instant release',
        ]);
        app(PartnerBenefitControls::class)->save($admin, $dealer, [
            'custom_conditions' => [
                'mo_target' => '100000.00',
                'bike_target' => '50000.00',
                'cooling_off_days' => 0,
            ],
            'reason' => 'Lower MO target, lower bike target, instant release',
        ]);

        // Sub-dealer buys 6,000: qualifies for custom tier1 (5k @ 0.50% = 30.00), below default tier1 (10k)
        $subOrder = $this->deliverPurchase($subDealer, '6000.00', '10000.00');
        // Dealer buys 120,000: below default MO (500k) -> reaches MO under custom 100k AND achieves custom bike 50k
        $dealerOrder = $this->deliverPurchase($dealer, '120000.00', '240000.00');
        // Control dealer with no custom override stays maturing under global cooling_off_days=5
        $defaultOrder = $this->deliverPurchase($defaultDealer, '1000.00', '2000.00');

        $qualification = app(CommissionQualification::class);
        $this->assertSame('mo', DealerRank::where('user_id', $dealer->id)->value('current_rank'));

        $bikeMilestone = $qualification->milestone($dealer->id, 'bike');
        $this->assertSame('50000.00', $bikeMilestone['target_amount']);
        $this->assertSame('120000.00', $bikeMilestone['qualifying_volume']);
        $this->assertTrue($bikeMilestone['achieved']);

        // Cooling-off override = 0 gives available cashback while default partner stays maturing
        $customCashback = CommissionAllocation::where('order_id', $dealerOrder->id)
            ->where('user_id', $dealer->id)
            ->where('category', 'own_dealer_cashback')
            ->first();
        $defaultCashback = CommissionAllocation::where('order_id', $defaultOrder->id)
            ->where('user_id', $defaultDealer->id)
            ->where('category', 'own_dealer_cashback')
            ->first();
        $this->assertNotNull($customCashback);
        $this->assertNotNull($defaultCashback);
        $this->assertSame('available', $customCashback->status);
        $this->assertSame('maturing', $defaultCashback->status);

        // Travel past November end so monthly salary settlement runs cleanly for 2026-11
        $this->travelTo(now()->setDate(2026, 12, 1)->startOfDay());
        $subPlan = app(CommissionSettlement::class)->settle('2026-11', 'salary', $admin->id, (string) Str::uuid());
        $this->assertIsArray($subPlan);
        $this->assertSame(
            '30.00',
            (string) DB::table('commission_settlement_payouts')
                ->where('user_id', $subDealer->id)
                ->where('kind', 'salary')
                ->where('period_month', '2026-11')
                ->value('amount'),
        );

        // Clearing custom_conditions reverts to general defaults cleanly
        app(PartnerBenefitControls::class)->save($admin, $dealer, [
            'custom_conditions' => [],
            'reason' => 'Revert custom conditions',
        ]);
        $this->assertSame([], PartnerBenefitControls::for($dealer->fresh())['custom_conditions']);
        $this->assertNotNull($subOrder->id);
        $this->assertNotNull($dealerOrder->id);
    }

    private function deliverPurchase(User $buyer, string $cash, string $mrp): Order
    {
        $product = Product::factory()->create([
            'mrp' => $mrp,
            'dealer_price' => $cash,
            'sub_dealer_price' => $buyer->role === 'sub_dealer' ? $cash : null,
        ]);
        $order = app(OrderService::class)->create($buyer, [
            'items' => [['product_id' => $product->id, 'quantity' => 1]],
            'idempotency_key' => (string) Str::uuid(),
        ]);
        $order->update(['status' => 'delivered', 'paid' => $cash]);
        app(CommissionPosting::class)->process($order->fresh());

        return $order;
    }
}
