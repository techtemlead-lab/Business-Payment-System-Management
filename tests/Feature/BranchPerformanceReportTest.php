<?php

namespace Tests\Feature;

use App\Domain\Inventory\BranchOperations;
use App\Domain\Inventory\BranchPerformance;
use App\Models\BranchStaffAssignment;
use App\Models\CompanyBranch;
use App\Models\Product;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Inertia\Testing\AssertableInertia as Assert;
use Symfony\Component\HttpKernel\Exception\HttpException;
use Tests\TestCase;

class BranchPerformanceReportTest extends TestCase
{
    use RefreshDatabase;

    public function test_owner_can_view_branch_performance_breakdown_for_period(): void
    {
        $owner = User::factory()->staff('super_admin')->create();
        $partner = User::factory()->dealer()->create();
        $hub = CompanyBranch::query()->where('code', 'HO-PIRGANJ')->firstOrFail();
        $other = CompanyBranch::factory()->create(['code' => 'DHK-02', 'name' => 'Dhaka Hub']);
        $product = Product::factory()->create([
            'stock' => 20,
            'mrp' => '500.00',
            'dealer_price' => '400.00',
            'cost_price' => '250.00',
        ]);

        $this->createFulfilledOrder($partner, $product, $hub->id, '400.00', '400.00');
        $this->createFulfilledOrder($partner, $product, $other->id, '400.00', '0.00');
        $this->createFulfilledOrder($partner, $product, null, '400.00', '100.00');

        $this->actingAs($owner)
            ->onPortal('sadmin')
            ->get('/branch-performance?period=month')
            ->assertOk();

        $summary = app(BranchPerformance::class)->summary($owner, ['period' => 'month']);
        $this->assertSame(3, $summary['totals']['order_count']);
        $this->assertSame('1200.00', $summary['totals']['order_value']);
        $this->assertSame('500.00', $summary['totals']['income']);
        $this->assertSame('450.00', $summary['totals']['estimated_profit']);

        $byCode = collect($summary['branches'])->keyBy('code');
        $this->assertSame(1, $byCode['HO-PIRGANJ']['order_count']);
        $this->assertSame('400.00', $byCode['HO-PIRGANJ']['income']);
        $this->assertSame(1, $byCode['DHK-02']['order_count']);
        $this->assertSame('0.00', $byCode['DHK-02']['income']);
        $this->assertSame(1, $summary['unassigned']['order_count']);
    }

    public function test_totals_count_every_order_in_the_period_and_the_list_is_paged(): void
    {
        $owner = User::factory()->staff('super_admin')->create();
        $partner = User::factory()->dealer()->create();
        $hub = CompanyBranch::query()->where('code', 'HO-PIRGANJ')->firstOrFail();
        $other = CompanyBranch::factory()->create(['code' => 'DHK-02', 'name' => 'Dhaka Hub']);
        $product = Product::factory()->create(['cost_price' => '30.00']);

        $this->bulkOrders($partner, $product, $hub->id, 150, '100.00', '100.00');
        $this->bulkOrders($partner, $product, $other->id, 80, '50.00', '0.00');

        $summary = app(BranchPerformance::class)->summary($owner, ['period' => 'month']);

        $this->assertCount(25, $summary['orders']->items());
        $this->assertSame(230, $summary['orders']->total());
        $this->assertSame(230, $summary['totals']['order_count']);
        $this->assertSame('19000.00', $summary['totals']['order_value']);
        $this->assertSame('15000.00', $summary['totals']['income']);
        $this->assertSame('4000.00', $summary['totals']['outstanding']);
        $this->assertSame('12100.00', $summary['totals']['estimated_profit']);
        $this->assertSame('6900.00', $summary['totals']['estimated_cost']);

        $byCode = collect($summary['branches'])->keyBy('code');
        $this->assertSame(150, $byCode['HO-PIRGANJ']['order_count']);
        $this->assertSame('15000.00', $byCode['HO-PIRGANJ']['order_value']);
        $this->assertSame('10500.00', $byCode['HO-PIRGANJ']['estimated_profit']);
        $this->assertSame(80, $byCode['DHK-02']['order_count']);
        $this->assertSame('4000.00', $byCode['DHK-02']['order_value']);
        $this->assertSame('4000.00', $byCode['DHK-02']['outstanding']);
    }

    public function test_branch_totals_add_up_to_the_all_branches_total(): void
    {
        $owner = User::factory()->staff('super_admin')->create();
        $partner = User::factory()->dealer()->create();
        $hub = CompanyBranch::query()->where('code', 'HO-PIRGANJ')->firstOrFail();
        $dhaka = CompanyBranch::factory()->create(['code' => 'DHK-02', 'name' => 'Dhaka Hub']);
        $rajshahi = CompanyBranch::factory()->create(['code' => 'RAJ-01', 'name' => 'Rajshahi Hub']);
        $product = Product::factory()->create(['cost_price' => '120.00']);

        $this->bulkOrders($partner, $product, $hub->id, 7, '250.50', '100.25');
        $this->bulkOrders($partner, $product, $dhaka->id, 5, '99.99', '99.99');
        $this->bulkOrders($partner, $product, $rajshahi->id, 3, '100.00', '20.00');
        $this->bulkOrders($partner, $product, null, 4, '300.00', '0.00');

        $summary = app(BranchPerformance::class)->summary($owner, ['period' => 'month']);
        $rows = collect([...$summary['branches'], $summary['unassigned']]);

        foreach (['order_count', 'order_value_cents', 'income_cents', 'outstanding_cents', 'profit_cents', 'cost_cents'] as $metric) {
            $this->assertSame($summary['totals'][$metric], $rows->sum($metric), "Branch {$metric} must add up to the all-branches total.");
        }
        $this->assertSame(19, $summary['totals']['order_count']);
        $this->assertSame(4, $summary['unassigned']['order_count']);
        // Selling below cost is a loss, shown as a negative profit instead of breaking the report.
        $this->assertSame('-60.00', collect($summary['branches'])->firstWhere('code', 'RAJ-01')['estimated_profit']);
        $this->assertSame('-100.05', collect($summary['branches'])->firstWhere('code', 'DHK-02')['estimated_profit']);
    }

    public function test_waiting_rejected_and_cancelled_orders_are_left_out(): void
    {
        $owner = User::factory()->staff('super_admin')->create();
        $partner = User::factory()->dealer()->create();
        $hub = CompanyBranch::query()->where('code', 'HO-PIRGANJ')->firstOrFail();
        $product = Product::factory()->create(['cost_price' => '50.00']);

        foreach (['approved', 'partially_dispatched', 'dispatched', 'delivered'] as $status) {
            $this->createFulfilledOrder($partner, $product, $hub->id, '100.00', '100.00', $status);
        }
        foreach (['submitted', 'held', 'rejected', 'cancelled'] as $status) {
            $this->createFulfilledOrder($partner, $product, $hub->id, '1000.00', '0.00', $status);
        }

        $summary = app(BranchPerformance::class)->summary($owner, ['period' => 'month']);

        $this->assertSame(4, $summary['totals']['order_count']);
        $this->assertSame('100.00', $summary['totals']['order_value'], 'Only the delivered order counts in money.');
        $this->assertSame('0.00', $summary['totals']['outstanding']);
        $this->assertSame('50.00', $summary['totals']['estimated_profit']);
        $this->assertSame(
            ['approved', 'delivered', 'dispatched', 'partially_dispatched'],
            collect($summary['orders']->items())->pluck('status')->sort()->values()->all(),
        );
    }

    public function test_branch_staff_see_only_their_own_branch(): void
    {
        $partner = User::factory()->dealer()->create();
        $hub = CompanyBranch::query()->where('code', 'HO-PIRGANJ')->firstOrFail();
        $dhaka = CompanyBranch::factory()->create(['code' => 'DHK-02', 'name' => 'Dhaka Hub']);
        $product = Product::factory()->create(['cost_price' => '10.00']);
        $this->createFulfilledOrder($partner, $product, $hub->id, '700.00', '700.00');
        $this->createFulfilledOrder($partner, $product, $dhaka->id, '200.00', '50.00');
        $this->createFulfilledOrder($partner, $product, null, '900.00', '0.00');

        $reportsClerk = User::factory()->staff('employee')->create(['profile' => ['permissions' => ['reports']], 'active_branch_id' => $dhaka->id]);
        BranchStaffAssignment::query()->create(['branch_id' => $dhaka->id, 'user_id' => $reportsClerk->id, 'duty' => 'storekeeper', 'is_primary' => true]);

        $summary = app(BranchPerformance::class)->summary($reportsClerk, ['period' => 'month']);
        $this->assertFalse($summary['can_view_all_branches']);
        $this->assertSame(['DHK-02'], collect($summary['branches'])->pluck('code')->all());
        $this->assertSame(['DHK-02'], collect($summary['branch_options'])->pluck('code')->all());
        $this->assertSame(1, $summary['totals']['order_count']);
        $this->assertSame('200.00', $summary['totals']['order_value']);
        $this->assertSame(0, $summary['unassigned']['order_count']);
        $this->assertSame(['DHK-02'], collect($summary['orders']->items())->pluck('branch_code')->unique()->values()->all());

        try {
            app(BranchPerformance::class)->summary($reportsClerk, ['period' => 'month', 'branch_id' => $hub->id]);
            $this->fail('Another branch must not be readable.');
        } catch (HttpException $exception) {
            $this->assertSame(403, $exception->getStatusCode());
        }

        $this->actingAs($reportsClerk)->onPortal('manage')
            ->get('/branch-performance?period=month&branch_id='.$hub->id)
            ->assertForbidden();

        $this->actingAs($reportsClerk)->onPortal('manage')
            ->withSession([BranchOperations::SessionView => 'all'])
            ->get('/branch-performance?period=month&all_branches=1')
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->where('data.performance.totals.order_count', 1)
                ->where('data.performance.totals.order_value', '200.00')
                ->where('data.performance.branches', fn ($branches) => collect($branches)->pluck('code')->all() === ['DHK-02'])
                ->where('data.branch_options', fn ($options) => collect($options)->pluck('code')->all() === ['DHK-02']));
    }

    public function test_owner_sees_every_branch_and_the_combined_total(): void
    {
        $owner = User::factory()->staff('super_admin')->create();
        $partner = User::factory()->dealer()->create();
        $hub = CompanyBranch::query()->where('code', 'HO-PIRGANJ')->firstOrFail();
        $dhaka = CompanyBranch::factory()->create(['code' => 'DHK-02', 'name' => 'Dhaka Hub']);
        $product = Product::factory()->create(['cost_price' => '10.00']);
        $this->createFulfilledOrder($partner, $product, $hub->id, '700.00', '700.00');
        $this->createFulfilledOrder($partner, $product, $dhaka->id, '200.00', '50.00');
        $this->createFulfilledOrder($partner, $product, null, '900.00', '0.00');
        $allCodes = CompanyBranch::query()->orderBy('name')->pluck('code')->sort()->values()->all();

        $this->actingAs($owner)->onPortal('sadmin')
            ->get('/branch-performance?period=month&all_branches=1')
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->where('data.performance.selected_branch_id', null)
                ->where('data.performance.can_view_all_branches', true)
                ->where('data.performance.totals.order_count', 3)
                ->where('data.performance.totals.order_value', '1800.00')
                ->where('data.performance.unassigned.order_count', 1)
                ->where('data.performance.branches', fn ($branches) => collect($branches)->pluck('code')->sort()->values()->all() === $allCodes)
                ->where('data.branch_options', fn ($options) => collect($options)->pluck('code')->sort()->values()->all() === $allCodes));

        $this->actingAs($owner)->onPortal('sadmin')
            ->get('/branch-performance?period=month&branch_id='.$dhaka->id)
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->where('data.performance.selected_branch_id', $dhaka->id)
                ->where('data.performance.totals.order_count', 1)
                ->where('data.performance.totals.order_value', '200.00'));
    }

    public function test_report_follows_the_top_bar_branch_switcher(): void
    {
        $partner = User::factory()->dealer()->create();
        $hub = CompanyBranch::query()->where('code', 'HO-PIRGANJ')->firstOrFail();
        $dhaka = CompanyBranch::factory()->create(['code' => 'DHK-02', 'name' => 'Dhaka Hub']);
        $owner = User::factory()->staff('super_admin')->create(['active_branch_id' => $dhaka->id]);
        $product = Product::factory()->create(['cost_price' => '10.00']);
        $this->createFulfilledOrder($partner, $product, $hub->id, '700.00', '700.00');
        $this->createFulfilledOrder($partner, $product, $dhaka->id, '200.00', '50.00');

        $this->actingAs($owner)->onPortal('sadmin')
            ->get('/branch-performance?period=month')
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->where('workspaceBranches.view', 'branch')
                ->where('data.performance.selected_branch_id', $dhaka->id)
                ->where('data.performance.totals.order_count', 1)
                ->where('data.performance.totals.order_value', '200.00'));

        $this->actingAs($owner)->onPortal('sadmin')
            ->withSession([BranchOperations::SessionView => 'all'])
            ->get('/branch-performance?period=month')
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->where('workspaceBranches.view', 'all')
                ->where('data.performance.selected_branch_id', null)
                ->where('data.performance.totals.order_count', 2)
                ->where('data.performance.totals.order_value', '900.00'));
    }

    public function test_all_branch_readers_with_no_switcher_choice_open_on_every_branch_and_branch_staff_on_their_own(): void
    {
        $partner = User::factory()->dealer()->create();
        $hub = CompanyBranch::query()->where('code', 'HO-PIRGANJ')->firstOrFail();
        $dhaka = CompanyBranch::factory()->create(['code' => 'DHK-02', 'name' => 'Dhaka Hub']);
        $product = Product::factory()->create(['cost_price' => '10.00']);
        $this->createFulfilledOrder($partner, $product, $hub->id, '700.00', '700.00');
        $this->createFulfilledOrder($partner, $product, $dhaka->id, '200.00', '50.00');

        $owner = User::factory()->staff('super_admin')->create(['active_branch_id' => null]);
        $this->actingAs($owner)->onPortal('sadmin')
            ->get('/branch-performance?period=month')
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->where('data.performance.selected_branch_id', null)
                ->where('data.performance.totals.order_count', 2)
                ->where('data.performance.totals.order_value', '900.00'));

        $storekeeper = User::factory()->staff('inventory')->create(['active_branch_id' => null]);
        $this->actingAs($storekeeper)->onPortal('manage')
            ->get('/branch-performance?period=month')
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->where('data.performance.selected_branch_id', null)
                ->where('data.performance.totals.order_count', 2));

        $reportsClerk = User::factory()->staff('employee')->create(['profile' => ['permissions' => ['reports']], 'active_branch_id' => null]);
        BranchStaffAssignment::query()->create(['branch_id' => $dhaka->id, 'user_id' => $reportsClerk->id, 'duty' => 'storekeeper', 'is_primary' => false]);
        $this->actingAs($reportsClerk)->onPortal('manage')
            ->withSession([BranchOperations::SessionView => 'all'])
            ->get('/branch-performance?period=month')
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->where('data.performance.selected_branch_id', $dhaka->id)
                ->where('data.performance.totals.order_count', 1)
                ->where('data.performance.totals.order_value', '200.00'));
    }

    public function test_finance_staff_with_no_branch_assignment_see_every_branch_and_the_grand_total(): void
    {
        $partner = User::factory()->dealer()->create();
        $hub = CompanyBranch::query()->where('code', 'HO-PIRGANJ')->firstOrFail();
        $dhaka = CompanyBranch::factory()->create(['code' => 'DHK-02', 'name' => 'Dhaka Hub']);
        $product = Product::factory()->create(['cost_price' => '10.00']);
        $this->createFulfilledOrder($partner, $product, $hub->id, '700.00', '700.00');
        $this->createFulfilledOrder($partner, $product, $dhaka->id, '200.00', '50.00');
        $this->createFulfilledOrder($partner, $product, null, '900.00', '0.00');
        $allCodes = CompanyBranch::query()->pluck('code')->sort()->values()->all();

        $accountant = User::factory()->staff('finance')->create(['active_branch_id' => null]);
        $this->assertSame(0, BranchStaffAssignment::query()->where('user_id', $accountant->id)->count());

        $this->actingAs($accountant)->onPortal('manage')
            ->get('/branch-performance?period=month')
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->where('workspaceBranches.can_view_all', true)
                ->where('data.performance.can_view_all_branches', true)
                ->where('data.performance.selected_branch_id', null)
                ->where('data.performance.totals.order_count', 3)
                ->where('data.performance.totals.order_value', '1800.00')
                ->where('data.performance.unassigned.order_count', 1)
                ->where('data.performance.branches', fn ($branches) => collect($branches)->pluck('code')->sort()->values()->all() === $allCodes)
                ->where('data.branch_options', fn ($options) => collect($options)->pluck('code')->sort()->values()->all() === $allCodes));

        $this->actingAs($accountant)->onPortal('manage')
            ->get('/branch-performance?period=month&branch_id='.$hub->id)
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->where('data.performance.totals.order_count', 1)
                ->where('data.performance.totals.order_value', '700.00'));

        // Viewing every branch gives no right to change stock at a branch they are not assigned to.
        $operations = app(BranchOperations::class);
        $this->assertTrue($operations->canViewAllBranches($accountant));
        $this->assertFalse($operations->canManageAllBranches($accountant));
    }

    public function test_product_editor_public_link_uses_public_portal_host(): void
    {
        $owner = User::factory()->staff('super_admin')->create();
        $product = Product::factory()->create(['active' => true, 'stock' => 5]);

        $response = $this->actingAs($owner)
            ->onPortal('sadmin')
            ->get('/products/'.$product->id)
            ->assertOk()
            ->assertInertia(fn ($page) => $page
                ->component('ProductEditor')
                ->has('product', fn ($p) => $p->where('id', $product->id)->etc())
                ->where('publicBase', fn ($base) => is_string($base) && $base !== ''));

        $publicBase = (string) $response->original->getData()['page']['props']['publicBase'];
        $this->assertStringNotContainsString('/sadmin', $publicBase);
        $this->assertStringNotContainsString('/manage', $publicBase);

        $this->get($publicBase.'/products/'.$product->id)->assertOk();
    }

    private function createFulfilledOrder(User $partner, Product $product, ?int $branchId, string $total, string $paid, string $status = 'delivered'): void
    {
        $orderId = (string) Str::uuid();
        DB::table('orders')->insert([
            'id' => $orderId,
            'number' => 'MN-TEST-'.Str::upper(Str::random(6)),
            'user_id' => $partner->id,
            'status' => $status,
            'total' => $total,
            'paid' => $paid,
            'due_credit' => '0.00',
            'idempotency_key' => (string) Str::uuid(),
            'payload_hash' => hash('sha256', $orderId),
            'fulfillment_branch_id' => $branchId,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
        DB::table('order_lines')->insert([
            'order_id' => $orderId,
            'product_id' => $product->id,
            'name' => $product->name,
            'sku' => $product->sku,
            'quantity' => 1,
            'dispatched' => 1,
            'mrp' => $product->mrp,
            'unit_price' => $total,
            'total' => $total,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }

    /**
     * Insert many delivered one-line orders at once, each selling one unit at the order total.
     */
    private function bulkOrders(User $partner, Product $product, ?int $branchId, int $count, string $total, string $paid): void
    {
        $orders = [];
        $lines = [];
        for ($index = 0; $index < $count; $index++) {
            $orderId = (string) Str::uuid();
            $createdAt = now()->subSeconds($index);
            $orders[] = [
                'id' => $orderId,
                'number' => 'MN-BULK-'.Str::upper(Str::random(10)),
                'user_id' => $partner->id,
                'status' => 'delivered',
                'total' => $total,
                'paid' => $paid,
                'due_credit' => '0.00',
                'idempotency_key' => (string) Str::uuid(),
                'payload_hash' => hash('sha256', $orderId),
                'fulfillment_branch_id' => $branchId,
                'created_at' => $createdAt,
                'updated_at' => $createdAt,
            ];
            $lines[] = [
                'order_id' => $orderId,
                'product_id' => $product->id,
                'name' => $product->name,
                'sku' => $product->sku,
                'quantity' => 1,
                'dispatched' => 1,
                'mrp' => $product->mrp,
                'unit_price' => $total,
                'total' => $total,
                'created_at' => $createdAt,
                'updated_at' => $createdAt,
            ];
        }
        foreach (array_chunk($orders, 100) as $chunk) {
            DB::table('orders')->insert($chunk);
        }
        foreach (array_chunk($lines, 100) as $chunk) {
            DB::table('order_lines')->insert($chunk);
        }
    }
}
