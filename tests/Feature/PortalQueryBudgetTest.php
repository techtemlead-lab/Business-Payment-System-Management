<?php

namespace Tests\Feature;

use App\Domain\Commissions\CommissionQualification;
use App\Domain\Support\GuidePrerequisites;
use App\Models\CompanyBranch;
use App\Models\Order;
use App\Models\Product;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Testing\TestResponse;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\TestCase;

/**
 * Query ceilings for the busiest portal pages. Each test checks that the
 * number of queries stays the same when the data grows (no query per row)
 * and stays under the count measured after the fix, and that the numbers
 * on screen did not change.
 */
class PortalQueryBudgetTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->withoutVite();
    }

    public function test_owner_dashboard_reads_order_totals_in_one_pass(): void
    {
        $owner = User::factory()->staff()->create();
        $rahim = User::factory()->dealer()->create();
        $karim = User::factory()->subDealer()->create(['sponsor_id' => $rahim->id]);
        $product = Product::factory()->create(['active' => true]);
        $approved = $this->order($rahim, 'approved', total: '300.00', paid: '100.00', dueCredit: '50.00');
        $this->line($approved, $product, quantity: 5, dispatched: 2);
        $this->order($karim, 'delivered', total: '200.00', paid: '200.00');
        $this->order($rahim, 'submitted', total: '150.00');
        $old = $this->order($karim, 'held', total: '70.00');
        DB::table('orders')->where('id', $old->id)->update(['created_at' => now()->subDays(3)]);
        // The first visit of a session does one-off work; measure a normal visit.
        $this->actingAs($owner)->onPortal('sadmin')->get('/')->assertOk();

        [$response, $queries] = $this->measure(fn () => $this->actingAs($owner)->onPortal('sadmin')->get('/'));

        $response->assertOk()->assertInertia(fn (Assert $page) => $page
            ->where('data.stats.order_count', 4)
            ->where('data.stats.pending', 2)
            ->where('data.stats.purchases', '500.00')
            ->where('data.stats.dues', '150.00')
            ->where('data.stats.supply', 3)
            ->where('data.stats.accounts', 2)
            ->where('data.admin_home.snapshot.orders_today', 3)
            ->where('data.admin_home.snapshot.pending_review', 2)
            ->where('data.admin_home.snapshot.partner_accounts', 2)
            ->where('data.admin_home.snapshot.purchases', '500.00')
            ->where('data.admin_home.snapshot.dues', '150.00'));
        $this->assertQueriesAtMost(33, $queries, 'owner dashboard');
        $this->assertSame(1, $this->matching($queries, 'filter (where status in'), 'Order count, pending, today and money totals should come from one aggregate query.');

        foreach (range(1, 10) as $index) {
            $this->line($this->order($rahim, 'approved', total: '10.00'), $product, quantity: 1, dispatched: 0);
        }
        [, $moreQueries] = $this->measure(fn () => $this->actingAs($owner)->onPortal('sadmin')->get('/'));
        $this->assertCount(count($queries), $moreQueries, 'Owner dashboard queries must not grow with the number of orders.');
    }

    public function test_dealer_dashboard_query_count_does_not_grow_with_orders(): void
    {
        $dealer = User::factory()->dealer()->create(['phone' => '01711000000']);
        $product = Product::factory()->create(['active' => true]);
        $this->line($this->order($dealer, 'approved', total: '100.00'), $product, quantity: 2, dispatched: 1);
        $this->actingAs($dealer)->onPortal('dealer')->get('/')->assertOk();

        [$response, $queries] = $this->measure(fn () => $this->actingAs($dealer)->onPortal('dealer')->get('/'));
        $response->assertOk()->assertInertia(fn (Assert $page) => $page
            ->where('data.stats.order_count', 1)
            ->where('data.stats.purchases', '100.00')
            ->where('data.stats.supply', 1)
            ->has('data.partner_home')
            ->has('data.guide_prerequisites.checklist'));
        $this->assertQueriesAtMost(39, $queries, 'dealer dashboard');

        foreach (range(1, 8) as $index) {
            $this->line($this->order($dealer, $index % 2 === 0 ? 'delivered' : 'submitted', total: '20.00'), $product, quantity: 1, dispatched: 0);
        }
        [, $moreQueries] = $this->measure(fn () => $this->actingAs($dealer)->onPortal('dealer')->get('/'));
        $this->assertCount(count($queries), $moreQueries, 'Dealer dashboard queries must not grow with the number of orders.');
    }

    public function test_guide_checklist_reads_tours_and_orders_once(): void
    {
        $dealer = User::factory()->dealer()->create(['phone' => null]);
        DB::table('tour_completions')->insert(['user_id' => $dealer->id, 'page_key' => 'catalog', 'completed_at' => now(), 'created_at' => now(), 'updated_at' => now()]);
        $guide = app(GuidePrerequisites::class);

        [$checklist, $queries] = $this->measure(fn () => $guide->forSection($dealer, 'orders'));

        $this->assertSame([
            'overview' => true,
            'profile' => false,
            'catalog' => true,
            'orders' => false,
            'earnings' => false,
            'withdrawals' => false,
            'full-report' => false,
            'help' => true,
        ], collect($checklist['checklist'])->pluck('done', 'key')->all());
        $this->assertSame(['profile'], collect($checklist['missing'])->pluck('key')->all());
        $this->assertSame(1, $this->matching($queries, 'from "tour_completions"'), 'Finished tours should be read once for the whole checklist.');
        $this->assertSame(1, $this->matching($queries, 'select exists(select * from "orders" where "user_id" = ?) as "exists"'), 'Whether the partner ordered should be asked once.');
        $this->assertQueriesAtMost(6, $queries, 'guide checklist');

        $this->order($dealer, 'submitted', total: '50.00');
        $done = collect($guide->forSection($dealer, 'orders')['checklist'])->pluck('done', 'key');
        $this->assertTrue($done['orders']);
        $this->assertTrue($done['full-report']);
    }

    public function test_inventory_reads_the_schema_once_however_many_products(): void
    {
        $owner = User::factory()->staff()->create();
        Product::factory()->count(3)->create(['active' => true]);
        $expiring = Product::factory()->create(['active' => true, 'expires_on' => '2027-03-31']);

        [$response, $queries] = $this->measure(fn () => $this->actingAs($owner)->onPortal('sadmin')->get('/inventory'));
        $response->assertOk()->assertInertia(fn (Assert $page) => $page
            ->where('data.supports_product_expiry', true)
            ->where('data.products', fn ($products) => collect($products)->firstWhere('id', $expiring->id)['expires_on'] === '2027-03-31'));
        $this->assertQueriesAtMost(24, $queries, 'inventory');

        Product::factory()->count(12)->create(['active' => true]);
        [, $moreQueries] = $this->measure(fn () => $this->actingAs($owner)->onPortal('sadmin')->get('/inventory'));
        $this->assertCount(count($queries), $moreQueries, 'Inventory queries must not grow with the number of products.');
    }

    public function test_inventory_loads_the_branch_list_once_per_reader(): void
    {
        $branch = CompanyBranch::factory()->create(['code' => 'QB-01', 'name' => 'Query Hub', 'is_active' => true]);
        $owner = User::factory()->staff()->create(['active_branch_id' => $branch->id]);
        $branchIds = CompanyBranch::query()->orderBy('name')->pluck('id')->all();

        [$response, $queries] = $this->measure(fn () => $this->actingAs($owner)->onPortal('sadmin')->get('/inventory'));

        $response->assertOk()->assertInertia(fn (Assert $page) => $page
            ->where('data.active_branch_id', $branch->id)
            ->where('data.active_branch.code', 'QB-01')
            ->where('data.branches', fn ($branches) => collect($branches)->pluck('id')->all() === $branchIds)
            ->where('workspaceBranches.active_branch_id', $branch->id)
            ->where('workspaceBranches.active_branch.code', 'QB-01')
            ->where('workspaceBranches.branches', fn ($branches) => collect($branches)->pluck('id')->all() === $branchIds));
        // One read for the shared branch switcher, one for the inventory section (was four).
        $this->assertSame(2, $this->matching($queries, 'select * from "company_branches" order by "name" asc'));
    }

    public function test_unread_notices_are_counted_once_per_page(): void
    {
        $dealer = User::factory()->dealer()->create();
        foreach (range(1, 3) as $index) {
            DB::table('account_notices')->insert(['user_id' => $dealer->id, 'title' => 'Notice '.$index, 'body' => 'Body', 'created_at' => now(), 'updated_at' => now()]);
        }

        [$response, $queries] = $this->measure(fn () => $this->actingAs($dealer)->onPortal('dealer')->get('/orders'));

        $response->assertOk()->assertInertia(fn (Assert $page) => $page
            ->where('navAttention.notifications', 3)
            ->where('unreadNotices', 3));
        $this->assertSame(1, $this->matching($queries, 'from "account_notices"'));
    }

    public function test_settlements_evaluate_rank_leaders_in_one_batch(): void
    {
        $owner = User::factory()->staff()->create();
        $month = now('Asia/Dhaka')->subMonthNoOverflow()->format('Y-m');
        $this->rankLeaders(2);

        [$response, $queries] = $this->measure(fn () => $this->actingAs($owner)->onPortal('sadmin')->get('/settlements?period_month='.$month));
        $response->assertOk();
        $this->assertQueriesAtMost(31, $queries, 'settlements');

        $this->rankLeaders(8);
        [$response, $moreQueries] = $this->measure(fn () => $this->actingAs($owner)->onPortal('sadmin')->get('/settlements?period_month='.$month));
        $this->assertCount(count($queries), $moreQueries, 'Settlement queries must not grow with the number of rank leaders.');

        $qualification = app(CommissionQualification::class);
        $response->assertOk()->assertInertia(fn (Assert $page) => $page
            ->has('data.settlement_state.qualified_rank_leaders', 10)
            ->where('data.settlement_state.qualified_rank_leaders', function ($leaders) use ($qualification, $month): bool {
                foreach ($leaders as $leader) {
                    $expected = $qualification->paidAs((int) $leader['user_id'], $month);
                    if ($leader['paid_as_rank'] !== $expected['paid_as_rank']
                        || $leader['is_qualified'] !== $expected['is_qualified']
                        || $leader['in_grace_period'] !== $expected['in_grace_period']) {
                        return false;
                    }
                }

                return true;
            }));
    }

    public function test_dashboard_filter_columns_are_indexed(): void
    {
        $indexes = collect(DB::select("select indexname from pg_indexes where schemaname = 'public'"))->pluck('indexname');

        foreach ([
            'orders_created_at_index',
            'order_lines_product_id_index',
            'payment_claims_status_index',
            'withdrawals_status_index',
            'users_sponsor_id_index',
            'account_notices_user_id_read_at_index',
        ] as $index) {
            $this->assertTrue($indexes->contains($index), "Missing index {$index}.");
        }
    }

    /**
     * Runs the callback with the query log on.
     *
     * @return array{0: mixed, 1: list<array{query: string, bindings: array<mixed>, time: float|null}>}
     */
    private function measure(callable $callback): array
    {
        auth()->forgetGuards();
        DB::flushQueryLog();
        DB::enableQueryLog();
        try {
            $result = $callback();
        } finally {
            $queries = DB::getQueryLog();
            DB::disableQueryLog();
        }
        if ($result instanceof TestResponse) {
            $result->assertOk();
        }

        return [$result, $queries];
    }

    /**
     * @param  list<array{query: string}>  $queries
     */
    private function assertQueriesAtMost(int $ceiling, array $queries, string $page): void
    {
        $this->assertLessThanOrEqual($ceiling, count($queries), "The {$page} ran ".count($queries)." queries; the budget is {$ceiling}:\n".collect($queries)->pluck('query')->implode("\n"));
    }

    /**
     * @param  list<array{query: string}>  $queries
     */
    private function matching(array $queries, string $needle): int
    {
        return collect($queries)->filter(fn (array $query): bool => str_contains(strtolower($query['query']), strtolower($needle)))->count();
    }

    private function order(User $buyer, string $status, string $total, string $paid = '0.00', string $dueCredit = '0.00'): Order
    {
        return Order::create([
            'number' => 'ORD-'.Str::upper(Str::random(8)),
            'user_id' => $buyer->id,
            'status' => $status,
            'total' => $total,
            'paid' => $paid,
            'due_credit' => $dueCredit,
            'idempotency_key' => (string) Str::uuid(),
            'payload_hash' => hash('sha256', Str::random()),
        ]);
    }

    private function line(Order $order, Product $product, int $quantity, int $dispatched): void
    {
        DB::table('order_lines')->insert([
            'order_id' => $order->id,
            'product_id' => $product->id,
            'name' => $product->name,
            'sku' => $product->sku,
            'quantity' => $quantity,
            'dispatched' => $dispatched,
            'mrp' => '50.00',
            'unit_price' => '50.00',
            'total' => number_format($quantity * 50, 2, '.', ''),
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }

    private function rankLeaders(int $count): void
    {
        foreach (User::factory()->dealer()->count($count)->create() as $index => $leader) {
            DB::table('dealer_ranks')->insert([
                'user_id' => $leader->id,
                'current_rank' => $index % 2 === 0 ? 'mo' : 'am',
                // Every other leader is still inside the grace period, so both paid-as paths are compared.
                'achieved_at' => $index % 2 === 0 ? now()->subYear() : now()->subMonthNoOverflow()->startOfMonth(),
                'created_at' => now(),
                'updated_at' => now(),
            ]);
        }
    }
}
