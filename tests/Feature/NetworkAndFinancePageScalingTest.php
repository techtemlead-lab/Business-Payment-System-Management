<?php

namespace Tests\Feature;

use App\Domain\Accounts\PartnerDashboardHome;
use App\Domain\Commissions\CommissionPosting;
use App\Domain\Commissions\CommissionReversal;
use App\Domain\Commissions\CommissionSettlement;
use App\Domain\Finance\ReserveService;
use App\Domain\Orders\OrderService;
use App\Domain\Pricing\ProductPricing;
use App\Http\Middleware\HandleInertiaRequests;
use App\Http\Portals\Shared\Sections\NetworkSection;
use App\Http\Portals\Shared\Sections\SectionContext;
use App\Models\CommissionAllocation;
use App\Models\Order;
use App\Models\Product;
use App\Models\ReferralTreePath;
use App\Models\User;
use App\Support\Money;
use Database\Seeders\CommissionRuleSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\TestCase;

class NetworkAndFinancePageScalingTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->withoutVite();
        $this->travelTo(now()->setDate(2026, 10, 17)->setTime(12, 0));
        $this->seed(CommissionRuleSeeder::class);
    }

    private function partner(?User $sponsor, string $role = 'dealer'): User
    {
        $user = User::factory()->create(['role' => $role, 'status' => 'active', 'sponsor_id' => $sponsor?->id]);
        ReferralTreePath::attachUser($user->id, $sponsor?->id);

        return $user;
    }

    /**
     * Adds many direct members under one sponsor with plain inserts, so a big team is quick to build.
     *
     * @return list<int>
     */
    private function bulkTeam(User $sponsor, int $count): array
    {
        $now = now();
        $rows = [];
        foreach (range(1, $count) as $index) {
            $rows[] = ['name' => 'Member '.$index, 'email' => 'bulk'.$index.'-'.Str::random(6).'@example.test', 'password' => 'x',
                'role' => 'sub_dealer', 'status' => 'active', 'sponsor_id' => $sponsor->id, 'dealer_code' => 'BULK-'.$index,
                'created_at' => $now, 'updated_at' => $now];
        }
        foreach (array_chunk($rows, 500) as $chunk) {
            DB::table('users')->insert($chunk);
        }
        $ids = DB::table('users')->where('sponsor_id', $sponsor->id)->where('dealer_code', 'like', 'BULK-%')->orderBy('id')->pluck('id')->map(fn ($id): int => (int) $id)->all();
        DB::statement('INSERT INTO referral_tree_paths (ancestor_id, descendant_id, depth, created_at, updated_at)
            SELECT u.id, u.id, 0, NOW(), NOW() FROM users u WHERE u.sponsor_id = ? AND u.dealer_code LIKE ?
            UNION ALL
            SELECT p.ancestor_id, u.id, p.depth + 1, NOW(), NOW() FROM users u JOIN referral_tree_paths p ON p.descendant_id = u.sponsor_id
            WHERE u.sponsor_id = ? AND u.dealer_code LIKE ?', [$sponsor->id, 'BULK-%', $sponsor->id, 'BULK-%']);

        return $ids;
    }

    private function postedOrder(User $buyer, string $total = '100.00'): Order
    {
        $product = Product::factory()->create(['mrp' => '200.00', 'dealer_price' => '100.00', 'sub_dealer_price' => '120.00']);
        $order = app(OrderService::class)->create($buyer, ['items' => [['product_id' => $product->id, 'quantity' => 1]], 'idempotency_key' => (string) Str::uuid()]);
        $order->update(['status' => 'delivered', 'paid' => $total]);
        app(CommissionPosting::class)->process($order);

        return $order;
    }

    private function inertiaVersion(): string
    {
        return (string) (new HandleInertiaRequests)->version(Request::create('/'));
    }

    public function test_dealer_network_sends_the_whole_small_team_with_its_size(): void
    {
        $dealer = $this->partner(null);
        $left = $this->partner($dealer);
        $right = $this->partner($dealer);
        $grandchild = $this->partner($left, 'sub_dealer');
        $this->partner(null);

        $this->actingAs($dealer)->onPortal('dealer')->get('/network')->assertOk()->assertInertia(fn (Assert $page) => $page
            ->where('data.dealers', function ($dealers) use ($dealer, $left, $right, $grandchild): bool {
                $ids = collect($dealers)->pluck('id')->all();

                return $ids[0] === $dealer->id && collect($ids)->sort()->values()->all() === collect([$dealer->id, $left->id, $right->id, $grandchild->id])->sort()->values()->all();
            })
            ->where('data.dealers_truncated', false)
            ->where('data.dealers_total', 4)
            ->where('data.team_summary.members', 3)
            ->missing('data.members'));
    }

    public function test_a_large_team_is_capped_on_the_page_and_counted_in_full(): void
    {
        $dealer = $this->partner(null);
        $subDealer = $this->partner($dealer, 'sub_dealer');
        $team = $this->bulkTeam($subDealer, 2501);

        $data = $this->actingAs($dealer)->onPortal('dealer')
            ->withHeaders(['X-Inertia' => 'true', 'X-Inertia-Version' => $this->inertiaVersion()])
            ->get('/network')->assertOk()->json('props.data');
        $this->assertCount(2500, $data['dealers']);
        $this->assertTrue($data['dealers_truncated']);
        $this->assertSame(2503, $data['dealers_total']);
        $this->assertSame([$dealer->id, $subDealer->id, $team[0]], array_slice(array_column($data['dealers'], 'id'), 0, 3));

        $request = Request::create('/network');
        $props = app(NetworkSection::class)->data(new SectionContext($request, $subDealer, 'subdealer', 'network', false, false, 'subdealer',
            Order::query(), app(ProductPricing::class)), []);
        $this->assertCount(2500, $props['members']);
        $this->assertSame(2501, $props['members_total']);
        $this->assertTrue($props['members_truncated']);
        $this->assertSame(array_slice($team, 0, 2500), $props['members']->pluck('id')->map(fn ($id): int => (int) $id)->all());
    }

    public function test_staff_partner_tree_opens_with_the_top_levels_and_grows_with_show_more(): void
    {
        $owner = User::factory()->staff()->create();
        $top = $this->partner(null);
        $middle = $this->partner($top);
        $team = $this->bulkTeam($middle, 400);
        $this->postedOrder(User::findOrFail($team[399]), '120.00');

        $data = $this->actingAs($owner)->onPortal('sadmin')
            ->withHeaders(['X-Inertia' => 'true', 'X-Inertia-Version' => $this->inertiaVersion()])
            ->get('/network-tree')->assertOk()->json('props.data');
        $this->assertCount(NetworkSection::TreeFirstLoad, $data['dealers']);
        $this->assertTrue($data['dealers_truncated']);
        $this->assertSame(402, $data['dealers_total']);
        $this->assertSame(NetworkSection::TreeFirstLoad * 2, $data['dealers_next_limit']);
        $this->assertSame([$top->id, $middle->id], array_slice(array_column($data['dealers'], 'id'), 0, 2), 'The top of the tree comes first, so every loaded partner has its sponsor loaded.');
        $byId = collect($data['dealers'])->keyBy('id');
        $this->assertSame(401, $byId[$top->id]['team_size'], 'Team size counts the whole branch, loaded or not.');
        $this->assertSame(1, $byId[$top->id]['direct_count']);
        $this->assertSame(400, $byId[$middle->id]['direct_count']);
        $this->assertSame(402, $data['tree_stats']['total']);
        $this->assertSame(402, $data['tree_stats']['ranks']['dealer']);
        $this->assertSame('120.00', $data['tree_stats']['sales_total'], 'Purchases are summed over every partner, including ones not loaded yet.');

        $more = $this->actingAs($owner)->onPortal('sadmin')
            ->withHeaders(['X-Inertia' => 'true', 'X-Inertia-Version' => $this->inertiaVersion()])
            ->get('/network-tree?tree_limit='.$data['dealers_next_limit'])->assertOk()->json('props.data');
        $this->assertCount(402, $more['dealers']);
        $this->assertFalse($more['dealers_truncated']);
        $this->assertNull($more['dealers_next_limit']);
    }

    public function test_partner_home_team_sales_still_count_only_the_downline(): void
    {
        $dealer = $this->partner(null);
        $member = $this->partner($dealer);
        $outsider = $this->partner(null);
        foreach ([[$dealer, '300.00'], [$member, '450.00'], [$outsider, '999.00']] as [$buyer, $total]) {
            Order::create(['number' => 'ORD-HOME-'.Str::random(6), 'user_id' => $buyer->id, 'status' => 'approved', 'channel' => 'dealer', 'currency' => 'BDT',
                'subtotal' => $total, 'discount_amount' => '0.00', 'tax_amount' => '0.00', 'total' => $total, 'paid' => $total,
                'idempotency_key' => (string) Str::uuid(), 'payload_hash' => hash('sha256', Str::random())]);
        }

        $this->assertSame('450.00', app(PartnerDashboardHome::class)->for($dealer)['snapshot']['team_sales_today']);
        $this->assertSame('0.00', app(PartnerDashboardHome::class)->for($outsider)['snapshot']['team_sales_today']);
    }

    public function test_treasury_totals_match_the_separate_sums(): void
    {
        $this->enableFinancialPosting();
        $dealer = $this->partner(null);
        $subDealer = $this->partner($dealer, 'sub_dealer');
        $this->postedOrder($dealer, '100.00');
        $refunded = $this->postedOrder($subDealer, '120.00');
        app(CommissionReversal::class)->refund($refunded, 3000, 'Part returned', 'TREASURY-REFUND');
        $source = CommissionAllocation::query()->firstOrFail();
        foreach ([['status' => 'maturing', 'amount' => '7.25'], ['status' => 'unassigned_reserve', 'amount' => '3.10'], ['status' => 'reversed', 'amount' => '9.99'],
            ['category' => CommissionSettlement::CompanyProfitCategory, 'amount' => '4.40'],
            ['category' => 'company_marketing_fund', 'amount' => '2.00', 'metadata' => ['source_allocation_id' => $source->id]]] as $override) {
            CommissionAllocation::create([...collect($source->getAttributes())->except(['id', 'metadata'])->all(), 'metadata' => [], ...$override]);
        }
        foreach ([['paid', '200.00', '190.00', '10.00'], ['paid', '50.00', null, '0.00'], ['approved', '80.00', '75.00', '5.00']] as [$status, $amount, $net, $withheld]) {
            DB::table('withdrawals')->insert(['id' => (string) Str::uuid(), 'user_id' => $dealer->id, 'amount' => $amount, 'net_amount' => $net, 'withheld_amount' => $withheld,
                'status' => $status, 'channel' => 'Bank', 'destination' => encrypt('TEST-1234'), 'idempotency_key' => (string) Str::uuid(), 'created_at' => now(), 'updated_at' => now()]);
        }

        $original = fn ($query) => $query->whereRaw("(metadata->>'source_allocation_id') IS NULL");
        $company = DB::table('commission_batches')->selectRaw('COALESCE(SUM(company_share * (cash_basis - refunded_amount) / cash_basis), 0) AS amount')->value('amount');
        $buffer = DB::table('commission_batches')->selectRaw('COALESCE(SUM(unallocated * (cash_basis - refunded_amount) / cash_basis), 0) AS amount')->value('amount');
        $allocated = (float) CommissionAllocation::where('status', '!=', 'reversed')->where('category', '!=', 'company_commission_benefits_fund')->where($original)->sum('amount');
        $expected = [
            'company_share_60' => round((float) $company, 2),
            'unassigned_territory_reserve' => (float) CommissionAllocation::where('status', 'unassigned_reserve')->sum('amount'),
            'commission_pool_40' => $allocated,
            'allocated_so_far' => $allocated,
            'settled_disbursements' => (float) DB::table('withdrawals')->where('status', 'paid')->sum(DB::raw('COALESCE(net_amount, amount)')),
            'maturing_commissions' => (float) CommissionAllocation::where('status', 'maturing')->sum('amount'),
            'marketing_fund' => (float) CommissionAllocation::where('category', 'company_marketing_fund')->sum('amount'),
            'car_bike_fund' => (float) CommissionAllocation::where('category', 'car_bike_incentive_fund')->sum('amount'),
            'tds_tax_withheld' => (float) DB::table('withdrawals')->where('status', 'paid')->sum('withheld_amount'),
            'refunded_purchase_value' => (float) DB::table('commission_refunds')->sum('amount'),
            'unclaimed_to_company_profit' => (float) CommissionAllocation::where('status', '!=', 'reversed')->where('category', CommissionSettlement::CompanyProfitCategory)->sum('amount'),
            'commission_benefits_fund' => round((float) $buffer, 2),
        ];
        $this->assertSame(240.0, $expected['settled_disbursements']);
        $this->assertSame(30.0, $expected['refunded_purchase_value']);

        $owner = User::factory()->staff()->create();
        $flow = $this->actingAs($owner)->onPortal('sadmin')
            ->withHeaders(['X-Inertia' => 'true', 'X-Inertia-Version' => $this->inertiaVersion()])
            ->get('/treasury')->assertOk()->json('props.data.flow');
        foreach ($expected as $key => $value) {
            $this->assertEquals($value, $flow[$key], "Treasury figure {$key} changed.");
        }
        $this->assertEquals($expected['company_share_60'] + $flow['management_reserve_2'] + $expected['unassigned_territory_reserve'], $flow['total_company_deposits']);
    }

    public function test_reserve_summary_matches_each_fund_and_shows_the_car_and_bike_fund_once(): void
    {
        $this->enableFinancialPosting();
        $buyer = $this->partner($this->partner(null), 'sub_dealer');
        $order = $this->postedOrder($buyer, '120.00');
        $finance = User::factory()->create(['role' => 'finance']);
        $id = $this->actingAs($finance)->postJson('/api/v1/operations/reserves', ['program' => 'additional_rank', 'user_id' => $buyer->id, 'amount' => '1.20',
            'request_key' => (string) Str::uuid(), 'reason' => 'Approved sub-dealer tour benefit'])->assertOk()->json('data.id');
        $this->actingAs(User::factory()->create(['role' => 'finance']))->postJson('/api/v1/operations/reserves/'.$id.'/review', ['decision' => 'approved', 'reason' => 'Approve own funded tour'])->assertOk();
        app(CommissionReversal::class)->refund($order, 2000, 'Small part returned', 'RESERVE-REFUND');

        $service = app(ReserveService::class);
        $expected = [];
        foreach (['tour' => 'sub_dealer_tour', 'additional_rank' => 'additional_rank_pool', 'car_bike' => 'car_bike_incentive_fund', 'marketing' => 'company_marketing_fund'] as $program => $category) {
            $total = CommissionAllocation::where('category', $category)->get()->sum(fn ($fund) => $service->remaining($fund));
            $expected[$program.'_uncommitted_reserve'] = ($total < 0 ? '-' : '').Money::decimal(abs($total));
        }
        $this->assertNotSame('0.00', $expected['car_bike_uncommitted_reserve']);

        $summary = $this->actingAs($finance)->onPortal('manage')
            ->withHeaders(['X-Inertia' => 'true', 'X-Inertia-Version' => $this->inertiaVersion()])
            ->get('/operations/reserve-payouts')->assertOk()->json('props.summary');
        $this->assertSame($expected, $summary);
        $this->assertSame(__('portal.ops_chk_car_bike_uncommitted_reserve'), 'Car and bike fund not yet promised');
    }

    public function test_pages_skip_rows_their_screens_never_show(): void
    {
        $owner = User::factory()->staff()->create();
        $this->partner(null);
        $this->actingAs($owner)->onPortal('sadmin')->get('/commissions')->assertOk()->assertInertia(fn (Assert $page) => $page
            ->has('data.rules')
            ->missing('data.allocations')
            ->missing('data.pools_summary'));
        $this->actingAs($owner)->onPortal('sadmin')->get('/accounts')->assertOk()->assertInertia(fn (Assert $page) => $page
            ->has('data.accounts')
            ->missing('data.sponsors'));
        $this->actingAs($owner)->get('/company/accounts/create')->assertOk()->assertInertia(fn (Assert $page) => $page
            ->has('sponsors', 1, fn (Assert $sponsor) => $sponsor->hasAll(['id', 'name', 'dealer_code'])->etc()->missing('district')->missing('refer_code')));
    }
}
