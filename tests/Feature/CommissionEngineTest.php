<?php

namespace Tests\Feature;

use App\Domain\Commissions\CommissionService;
use App\Models\AreaDealerAssignment;
use App\Models\CommissionAllocation;
use App\Models\CommissionRuleParameter;
use App\Models\DealerRank;
use App\Models\Order;
use App\Models\Product;
use App\Models\ReferralTreePath;
use App\Models\RewardMilestoneProgress;
use App\Models\User;
use Database\Seeders\CommissionRuleSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class CommissionEngineTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->withoutVite();
        $this->seed(CommissionRuleSeeder::class);
    }

    public function test_closure_table_records_exact_depth_paths(): void
    {
        // Setup hierarchy: uplineA -> uplineB -> uplineC -> buyerD
        $uplineA = User::factory()->create(['role' => 'dealer', 'status' => 'active']);
        ReferralTreePath::attachUser($uplineA->id, null);

        $uplineB = User::factory()->create(['role' => 'dealer', 'status' => 'active', 'sponsor_id' => $uplineA->id]);
        ReferralTreePath::attachUser($uplineB->id, $uplineA->id);

        $uplineC = User::factory()->create(['role' => 'dealer', 'status' => 'active', 'sponsor_id' => $uplineB->id]);
        ReferralTreePath::attachUser($uplineC->id, $uplineB->id);

        $buyerD = User::factory()->create(['role' => 'dealer', 'status' => 'active', 'sponsor_id' => $uplineC->id]);
        ReferralTreePath::attachUser($buyerD->id, $uplineC->id);

        $uplines = ReferralTreePath::getUplines($buyerD->id, 3);

        $this->assertCount(3, $uplines);
        $this->assertEquals($uplineC->id, $uplines->firstWhere('depth', 1)->ancestor_id);
        $this->assertEquals($uplineB->id, $uplines->firstWhere('depth', 2)->ancestor_id);
        $this->assertEquals($uplineA->id, $uplines->firstWhere('depth', 3)->ancestor_id);
    }

    public function test_zero_deficit_constraint_rejects_pool_sum_exceeding_forty_percent(): void
    {
        $admin = User::factory()->create(['role' => 'super_admin']);

        // Attempt to set direct referral to 35% while others total > 10% (sum > 40%)
        $rules = CommissionRuleParameter::all()->keyBy('rule_key')->toArray();
        $rules['direct_referral']['percentage'] = 35.00;
        $rules['own_dealer_cashback']['percentage'] = 10.00; // 35 + 10 = 45% > 40%

        $response = $this->actingAs($admin)->post('/commands/commissions/rules', [
            'rules' => $rules,
        ]);

        $response->assertSessionHasErrors('rules');
    }

    public function test_waterfall_allocates_correct_shares_and_credits_wallets(): void
    {
        $service = app(CommissionService::class);

        // Build 3-generation chain
        $gen3 = User::factory()->create(['role' => 'dealer', 'status' => 'active']);
        ReferralTreePath::attachUser($gen3->id, null);

        $gen2 = User::factory()->create(['role' => 'dealer', 'status' => 'active', 'sponsor_id' => $gen3->id]);
        ReferralTreePath::attachUser($gen2->id, $gen3->id);

        $gen1 = User::factory()->create(['role' => 'dealer', 'status' => 'active', 'sponsor_id' => $gen2->id]);
        ReferralTreePath::attachUser($gen1->id, $gen2->id);

        $buyer = User::factory()->create(['role' => 'dealer', 'status' => 'active', 'sponsor_id' => $gen1->id]);
        ReferralTreePath::attachUser($buyer->id, $gen1->id);

        // Create an order for buyer of ৳10,000 cash total
        $order = Order::create([
            'idempotency_key' => (string) \Illuminate\Support\Str::uuid(),
            'payload_hash' => hash('sha256', 'test1'),
            'number' => 'ORD-TEST-001',
            'user_id' => $buyer->id,
            'status' => 'delivered',
            'total' => '10000.00',
            'paid' => '10000.00',
        ]);

        // Process commissions
        $allocations = $service->processOrder($order);

        $this->assertNotEmpty($allocations);

        // 1. Self Cashback (5% of 10,000 = 500)
        $selfAlloc = CommissionAllocation::where('order_id', $order->id)->where('category', 'own_dealer_cashback')->first();
        $this->assertNotNull($selfAlloc);
        $this->assertEquals($buyer->id, $selfAlloc->user_id);
        $this->assertEquals(500.00, (float)$selfAlloc->amount);

        // 2. Direct Referral (10% of 10,000 = 1000)
        $dirAlloc = CommissionAllocation::where('order_id', $order->id)->where('category', 'direct_referral')->first();
        $this->assertNotNull($dirAlloc);
        $this->assertEquals($gen1->id, $dirAlloc->user_id);
        $this->assertEquals(1000.00, (float)$dirAlloc->amount);

        // 3. D2D Gen 2 (2% of 10,000 = 200)
        $g2Alloc = CommissionAllocation::where('order_id', $order->id)->where('category', 'd2d_gen2')->first();
        $this->assertNotNull($g2Alloc);
        $this->assertEquals($gen2->id, $g2Alloc->user_id);
        $this->assertEquals(200.00, (float)$g2Alloc->amount);

        // 4. D2D Gen 3 (1% of 10,000 = 100)
        $g3Alloc = CommissionAllocation::where('order_id', $order->id)->where('category', 'd2d_gen3')->first();
        $this->assertNotNull($g3Alloc);
        $this->assertEquals($gen3->id, $g3Alloc->user_id);
        $this->assertEquals(100.00, (float)$g3Alloc->amount);

        // Check wallet balances credited
        $buyerWallet = DB::table('wallets')->where('user_id', $buyer->id)->first();
        $this->assertEquals(500.00, (float)$buyerWallet->available);

        $gen1Wallet = DB::table('wallets')->where('user_id', $gen1->id)->first();
        $this->assertEquals(1000.00, (float)$gen1Wallet->available);

        $gen2Wallet = DB::table('wallets')->where('user_id', $gen2->id)->first();
        $this->assertEquals(200.00, (float)$gen2Wallet->available);

        $gen3Wallet = DB::table('wallets')->where('user_id', $gen3->id)->first();
        $this->assertEquals(100.00, (float)$gen3Wallet->available);

        // Test idempotency: Calling processOrder again does not duplicate
        $secondRun = $service->processOrder($order);
        $this->assertNotEmpty($secondRun); // Returns existing allocations from db guard

        $buyerWalletAfter = DB::table('wallets')->where('user_id', $buyer->id)->first();
        $this->assertEquals(500.00, (float)$buyerWalletAfter->available);
    }

    public function test_dealer_promoted_to_mo_upon_reaching_two_point_five_lakh_turnover(): void
    {
        $service = app(CommissionService::class);

        $dealer = User::factory()->create(['role' => 'dealer', 'status' => 'active']);
        ReferralTreePath::attachUser($dealer->id, null);

        DealerRank::firstOrCreate(
            ['user_id' => $dealer->id],
            ['current_rank' => 'dealer', 'lifetime_purchase_total' => 240000.00]
        );

        // Buyer makes a ৳20,000 order, taking cumulative to ৳260,000 (> ৳250,000 MO target)
        $order = Order::create([
            'idempotency_key' => (string) \Illuminate\Support\Str::uuid(),
            'payload_hash' => hash('sha256', 'test2'),
            'number' => 'ORD-MO-PROMO',
            'user_id' => $dealer->id,
            'status' => 'delivered',
            'total' => '20000.00',
            'paid' => '20000.00',
        ]);

        $service->processOrder($order);

        $rankRecord = DealerRank::where('user_id', $dealer->id)->first();
        $this->assertEquals('mo', $rankRecord->current_rank);
        $this->assertNotNull($rankRecord->achieved_at);
        $this->assertEquals(260000.00, (float)$rankRecord->lifetime_purchase_total);
    }

    public function test_anti_whale_branch_capping_truncates_excess_volume(): void
    {
        $service = app(CommissionService::class);

        $leader = User::factory()->create(['role' => 'dealer', 'status' => 'active']);
        ReferralTreePath::attachUser($leader->id, null);

        // Branch 1 (Whale Leg): Frontline leg 1
        $leg1 = User::factory()->create(['role' => 'dealer', 'status' => 'active', 'sponsor_id' => $leader->id]);
        ReferralTreePath::attachUser($leg1->id, $leader->id);

        // Branch 2 (Normal Leg): Frontline leg 2
        $leg2 = User::factory()->create(['role' => 'dealer', 'status' => 'active', 'sponsor_id' => $leader->id]);
        ReferralTreePath::attachUser($leg2->id, $leader->id);

        // Record ৳15,00,000 purchases in Leg 1 (Exceeds ৳8,00,000 Bike cap)
        Order::create([
            'idempotency_key' => (string) \Illuminate\Support\Str::uuid(),
            'payload_hash' => hash('sha256', 'test3'),
            'number' => 'ORD-LEG1-WHALE',
            'user_id' => $leg1->id,
            'status' => 'delivered',
            'total' => '1500000.00',
            'paid' => '1500000.00',
        ]);

        // Record ৳4,00,000 purchases in Leg 2
        Order::create([
            'idempotency_key' => (string) \Illuminate\Support\Str::uuid(),
            'payload_hash' => hash('sha256', 'test4'),
            'number' => 'ORD-LEG2-NORMAL',
            'user_id' => $leg2->id,
            'status' => 'delivered',
            'total' => '400000.00',
            'paid' => '400000.00',
        ]);

        // Evaluate Bike Milestone
        $eval = $service->evaluateAntiWhaleLegs($leader->id, 'bike');

        // Total raw volume is 15L + 4L = 19L
        $this->assertEquals(1900000.00, $eval['raw_team_volume']);

        // Qualifying volume must be capped at 8L for Leg 1 + 4L for Leg 2 = 12L
        $this->assertEquals(1200000.00, $eval['qualifying_volume']);

        // Leg 1 excess must be exactly 7L
        $leg1Breakdown = collect($eval['legs'])->firstWhere('dealer_id', $leg1->id);
        $this->assertEquals(800000.00, $leg1Breakdown['qualifying_volume']);
        $this->assertEquals(700000.00, $leg1Breakdown['excess_volume']);

        // Leg 2 has 0 excess
        $leg2Breakdown = collect($eval['legs'])->firstWhere('dealer_id', $leg2->id);
        $this->assertEquals(400000.00, $leg2Breakdown['qualifying_volume']);
        $this->assertEquals(0.00, $leg2Breakdown['excess_volume']);
    }

    public function test_staff_can_assign_and_remove_area_dealer(): void
    {
        $admin = User::factory()->create(['role' => 'super_admin']);
        $dealer = User::factory()->create(['role' => 'dealer', 'status' => 'active']);

        // 1. Assign territory
        $response = $this->actingAs($admin)->post('/commands/area-dealers', [
            'user_id' => $dealer->id,
            'level' => 'district',
            'zone_name' => 'Gazipur Sadar Hub',
            'division' => 'Dhaka',
            'district' => 'Gazipur',
            'monthly_quota' => 40000.00,
        ]);

        $response->assertSessionHas('success');
        $this->assertDatabaseHas('area_dealer_assignments', [
            'user_id' => $dealer->id,
            'zone_name' => 'Gazipur Sadar Hub',
            'level' => 'district',
        ]);

        $assignment = AreaDealerAssignment::where('user_id', $dealer->id)->first();

        // 2. Remove assignment
        $delResponse = $this->actingAs($admin)->delete("/commands/area-dealers/{$assignment->id}");
        $delResponse->assertSessionHas('success');
        $this->assertDatabaseMissing('area_dealer_assignments', [
            'id' => $assignment->id,
        ]);
    }

    public function test_company_dashboard_renders_all_commission_and_network_sections(): void
    {
        $admin = User::factory()->create(['role' => 'super_admin']);

        $sections = [
            'commissions',
            'settlements',
            'simulator',
            'payouts-hub',
            'network-tree',
            'area-dealers',
            'ranks',
            'rewards',
            'capital-flow',
            'treasury',
            'sub-dealers',
            'sub-dealer-orders',
        ];

        foreach ($sections as $section) {
            $response = $this->actingAs($admin)->get("/company/{$section}");
            $response->assertOk();
        }
    }
}

