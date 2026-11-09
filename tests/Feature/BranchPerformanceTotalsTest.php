<?php

namespace Tests\Feature;

use App\Http\Middleware\HandleInertiaRequests;
use App\Models\BranchStaffAssignment;
use App\Models\CompanyBranch;
use App\Models\Product;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Testing\TestResponse;
use OpenSpout\Reader\XLSX\Reader;
use Tests\TestCase;

class BranchPerformanceTotalsTest extends TestCase
{
    use RefreshDatabase;

    private User $owner;

    private User $partner;

    private Product $product;

    private CompanyBranch $dhaka;

    private CompanyBranch $khulna;

    protected function setUp(): void
    {
        parent::setUp();
        $this->withoutVite();
        $this->owner = User::factory()->staff()->create();
        $this->partner = User::factory()->dealer()->create(['name' => 'Branch Buyer']);
        $this->product = Product::factory()->create(['cost_price' => '60.00']);
        $this->dhaka = CompanyBranch::factory()->create(['code' => 'DHK-01', 'name' => 'Dhaka Hub']);
        $this->khulna = CompanyBranch::factory()->create(['code' => 'KHL-01', 'name' => 'Khulna Hub']);
    }

    public function test_totals_cover_every_order_and_the_order_list_is_paged_on_the_server(): void
    {
        $this->seedOrders();

        $first = $this->performance('/branch-performance?period=month&all_branches=1');
        $second = $this->performance('/branch-performance?period=month&all_branches=1&page=2');

        $this->assertSame(30, $first['totals']['order_count']);
        $this->assertSame('2000.00', $first['totals']['order_value'], 'Only the 20 delivered orders count in money.');
        $this->assertSame('2000.00', $first['totals']['income']);
        $this->assertSame('500.00', $first['totals']['outstanding'], 'What is still unpaid stays, delivered or not.');
        $this->assertSame('800.00', $first['totals']['estimated_profit']);
        $byCode = collect($first['branches'])->keyBy('code');
        $this->assertSame(20, $byCode['DHK-01']['order_count']);
        $this->assertSame('0.00', $byCode['DHK-01']['outstanding']);
        $this->assertSame(10, $byCode['KHL-01']['order_count']);
        $this->assertSame('500.00', $byCode['KHL-01']['outstanding']);

        $this->assertSame(30, $first['orders']['total']);
        $this->assertCount(25, $first['orders']['data']);
        $this->assertCount(5, $second['orders']['data']);
        $this->assertNotContains('rejected', collect([...$first['orders']['data'], ...$second['orders']['data']])->pluck('status')->all());
        $this->assertStringContainsString('all_branches=1', (string) $first['orders']['next_page_url'], 'The period and branch survive going to the next page.');

        $khulna = $this->performance('/branch-performance?period=month&branch_id='.$this->khulna->id);
        $this->assertSame(10, $khulna['totals']['order_count']);
        $this->assertSame(10, $khulna['orders']['total']);
        $this->assertSame($this->khulna->id, $khulna['selected_branch_id']);
    }

    public function test_more_than_two_hundred_orders_add_up_on_screen_and_in_the_excel_file(): void
    {
        // 150 delivered at 100.00 paid in full from Dhaka and 80 approved at 50.00 unpaid from Khulna, with a 60.00 cost. Only the delivered ones count in money.
        $this->orders($this->dhaka, 150, 'delivered', '100.00', '100.00');
        $this->orders($this->khulna, 80, 'approved', '50.00', '0.00');
        $expected = ['orders' => 230, 'value' => 150 * 100, 'paid' => 150 * 100, 'awaiting' => 80 * 50, 'profit' => 150 * (100 - 60)];

        $screen = $this->performance('/branch-performance?period=month&all_branches=1');
        $this->assertSame($expected['orders'], $screen['totals']['order_count']);
        $this->assertSame(number_format($expected['value'], 2, '.', ''), $screen['totals']['order_value']);
        $this->assertSame(number_format($expected['paid'], 2, '.', ''), $screen['totals']['income']);
        $this->assertSame(number_format($expected['awaiting'], 2, '.', ''), $screen['totals']['outstanding']);
        $this->assertSame(number_format($expected['profit'], 2, '.', ''), $screen['totals']['estimated_profit']);
        $this->assertSame($expected['orders'], $screen['orders']['total'], 'The list holds every order, not the newest 200.');

        $finance = User::factory()->create(['role' => 'finance', 'status' => 'active']);
        $rows = $this->rows($this->actingAs($finance)->onPortal('manage')->get('/exports/branch-performance?period=month')->assertOk());
        $this->assertCount($expected['orders'] + 2, $rows, 'Header, every order and the totals row.');
        $this->assertSame(
            [(float) $expected['value'], (float) $expected['paid'], (float) $expected['awaiting'], (float) $expected['profit']],
            array_map('floatval', array_slice($rows[$expected['orders'] + 1], 5, 4)),
        );
    }

    public function test_excel_download_holds_the_period_and_branch_on_screen(): void
    {
        $this->seedOrders();
        $finance = User::factory()->create(['role' => 'finance', 'status' => 'active']);

        $response = $this->actingAs($finance)->onPortal('manage')->get('/exports/branch-performance?period=month')
            ->assertOk()
            ->assertHeader('content-type', 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet');
        $rows = $this->rows($response);
        $this->assertCount(32, $rows, 'Header, 30 orders and the totals row. The rejected order is left out, as on screen.');
        $this->assertSame(__('portal.branch_perf_outstanding'), $rows[0][7]);
        $this->assertSame([2000.0, 2000.0, 500.0, 800.0], array_map('floatval', array_slice($rows[31], 5, 4)), 'The file adds up to what the screen shows.');
        $this->assertSame(__('portal.report_col_ordered'), $rows[0][9]);

        $branchRows = $this->rows($this->actingAs($finance)->onPortal('manage')->get('/exports/branch-performance?period=month&branch_id='.$this->dhaka->id)->assertOk());
        $this->assertCount(22, $branchRows);
        $this->assertStringNotContainsString('Khulna Hub', json_encode($branchRows));
        $this->assertDatabaseHas('audit_events', ['action' => 'report.exported', 'subject' => 'branch-performance']);
    }

    public function test_only_staff_with_the_reports_permission_may_download_and_only_their_branches(): void
    {
        $this->seedOrders();
        $storekeeper = User::factory()->create(['role' => 'inventory', 'status' => 'active']);
        $dealer = User::factory()->dealer()->create(['status' => 'active']);
        $this->actingAs($storekeeper)->onPortal('manage')->get('/exports/branch-performance')->assertForbidden();
        $this->actingAs($dealer)->onPortal('dealer')->get('/exports/branch-performance')->assertForbidden();

        $reportsClerk = User::factory()->staff('employee')->create(['profile' => ['permissions' => ['reports']], 'active_branch_id' => $this->khulna->id]);
        BranchStaffAssignment::query()->create(['branch_id' => $this->khulna->id, 'user_id' => $reportsClerk->id, 'duty' => 'storekeeper', 'is_primary' => true]);

        $own = $this->rows($this->actingAs($reportsClerk)->onPortal('manage')->get('/exports/branch-performance?period=month')->assertOk());
        $this->assertCount(12, $own, 'Header, the 10 Khulna orders and the totals row.');
        $this->assertStringNotContainsString('Dhaka Hub', json_encode($own));

        $this->actingAs($reportsClerk)->onPortal('manage')->get('/exports/branch-performance?period=month&branch_id='.$this->dhaka->id)->assertForbidden();
    }

    /** 20 paid orders from Dhaka, 10 half-paid from Khulna and one rejected order: more than one page. */
    private function seedOrders(): void
    {
        $this->orders($this->dhaka, 20, 'delivered', '100.00', '100.00');
        $this->orders($this->khulna, 10, 'approved', '100.00', '50.00');
        $this->orders($this->khulna, 1, 'rejected', '100.00', '0.00');
    }

    /** Insert one-line orders that sell one unit at the order total. */
    private function orders(CompanyBranch $branch, int $count, string $status, string $total, string $paid): void
    {
        $orders = [];
        $lines = [];
        foreach (range(1, $count) as $index) {
            $orderId = (string) Str::uuid();
            $createdAt = now()->subSeconds($index);
            $orders[] = [
                'id' => $orderId,
                'number' => 'MN-BP-'.Str::upper(Str::random(10)),
                'user_id' => $this->partner->id,
                'status' => $status,
                'total' => $total,
                'paid' => $paid,
                'due_credit' => '0.00',
                'idempotency_key' => (string) Str::uuid(),
                'payload_hash' => hash('sha256', $orderId),
                'fulfillment_branch_id' => $branch->id,
                'created_at' => $createdAt,
                'updated_at' => $createdAt,
            ];
            $lines[] = [
                'order_id' => $orderId,
                'product_id' => $this->product->id,
                'name' => $this->product->name,
                'sku' => $this->product->sku,
                'quantity' => 1,
                'dispatched' => 0,
                'mrp' => $total,
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

    /** @return array<string, mixed> */
    private function performance(string $path): array
    {
        $version = (string) (new HandleInertiaRequests)->version(Request::create('/'));
        $body = $this->actingAs($this->owner)->onPortal('sadmin')
            ->withHeaders(['X-Inertia' => 'true', 'X-Inertia-Version' => $version])
            ->get($path)->assertOk()->json('props.data.performance');
        $this->flushHeaders();

        return $body;
    }

    /** @return list<list<mixed>> */
    private function rows(TestResponse $response): array
    {
        $path = $response->baseResponse->getFile()->getPathname();
        $reader = new Reader;
        $reader->open($path);
        $rows = [];
        foreach ($reader->getSheetIterator() as $sheet) {
            foreach ($sheet->getRowIterator() as $row) {
                $rows[] = $row->toArray();
            }
            break;
        }
        $reader->close();
        @unlink($path);

        return $rows;
    }
}
