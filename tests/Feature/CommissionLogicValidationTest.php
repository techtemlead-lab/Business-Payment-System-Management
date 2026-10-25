<?php

namespace Tests\Feature;

use App\Domain\Commissions\CommissionService;
use App\Models\CommissionAllocation;
use App\Models\CommissionRuleParameter;
use App\Models\DealerRank;
use App\Models\Order;
use App\Models\Product;
use App\Models\ReferralTreePath;
use App\Models\User;
use App\Support\Money;
use Database\Seeders\CommissionRuleSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use InvalidArgumentException;
use Tests\TestCase;

class CommissionLogicValidationTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->withoutVite();
        $this->enableFinancialPosting();
        $this->seed(CommissionRuleSeeder::class);
    }

    private function commissionOrder(array $data): Order
    {
        $order = Order::create($data);
        $product = Product::factory()->create(['mrp' => Money::decimal(Money::cents($data['total']) * 2)]);
        $order->lines()->create(['product_id' => $product->id, 'name' => $product->name, 'sku' => $product->sku, 'quantity' => 1,
            'mrp' => $product->mrp, 'unit_price' => $data['total'], 'total' => $data['total']]);

        return $order;
    }

    public function test_self_sponsorship_is_rejected(): void
    {
        $user = User::factory()->create(['role' => 'dealer', 'status' => 'active']);

        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage(__('portal.err_tree_self_sponsor'));

        ReferralTreePath::attachUser($user->id, $user->id);
    }

    public function test_circular_referral_cycle_is_detected_and_rejected(): void
    {
        // Chain: A -> B -> C
        $userA = User::factory()->create(['role' => 'dealer', 'status' => 'active']);
        ReferralTreePath::attachUser($userA->id, null);

        $userB = User::factory()->create(['role' => 'dealer', 'status' => 'active', 'sponsor_id' => $userA->id]);
        ReferralTreePath::attachUser($userB->id, $userA->id);

        $userC = User::factory()->create(['role' => 'dealer', 'status' => 'active', 'sponsor_id' => $userB->id]);
        ReferralTreePath::attachUser($userC->id, $userB->id);

        // Attempting to make C the sponsor of A must be rejected as an illegal cycle!
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage(__('portal.err_tree_circular_hierarchy'));

        ReferralTreePath::attachUser($userA->id, $userC->id);
    }

    public function test_referral_tree_guard_messages_follow_bangla_locale(): void
    {
        app()->setLocale('bn');
        $user = User::factory()->create(['role' => 'dealer', 'status' => 'active']);

        try {
            ReferralTreePath::attachUser($user->id, $user->id);
            $this->fail('Expected InvalidArgumentException for self-sponsorship');
        } catch (InvalidArgumentException $exception) {
            $this->assertSame(__('portal.err_tree_self_sponsor', [], 'bn'), $exception->getMessage());
        }
    }

    public function test_reparenting_subtree_updates_all_descendant_depths_correctly(): void
    {
        // Initial Tree: Root A -> B -> C -> D
        $rootA = User::factory()->create(['role' => 'dealer', 'status' => 'active']);
        ReferralTreePath::attachUser($rootA->id, null);

        $userB = User::factory()->create(['role' => 'dealer', 'status' => 'active', 'sponsor_id' => $rootA->id]);
        ReferralTreePath::attachUser($userB->id, $rootA->id);

        $userC = User::factory()->create(['role' => 'dealer', 'status' => 'active', 'sponsor_id' => $userB->id]);
        ReferralTreePath::attachUser($userC->id, $userB->id);

        $userD = User::factory()->create(['role' => 'dealer', 'status' => 'active', 'sponsor_id' => $userC->id]);
        ReferralTreePath::attachUser($userD->id, $userC->id);

        // Verify initial depths from Root A
        $this->assertEquals(1, ReferralTreePath::where('ancestor_id', $rootA->id)->where('descendant_id', $userB->id)->value('depth'));
        $this->assertEquals(2, ReferralTreePath::where('ancestor_id', $rootA->id)->where('descendant_id', $userC->id)->value('depth'));
        $this->assertEquals(3, ReferralTreePath::where('ancestor_id', $rootA->id)->where('descendant_id', $userD->id)->value('depth'));

        // New Leader E
        $leaderE = User::factory()->create(['role' => 'dealer', 'status' => 'active']);
        ReferralTreePath::attachUser($leaderE->id, null);

        // Move sub-tree rooted at B under Leader E
        ReferralTreePath::reparentUser($userB->id, $leaderE->id);

        // Root A must NO LONGER be ancestor of B, C, or D
        $this->assertFalse(ReferralTreePath::isDescendant($rootA->id, $userB->id));
        $this->assertFalse(ReferralTreePath::isDescendant($rootA->id, $userC->id));
        $this->assertFalse(ReferralTreePath::isDescendant($rootA->id, $userD->id));

        // Leader E must NOW be ancestor of B (depth 1), C (depth 2), D (depth 3)
        $this->assertEquals(1, ReferralTreePath::where('ancestor_id', $leaderE->id)->where('descendant_id', $userB->id)->value('depth'));
        $this->assertEquals(2, ReferralTreePath::where('ancestor_id', $leaderE->id)->where('descendant_id', $userC->id)->value('depth'));
        $this->assertEquals(3, ReferralTreePath::where('ancestor_id', $leaderE->id)->where('descendant_id', $userD->id)->value('depth'));

        // Internal sub-tree relationships B -> C (depth 1) and B -> D (depth 2) MUST be preserved
        $this->assertEquals(1, ReferralTreePath::where('ancestor_id', $userB->id)->where('descendant_id', $userC->id)->value('depth'));
        $this->assertEquals(2, ReferralTreePath::where('ancestor_id', $userB->id)->where('descendant_id', $userD->id)->value('depth'));
    }

    public function test_suspended_upline_does_not_receive_wallet_credit_and_allocation_is_held(): void
    {
        $service = app(CommissionService::class);

        // Suspended Sponsor
        $sponsor = User::factory()->create(['role' => 'dealer', 'status' => 'suspended']);
        ReferralTreePath::attachUser($sponsor->id, null);

        $buyer = User::factory()->create(['role' => 'dealer', 'status' => 'active', 'sponsor_id' => $sponsor->id]);
        ReferralTreePath::attachUser($buyer->id, $sponsor->id);

        $order = $this->commissionOrder([
            'idempotency_key' => (string) Str::uuid(),
            'payload_hash' => hash('sha256', 'order_suspended_upline'),
            'number' => 'ORD-SUSPENDED-UPLINE',
            'user_id' => $buyer->id,
            'status' => 'delivered',
            'total' => '10000.00',
            'paid' => '10000.00',
        ]);

        $allocations = $service->processOrder($order);

        // Direct referral allocation was created for audit, but marked 'held'
        $dirAlloc = CommissionAllocation::where('order_id', $order->id)->where('category', 'direct_referral')->first();
        $this->assertNotNull($dirAlloc);
        $this->assertEquals('held', $dirAlloc->status);

        // Suspended sponsor's wallet was NOT credited!
        $sponsorWallet = DB::table('wallets')->where('user_id', $sponsor->id)->first();
        $this->assertEquals(0.00, (float) ($sponsorWallet?->available ?? 0));
    }

    public function test_double_entry_journal_entry_is_posted_atomically(): void
    {
        $service = app(CommissionService::class);

        $sponsor = User::factory()->create(['role' => 'dealer', 'status' => 'active']);
        ReferralTreePath::attachUser($sponsor->id, null);

        $buyer = User::factory()->create(['role' => 'dealer', 'status' => 'active', 'sponsor_id' => $sponsor->id]);
        ReferralTreePath::attachUser($buyer->id, $sponsor->id);

        $order = $this->commissionOrder([
            'idempotency_key' => (string) Str::uuid(),
            'payload_hash' => hash('sha256', 'order_double_entry'),
            'number' => 'ORD-JOURNAL-001',
            'user_id' => $buyer->id,
            'status' => 'delivered',
            'total' => '10000.00',
            'paid' => '10000.00',
        ]);

        $service->processOrder($order);

        // Check journal entry created
        $journal = DB::table('journal_entries')->where('event_key', "commissions.order.{$order->id}")->first();
        $this->assertNotNull($journal);

        // Total debits must equal total credits in double-entry bookkeeping!
        $totalDebit = (float) DB::table('journal_lines')->where('entry_id', $journal->id)->sum('debit');
        $totalCredit = (float) DB::table('journal_lines')->where('entry_id', $journal->id)->sum('credit');

        // Self cashback (500) + Direct referral (1000) = 1500
        $this->assertEquals(1500.00, $totalDebit);
        $this->assertEquals(1500.00, $totalCredit);
    }

    public function test_zero_or_negative_cash_order_is_safely_ignored(): void
    {
        $service = app(CommissionService::class);
        $buyer = User::factory()->create(['role' => 'dealer', 'status' => 'active']);

        $order = $this->commissionOrder([
            'idempotency_key' => (string) Str::uuid(),
            'payload_hash' => hash('sha256', 'zero_order_test'),
            'number' => 'ORD-ZERO-001',
            'user_id' => $buyer->id,
            'status' => 'delivered',
            'total' => '100.00',
            'paid' => '100.00',
        ]);

        // Simulate an edge case where total evaluates to 0 or negative
        $order->total = '0.00';

        $allocations = $service->processOrder($order);
        $this->assertEmpty($allocations);
    }

    public function test_undelivered_orders_do_not_qualify_for_anti_whale_milestones(): void
    {
        $service = app(CommissionService::class);

        $leader = User::factory()->create(['role' => 'dealer', 'status' => 'active']);
        ReferralTreePath::attachUser($leader->id, null);

        $leg = User::factory()->create(['role' => 'dealer', 'status' => 'active', 'sponsor_id' => $leader->id]);
        ReferralTreePath::attachUser($leg->id, $leader->id);

        // Submitted but unpaid/undelivered order of ৳5,00,000
        $this->commissionOrder([
            'idempotency_key' => (string) Str::uuid(),
            'payload_hash' => hash('sha256', 'unpaid_order'),
            'number' => 'ORD-SUBMITTED-NOT-DELIVERED',
            'user_id' => $leg->id,
            'status' => 'submitted', // NOT delivered!
            'total' => '500000.00',
            'paid' => '0.00',
        ]);

        $eval = $service->evaluateAntiWhaleLegs($leader->id, 'bike');

        // Volume must be 0 because order is not delivered!
        $this->assertEquals(0.00, $eval['raw_team_volume']);
        $this->assertEquals(0.00, $eval['qualifying_volume']);
    }

    public function test_single_branch_whale_cannot_unlock_milestone_alone(): void
    {
        $service = app(CommissionService::class);

        $leader = User::factory()->create(['role' => 'dealer', 'status' => 'active']);
        ReferralTreePath::attachUser($leader->id, null);

        // Only ONE single frontline leg
        $whaleLeg = User::factory()->create(['role' => 'dealer', 'status' => 'active', 'sponsor_id' => $leader->id]);
        ReferralTreePath::attachUser($whaleLeg->id, $leader->id);

        // Whale leg does an astronomical ৳10 Crore (100,000,000) in sales!
        $this->commissionOrder([
            'idempotency_key' => (string) Str::uuid(),
            'payload_hash' => hash('sha256', 'super_whale_order'),
            'number' => 'ORD-SUPER-WHALE',
            'user_id' => $whaleLeg->id,
            'status' => 'delivered',
            'total' => '100000000.00',
            'paid' => '100000000.00',
        ]);

        $eval = $service->evaluateAntiWhaleLegs($leader->id, 'bike');

        // Total raw volume is 10 Crore
        $this->assertEquals(100000000.00, $eval['raw_team_volume']);

        // But qualifying volume is strictly capped at ৳8 Lakh!
        $this->assertEquals(800000.00, $eval['qualifying_volume']);

        // Bike milestone target is ৳60 Lakh, so achieved MUST BE FALSE!
        $this->assertFalse($eval['achieved']);
        $this->assertEquals(16.00, $eval['percentage']); // 8L / 50L = 16%
    }

    public function test_legacy_compression_setting_cannot_automatically_reassign_inactive_recipients(): void
    {
        $service = app(CommissionService::class);

        // Configure dynamic compression on d2d_multitier rule
        $rule = CommissionRuleParameter::where('rule_key', 'd2d_multitier')->first();
        if ($rule) {
            $rule->update([
                'parameters' => array_merge($rule->parameters ?? [], ['dynamic_compression' => true]),
            ]);
        }

        // Lineage: Leader A (active) -> Mid B (active) -> Sponsor C (suspended) -> Buyer D (active)
        $leaderA = User::factory()->create(['role' => 'dealer', 'status' => 'active']);
        ReferralTreePath::attachUser($leaderA->id, null);

        $midB = User::factory()->create(['role' => 'dealer', 'status' => 'active', 'sponsor_id' => $leaderA->id]);
        ReferralTreePath::attachUser($midB->id, $leaderA->id);

        $sponsorC = User::factory()->create(['role' => 'dealer', 'status' => 'suspended', 'sponsor_id' => $midB->id]);
        ReferralTreePath::attachUser($sponsorC->id, $midB->id);

        $buyerD = User::factory()->create(['role' => 'dealer', 'status' => 'active', 'sponsor_id' => $sponsorC->id]);
        ReferralTreePath::attachUser($buyerD->id, $sponsorC->id);

        $order = $this->commissionOrder([
            'idempotency_key' => (string) Str::uuid(),
            'payload_hash' => hash('sha256', 'dynamic_compression_order'),
            'number' => 'ORD-COMPRESS-001',
            'user_id' => $buyerD->id,
            'status' => 'delivered',
            'total' => '10000.00',
            'paid' => '10000.00',
        ]);

        $allocations = $service->processOrder($order);

        $this->assertDatabaseHas('commission_allocations', ['order_id' => $order->id, 'user_id' => $sponsorC->id, 'category' => 'direct_referral', 'status' => 'held', 'amount' => '1000.00']);
        $this->assertDatabaseHas('wallets', ['user_id' => $midB->id, 'available' => '200.00']);
        $this->assertDatabaseHas('wallets', ['user_id' => $leaderA->id, 'available' => '100.00']);
        $this->assertDatabaseMissing('wallets', ['user_id' => $sponsorC->id]);
    }

    public function test_exact_penny_remainder_reconciliation_eliminates_roundoff_drift(): void
    {
        $service = app(CommissionService::class);
        $buyer = User::factory()->create(['role' => 'dealer', 'status' => 'active']);

        // Odd fractional amount: ৳333.33
        $order = $this->commissionOrder([
            'idempotency_key' => (string) Str::uuid(),
            'payload_hash' => hash('sha256', 'penny_drift_test_order'),
            'number' => 'ORD-PENNY-001',
            'user_id' => $buyer->id,
            'status' => 'delivered',
            'total' => '333.33',
            'paid' => '333.33',
        ]);

        $allocations = $service->processOrder($order);

        // 10% of 333.33 is exactly ৳33.33
        $expectedTotalAreaPool = 33.33;

        $areaAllocations = CommissionAllocation::where('order_id', $order->id)
            ->whereIn('category', ['area_management', 'area_division', 'area_district', 'area_upazila', 'area_union'])
            ->get();

        $sumArea = round($areaAllocations->sum(fn ($a) => (float) $a->amount), 2);

        // Zero penny drift: sum must exactly equal ৳33.33!
        $this->assertEquals($expectedTotalAreaPool, $sumArea);
    }

    public function test_unassigned_area_dealer_allocates_cleanly_to_company_geographic_reserve(): void
    {
        $service = app(CommissionService::class);

        // Buyer in an unassigned division
        $buyer = User::factory()->create([
            'role' => 'dealer',
            'status' => 'active',
            'division' => 'UnassignedSylhet',
        ]);

        $order = $this->commissionOrder([
            'idempotency_key' => (string) Str::uuid(),
            'payload_hash' => hash('sha256', 'unassigned_area_order'),
            'number' => 'ORD-UNASSIGNED-001',
            'user_id' => $buyer->id,
            'status' => 'delivered',
            'total' => '5000.00',
            'paid' => '5000.00',
        ]);

        $service->processOrder($order);

        $divAlloc = CommissionAllocation::where('order_id', $order->id)
            ->where('category', 'area_division')
            ->first();

        $this->assertNotNull($divAlloc);
        $this->assertEquals('unassigned_reserve', $divAlloc->status);
        $this->assertNull($divAlloc->user_id);
        $this->assertTrue($divAlloc->metadata['unassigned'] ?? false);
        $this->assertEquals('company_geographic_reserve', $divAlloc->metadata['retained_by'] ?? '');
    }

    public function test_order_cancellation_and_clawback_reverses_commissions_and_updates_ledger(): void
    {
        $service = app(CommissionService::class);

        $sponsor = User::factory()->create(['role' => 'dealer', 'status' => 'active']);
        ReferralTreePath::attachUser($sponsor->id, null);

        $buyer = User::factory()->create(['role' => 'dealer', 'status' => 'active', 'sponsor_id' => $sponsor->id]);
        ReferralTreePath::attachUser($buyer->id, $sponsor->id);

        $order = $this->commissionOrder([
            'idempotency_key' => (string) Str::uuid(),
            'payload_hash' => hash('sha256', 'clawback_test_order'),
            'number' => 'ORD-CLAWBACK-001',
            'user_id' => $buyer->id,
            'status' => 'delivered',
            'total' => '10000.00',
            'paid' => '10000.00',
        ]);

        $service->processOrder($order);

        // Verify initial wallet credits: Buyer = 500 cashback, Sponsor = 1000 direct referral
        $buyerWalletBefore = (float) DB::table('wallets')->where('user_id', $buyer->id)->value('available');
        $sponsorWalletBefore = (float) DB::table('wallets')->where('user_id', $sponsor->id)->value('available');
        $this->assertEquals(500.00, $buyerWalletBefore);
        $this->assertEquals(1000.00, $sponsorWalletBefore);

        // Buyer's rank volume increased
        $buyerRankBefore = (float) DB::table('dealer_ranks')->where('user_id', $buyer->id)->value('lifetime_purchase_total');
        $this->assertEquals(10000.00, $buyerRankBefore);

        // Now reverse order (e.g. customer returned products)
        $reversals = $service->reverseOrder($order, 'Returned damaged goods');

        $this->assertNotEmpty($reversals);

        // Wallets clawed back!
        $buyerWalletAfter = (float) DB::table('wallets')->where('user_id', $buyer->id)->value('available');
        $sponsorWalletAfter = (float) DB::table('wallets')->where('user_id', $sponsor->id)->value('available');
        $this->assertEquals(0.00, $buyerWalletAfter);
        $this->assertEquals(0.00, $sponsorWalletAfter);

        // Allocations marked 'reversed'
        $allReversed = CommissionAllocation::where('order_id', $order->id)->where('status', 'reversed')->count();
        $this->assertGreaterThan(0, $allReversed);

        // Reversal double-entry ledger entry posted!
        $revJournal = DB::table('journal_entries')->where('event_key', 'commissions.reversal.'.DB::table('commission_refunds')->where('order_id', $order->id)->value('id'))->first();
        $this->assertNotNull($revJournal);

        $revDebit = (float) DB::table('journal_lines')->where('entry_id', $revJournal->id)->where('account', 'wallet_liability')->sum('debit');
        $revCredit = (float) DB::table('journal_lines')->where('entry_id', $revJournal->id)->where('account', 'commission_clawback_recovery')->value('credit');
        $this->assertEquals(1500.00, $revDebit);
        $this->assertEquals(1500.00, $revCredit);

        // Buyer volume deducted back to 0!
        $buyerRankAfter = (float) DB::table('dealer_ranks')->where('user_id', $buyer->id)->value('lifetime_purchase_total');
        $this->assertEquals(0.00, $buyerRankAfter);
    }

    public function test_delivered_order_status_refreshes_milestones_idempotently(): void
    {
        $service = app(CommissionService::class);

        $leader = User::factory()->create(['role' => 'dealer', 'status' => 'active']);
        ReferralTreePath::attachUser($leader->id, null);

        $buyer = User::factory()->create(['role' => 'dealer', 'status' => 'active', 'sponsor_id' => $leader->id]);
        ReferralTreePath::attachUser($buyer->id, $leader->id);

        // Step 1: Order is paid, but status is 'approved' (not yet delivered)
        $order = $this->commissionOrder([
            'idempotency_key' => (string) Str::uuid(),
            'payload_hash' => hash('sha256', 'idempotent_refresh_order'),
            'number' => 'ORD-DELIV-REFRESH',
            'user_id' => $buyer->id,
            'status' => 'approved',
            'total' => '50000.00',
            'paid' => '50000.00',
        ]);

        $service->processOrder($order);

        // Fully paid orders wait for delivery before earnings are recognized.
        $this->assertEquals(0, CommissionAllocation::where('order_id', $order->id)->count());

        // Anti-whale milestone is still 0 because order is NOT delivered
        $evalBefore = $service->evaluateAntiWhaleLegs($leader->id, 'bike');
        $this->assertEquals(0.00, $evalBefore['raw_team_volume']);

        // Step 2: Order is delivered!
        $order->update(['status' => 'delivered']);

        // Calling processOrder again (e.g. from OrderService::deliver)
        $allocationsAgain = $service->processOrder($order);

        // Recognition creates exactly one set of allocations after delivery.
        $this->assertEquals(14, CommissionAllocation::where('order_id', $order->id)->count());
        $service->processOrder($order);
        $this->assertEquals(14, CommissionAllocation::where('order_id', $order->id)->count());

        // Now Car & Bike milestone progress is refreshed and reflects ৳50,000!
        $progress = DB::table('reward_milestone_progress')
            ->where('user_id', $leader->id)
            ->where('milestone_type', 'bike')
            ->first();

        $this->assertNotNull($progress);
        $this->assertEquals(50000.00, (float) $progress->raw_team_volume);
        $this->assertEquals(50000.00, (float) $progress->qualifying_volume);
    }

    public function test_pro_rata_partial_order_refund_reverses_commissions_proportionally(): void
    {
        $service = app(CommissionService::class);

        $sponsor = User::factory()->create(['role' => 'dealer', 'status' => 'active']);
        ReferralTreePath::attachUser($sponsor->id, null);

        $buyer = User::factory()->create(['role' => 'dealer', 'status' => 'active', 'sponsor_id' => $sponsor->id]);
        ReferralTreePath::attachUser($buyer->id, $sponsor->id);

        $order = $this->commissionOrder([
            'idempotency_key' => (string) Str::uuid(),
            'payload_hash' => hash('sha256', 'partial_refund_order'),
            'number' => 'ORD-PARTIAL-001',
            'user_id' => $buyer->id,
            'status' => 'delivered',
            'total' => '10000.00',
            'paid' => '10000.00',
        ]);

        $service->processOrder($order);

        // Initial credits: Sponsor 1000 (10%), Buyer 500 (5%)
        $this->assertEquals(1000.00, (float) DB::table('wallets')->where('user_id', $sponsor->id)->value('available'));
        $this->assertEquals(500.00, (float) DB::table('wallets')->where('user_id', $buyer->id)->value('available'));

        // Customer returns ৳3,000 worth of goods (30% partial refund)
        $reversals = $service->reverseOrderPartial($order, 3000.00, '30% partial customer return');

        $this->assertNotEmpty($reversals);

        // Sponsor deducted 30% of 1000 = ৳300 -> Remaining wallet: ৳700
        $this->assertEquals(700.00, (float) DB::table('wallets')->where('user_id', $sponsor->id)->value('available'));

        // Buyer deducted 30% of 500 = ৳150 -> Remaining wallet: ৳350
        $this->assertEquals(350.00, (float) DB::table('wallets')->where('user_id', $buyer->id)->value('available'));

        // Buyer's rank volume decremented from 10,000 by 3,000 -> 7,000
        $buyerRank = (float) DB::table('dealer_ranks')->where('user_id', $buyer->id)->value('lifetime_purchase_total');
        $this->assertEquals(7000.00, $buyerRank);
    }

    public function test_unrecovered_clawback_debt_is_automatically_cleared_by_future_commissions(): void
    {
        $service = app(CommissionService::class);

        $dealer = User::factory()->create(['role' => 'dealer', 'status' => 'active']);
        ReferralTreePath::attachUser($dealer->id, null);

        $customer = User::factory()->create(['role' => 'dealer', 'status' => 'active', 'sponsor_id' => $dealer->id]);
        ReferralTreePath::attachUser($customer->id, $dealer->id);

        $order1 = $this->commissionOrder([
            'idempotency_key' => (string) Str::uuid(),
            'payload_hash' => hash('sha256', 'order1_debt_test'),
            'number' => 'ORD-DEBT-001',
            'user_id' => $customer->id,
            'status' => 'delivered',
            'total' => '10000.00',
            'paid' => '10000.00',
        ]);

        $service->processOrder($order1);

        // Dealer earned ৳1,000 direct referral
        $this->assertEquals(1000.00, (float) DB::table('wallets')->where('user_id', $dealer->id)->value('available'));

        // Dealer withdraws or spends ৳800, leaving only ৳200
        DB::table('wallets')->where('user_id', $dealer->id)->update(['available' => 200.00]);

        // Order 1 is cancelled! Clawback needs ৳1,000
        $service->reverseOrder($order1, 'Order 1 cancelled');

        // Available balance is safely zeroed out without violating funds_nonnegative check
        $this->assertEquals(0.00, (float) DB::table('wallets')->where('user_id', $dealer->id)->value('available'));

        // Unrecovered deficit of ৳800 is recorded in reversed allocation
        $revAlloc = CommissionAllocation::where('order_id', $order1->id)->where('category', 'direct_referral')->first();
        $this->assertEquals(800.00, (float) ($revAlloc->metadata['unrecovered_deficit'] ?? 0));

        // Now Dealer earns ৳1,200 commission on a NEW order!
        $service->creditWallet($dealer->id, 1200.00);

        // ৳800 debt is automatically settled, so wallet available is now exactly ৳400!
        $this->assertEquals(400.00, (float) DB::table('wallets')->where('user_id', $dealer->id)->value('available'));

        // Deficit on reversed allocation is now cleared to 0.00!
        $revAlloc->refresh();
        $this->assertEquals(0.00, (float) ($revAlloc->metadata['unrecovered_deficit'] ?? 0));
    }

    public function test_monthly_salary_pool_settles_and_distributes_to_qualified_dealers(): void
    {
        $service = app(CommissionService::class);
        $this->travelTo(now()->startOfMonth()->subMonth()->addDays(14));
        $period = now()->format('Y-m');

        // Setup 2 dealers
        $dealer1 = User::factory()->create(['role' => 'dealer', 'status' => 'active']);
        $dealer2 = User::factory()->create(['role' => 'dealer', 'status' => 'active']);

        // Dealer 1 has ৳15,000 monthly purchases (qualified >= ৳10,000)
        DealerRank::create([
            'user_id' => $dealer1->id,
            'current_rank' => 'dealer',
            'monthly_purchase_total' => 15000.00,
            'lifetime_purchase_total' => 15000.00,
        ]);

        // Dealer 2 has ৳4,000 monthly purchases (unqualified < ৳10,000)
        DealerRank::create([
            'user_id' => $dealer2->id,
            'current_rank' => 'dealer',
            'monthly_purchase_total' => 4000.00,
            'lifetime_purchase_total' => 4000.00,
        ]);

        // Create an order generating 2% salary pool: ৳20,000 * 2% = ৳400
        $order = $this->commissionOrder([
            'idempotency_key' => (string) Str::uuid(),
            'payload_hash' => hash('sha256', 'salary_pool_order'),
            'number' => 'ORD-SALARY-001',
            'user_id' => $dealer1->id,
            'status' => 'delivered',
            'total' => '20000.00',
            'paid' => '20000.00',
        ]);
        $service->processOrder($order);

        // Run Month-End Salary Settlement!
        $this->travelTo(now()->addMonth()->startOfMonth());
        $result = $service->settleMonthlySalaryPool($period);

        $this->assertEquals('settled', $result['status']);
        $this->assertEquals(1, $result['qualifiers_count']);
        $this->assertEquals(400.00, (float) $result['total_pool']);

        // Only Dealer 1 receives the ৳400 salary!
        $d1Wallet = (float) DB::table('wallets')->where('user_id', $dealer1->id)->value('available');
        // Dealer 1 also got 5% own cashback (1000) + 400 salary = 1400
        $this->assertEquals(1400.00, $d1Wallet);

        // Dealer 2 received 0
        $d2Wallet = (float) DB::table('wallets')->where('user_id', $dealer2->id)->value('available');
        $this->assertEquals(0.00, $d2Wallet);

        // Double-entry journal entry was posted
        $journal = DB::table('journal_entries')->where('event_key', 'commissions.settlement.'.CommissionAllocation::where('order_id', $order->id)->where('category', 'dealer_monthly_salary_pool')->value('id').'.'.$dealer1->id.'.salary')->first();
        $this->assertNotNull($journal);
    }

    public function test_rank_royalty_pool_settles_across_four_leadership_tiers(): void
    {
        $service = app(CommissionService::class);
        $this->travelTo(now()->startOfMonth()->subMonth()->addDays(14));
        $period = now()->format('Y-m');

        // Create 2 MO leaders
        $mo1 = User::factory()->create(['role' => 'dealer', 'status' => 'active']);
        $mo2 = User::factory()->create(['role' => 'dealer', 'status' => 'active']);

        DealerRank::create(['user_id' => $mo1->id, 'current_rank' => 'mo', 'achieved_at' => now()]);
        DealerRank::create(['user_id' => $mo2->id, 'current_rank' => 'mo', 'achieved_at' => now()]);

        // Generate 4% rank royalty pool from a ৳1,00,000 order = ৳4,000 total pool
        $buyer = User::factory()->create(['role' => 'dealer', 'status' => 'active']);
        $order = $this->commissionOrder([
            'idempotency_key' => (string) Str::uuid(),
            'payload_hash' => hash('sha256', 'royalty_pool_order'),
            'number' => 'ORD-ROYALTY-001',
            'user_id' => $buyer->id,
            'status' => 'delivered',
            'total' => '10000.00',
            'paid' => '10000.00',
        ]);
        $service->processOrder($order);

        // Run Rank Royalty Settlement!
        $this->travelTo(now()->addMonth()->startOfMonth());
        $result = $service->settleRankRoyaltyPool($period);

        $this->assertEquals('settled', $result['status']);
        $this->assertEquals(400.00, (float) $result['total_pool']);

        // MO tier pool = 4000 / 4 = ৳1,000 split equally between 2 MOs = ৳500 each!
        $this->assertEquals(50.00, (float) DB::table('wallets')->where('user_id', $mo1->id)->value('available'));
        $this->assertEquals(50.00, (float) DB::table('wallets')->where('user_id', $mo2->id)->value('available'));

        // Journal entry was posted
        $journal = DB::table('journal_entries')->where('event_key', 'commissions.settlement.'.CommissionAllocation::where('order_id', $order->id)->where('category', 'rank_royalty_pool')->value('id').'.'.$mo1->id.'.royalty')->first();
        $this->assertNotNull($journal);
    }

    public function test_one_time_rank_incentive_claim_and_payout(): void
    {
        $service = app(CommissionService::class);

        $leader = User::factory()->create(['role' => 'dealer', 'status' => 'active']);
        $rank = DealerRank::create([
            'user_id' => $leader->id,
            'current_rank' => 'mo',
            'achieved_at' => now(),
            'one_time_incentive_paid' => false,
        ]);

        $funding = $this->commissionOrder(['idempotency_key' => (string) Str::uuid(), 'payload_hash' => hash('sha256', 'incentive-funding'), 'number' => 'INCENTIVE-FUNDING', 'user_id' => User::factory()->create()->id, 'status' => 'delivered', 'total' => '250000.00', 'paid' => '250000.00']);
        $service->processOrder($funding);
        // Claim the funded one-time incentive.
        $claim = $service->claimOneTimeRankIncentive($leader->id, 5000.00);

        $this->assertTrue($claim['success']);
        $this->assertEquals(5000.00, $claim['amount']);

        // Wallet was credited ৳5,000!
        $this->assertEquals(5000.00, (float) DB::table('wallets')->where('user_id', $leader->id)->value('available'));

        // Double-entry journal posted
        $journal = DB::table('journal_entries')->where('event_key', 'commissions.settlement.'.CommissionAllocation::where('order_id', $funding->id)->where('category', 'rank_incentive_pool')->value('id').'.'.$leader->id.'.rank_mo')->first();
        $this->assertNotNull($journal);

        // Claiming a second time is rejected!
        $claimAgain = $service->claimOneTimeRankIncentive($leader->id);
        $this->assertFalse($claimAgain['success']);
        $this->assertStringContainsString('already paid', $claimAgain['message']);
    }

    public function test_cooling_off_period_holds_commissions_and_batch_release_matures_them(): void
    {
        $service = app(CommissionService::class);

        // Configure 7-day statutory cooling-off window
        $coolingRule = CommissionRuleParameter::getRule('commission_cooling_off');
        if ($coolingRule) {
            $coolingRule->update(['parameters' => ['cooling_off_days' => 7]]);
        }

        $sponsor = User::factory()->create(['role' => 'dealer', 'status' => 'active']);
        ReferralTreePath::attachUser($sponsor->id, null);

        $buyer = User::factory()->create(['role' => 'dealer', 'status' => 'active', 'sponsor_id' => $sponsor->id]);
        ReferralTreePath::attachUser($buyer->id, $sponsor->id);

        $order = $this->commissionOrder([
            'idempotency_key' => (string) Str::uuid(),
            'payload_hash' => hash('sha256', 'cooling_off_order'),
            'number' => 'ORD-COOLING-001',
            'user_id' => $buyer->id,
            'status' => 'delivered',
            'total' => '10000.00',
            'paid' => '10000.00',
        ]);

        $allocations = $service->processOrder($order);

        // Buyer cashback (500) and Sponsor referral (1000) are 'maturing'
        $buyerAlloc = CommissionAllocation::where('order_id', $order->id)->where('user_id', $buyer->id)->first();
        $sponsorAlloc = CommissionAllocation::where('order_id', $order->id)->where('user_id', $sponsor->id)->first();

        $this->assertEquals('maturing', $buyerAlloc->status);
        $this->assertEquals('maturing', $sponsorAlloc->status);
        $this->assertNotNull($buyerAlloc->maturation_due_at);

        // Available wallets remain 0.00, while held balances reflect pending allocations!
        $buyerWallet = DB::table('wallets')->where('user_id', $buyer->id)->first();
        $sponsorWallet = DB::table('wallets')->where('user_id', $sponsor->id)->first();

        $this->assertEquals(0.00, (float) ($buyerWallet->available ?? 0));
        $this->assertEquals(500.00, (float) ($buyerWallet->held ?? 0));
        $this->assertEquals(0.00, (float) ($sponsorWallet->available ?? 0));
        $this->assertEquals(1000.00, (float) ($sponsorWallet->held ?? 0));

        // Attempting to release matured allocations prematurely releases nothing
        $prematureRelease = $service->releaseMaturedCommissions();
        $this->assertEquals('no_matured_commissions', $prematureRelease['status']);

        // Fast forward maturation time past the 7-day window
        foreach (CommissionAllocation::where('order_id', $order->id)->where('status', 'maturing')->get() as $al) {
            $meta = $al->metadata ?? [];
            $meta['maturation_due_at'] = now()->subMinute()->toIso8601String();
            $al->update(['metadata' => $meta]);
        }

        // Run Batch Maturation Release!
        $maturedRelease = $service->releaseMaturedCommissions();
        $this->assertEquals('success', $maturedRelease['status']);
        $this->assertEquals(2, $maturedRelease['released_count']);
        $this->assertEquals(1500.00, (float) $maturedRelease['total_amount']);

        // Wallets transitioned from held to available atomically!
        $buyerWalletAfter = DB::table('wallets')->where('user_id', $buyer->id)->first();
        $sponsorWalletAfter = DB::table('wallets')->where('user_id', $sponsor->id)->first();

        $this->assertEquals(500.00, (float) $buyerWalletAfter->available);
        $this->assertEquals(0.00, (float) $buyerWalletAfter->held);
        $this->assertEquals(1000.00, (float) $sponsorWalletAfter->available);
        $this->assertEquals(0.00, (float) $sponsorWalletAfter->held);

        // Double-entry journal entry was posted
        $this->assertTrue(DB::table('journal_entries')->where('event_key', 'like', 'commissions.matured.%')->exists());
    }

    public function test_order_cancellation_during_cooling_off_voids_held_balance_without_debt(): void
    {
        $service = app(CommissionService::class);

        // Enable cooling off
        $coolingRule = CommissionRuleParameter::getRule('commission_cooling_off');
        if ($coolingRule) {
            $coolingRule->update(['parameters' => ['cooling_off_days' => 14]]);
        }

        $sponsor = User::factory()->create(['role' => 'dealer', 'status' => 'active']);
        ReferralTreePath::attachUser($sponsor->id, null);

        $buyer = User::factory()->create(['role' => 'dealer', 'status' => 'active', 'sponsor_id' => $sponsor->id]);
        ReferralTreePath::attachUser($buyer->id, $sponsor->id);

        $order = $this->commissionOrder([
            'idempotency_key' => (string) Str::uuid(),
            'payload_hash' => hash('sha256', 'cooling_off_clawback_order'),
            'number' => 'ORD-COOLING-CLAWBACK',
            'user_id' => $buyer->id,
            'status' => 'delivered',
            'total' => '10000.00',
            'paid' => '10000.00',
        ]);

        $service->processOrder($order);

        // Sponsor has 1000 in held balance
        $sponsorWalletBefore = DB::table('wallets')->where('user_id', $sponsor->id)->first();
        $this->assertEquals(1000.00, (float) $sponsorWalletBefore->held);
        $this->assertEquals(0.00, (float) $sponsorWalletBefore->available);

        // Order cancelled within cooling-off window
        $reversals = $service->reverseOrder($order, 'Returned within cooling-off period');

        // Held balance safely relieved to 0.00, available remains 0.00, ZERO deficit created!
        $sponsorWalletAfter = DB::table('wallets')->where('user_id', $sponsor->id)->first();
        $this->assertEquals(0.00, (float) $sponsorWalletAfter->held);
        $this->assertEquals(0.00, (float) $sponsorWalletAfter->available);

        $revAlloc = CommissionAllocation::where('order_id', $order->id)->where('user_id', $sponsor->id)->first();
        $this->assertEquals('reversed', $revAlloc->status);
        $this->assertEquals(0.00, (float) ($revAlloc->metadata['unrecovered_deficit'] ?? 0));
    }

    public function test_what_if_simulation_calculates_variance_and_enforces_cap_without_db_mutations(): void
    {
        $service = app(CommissionService::class);

        $initialAllocationsCount = CommissionAllocation::count();
        $initialOrdersCount = Order::count();
        $initialJournalsCount = DB::table('journal_entries')->count();

        // 1. Simulation within 40% cap (Total: 4+10+2+1+8+2+4+2+2+2 = 37.0% <= 40%)
        $simCompliant = $service->simulatePlan([
            'own_dealer_cashback' => 4.0,
            'direct_referral' => 10.0,
            'area_dealer_pool' => 8.0,
        ]);

        $this->assertFalse($simCompliant['exceeds_cap']);
        $this->assertGreaterThan(0, $simCompliant['gross_sales_tested']);
        $this->assertGreaterThan(0, $simCompliant['simulated']['total_commissions']);
        $this->assertGreaterThan(0, $simCompliant['simulated']['company_retention']);
        $this->assertCount(13, $simCompliant['slices_comparison']);
        $this->assertContains('sub_dealer_salary', array_column($simCompliant['slices_comparison'], 'key'));
        $this->assertContains('sub_dealer_tour', array_column($simCompliant['slices_comparison'], 'key'));

        // 2. Simulation exceeding 40% cap
        $simExcessive = $service->simulatePlan([
            'own_dealer_cashback' => 20.0,
            'direct_referral' => 25.0,
        ]);

        $this->assertTrue($simExcessive['exceeds_cap']);
        $this->assertGreaterThan(40.0, $simExcessive['total_proposed_pct']);

        // In-memory purity: zero DB mutations occurred during What-If simulation
        $this->assertEquals($initialAllocationsCount, CommissionAllocation::count());
        $this->assertEquals($initialOrdersCount, Order::count());
        $this->assertEquals($initialJournalsCount, DB::table('journal_entries')->count());
    }

    public function test_paid_as_rank_demotes_inactive_leaders_after_grace_period_expires(): void
    {
        $service = app(CommissionService::class);

        $leader = User::factory()->create(['role' => 'dealer', 'status' => 'active']);

        // Leader promoted to MO 10 days ago (within 30-day grace period), has low monthly purchases (৳1,000)
        $rank = DealerRank::create([
            'user_id' => $leader->id,
            'current_rank' => 'mo',
            'achieved_at' => now()->subDays(10),
            'monthly_purchase_total' => 1000.00,
        ]);

        // Within grace period: Paid-As rank is protected as MO!
        $evalGrace = $service->evaluatePaidAsRank($leader->id);
        $this->assertTrue($evalGrace['in_grace_period']);
        $this->assertEquals('mo', $evalGrace['paid_as_rank']);

        // Grace period expired (achieved 45 days ago) without meeting ৳5,000 monthly quota
        $rank->update(['achieved_at' => now()->subDays(45)]);

        $evalExpired = $service->evaluatePaidAsRank($leader->id);
        $this->assertFalse($evalExpired['in_grace_period']);
        $this->assertEquals('dealer', $evalExpired['paid_as_rank']);
    }

    public function test_configured_payout_withholding_calculation_and_command_settlements(): void
    {
        $service = app(CommissionService::class);

        $this->assertEquals('0.00', $service->calculatePayoutTds(10000.00)['tds_amount']);
        CommissionRuleParameter::where('rule_key', 'payout_tax_withholding')->update(['percentage' => '5.00']);
        // Preview an explicitly configured 5% withholding.
        $payoutCalc = $service->calculatePayoutTds(10000.00);

        $this->assertEquals(10000.00, $payoutCalc['gross_amount']);
        $this->assertEquals(5.00, $payoutCalc['tds_pct']);
        $this->assertEquals(500.00, $payoutCalc['tds_amount']);
        $this->assertEquals(9500.00, $payoutCalc['net_amount']);

        // Custom rate e.g. 10%
        $customCalc = $service->calculatePayoutTds(10000.00, 10.00);
        $this->assertEquals(1000.00, $customCalc['tds_amount']);
        $this->assertEquals(9000.00, $customCalc['net_amount']);

        // Test API command endpoint with finance staff
        $financeStaff = User::factory()->create([
            'role' => 'finance',
            'status' => 'active',
        ]);

        $response = $this->actingAs($financeStaff)->postJson('/commands/commissions/simulate', [
            'overrides' => ['direct_referral' => 11.0],
        ]);

        $response->assertStatus(200);
        $response->assertJsonStructure([
            'gross_sales_tested',
            'total_proposed_pct',
            'exceeds_cap',
            'baseline',
            'simulated',
            'variance',
            'slices_comparison',
        ]);
    }
}
