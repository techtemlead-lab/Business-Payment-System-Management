<?php

namespace Tests\Feature;

use App\Http\Middleware\HandleInertiaRequests;
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

    private CompanyBranch $dhaka;

    private CompanyBranch $khulna;

    protected function setUp(): void
    {
        parent::setUp();
        $this->withoutVite();
        $this->owner = User::factory()->staff()->create();
        $partner = User::factory()->dealer()->create(['name' => 'Branch Buyer']);
        $product = Product::factory()->create(['cost_price' => '60.00']);
        $this->dhaka = CompanyBranch::factory()->create(['code' => 'DHK-01', 'name' => 'Dhaka Hub']);
        $this->khulna = CompanyBranch::factory()->create(['code' => 'KHL-01', 'name' => 'Khulna Hub']);
        // 20 paid orders from Dhaka and 10 half-paid orders from Khulna: more than one page.
        foreach (range(1, 20) as $index) {
            $this->order($partner, $product, $this->dhaka, 'delivered', '100.00', '100.00');
        }
        foreach (range(1, 10) as $index) {
            $this->order($partner, $product, $this->khulna, 'approved', '100.00', '50.00');
        }
        // A rejected order is still an order, but nobody is waiting for its money.
        $this->order($partner, $product, $this->khulna, 'rejected', '100.00', '0.00');
    }

    public function test_totals_cover_every_order_and_the_order_list_is_paged_on_the_server(): void
    {
        $first = $this->performance('/branch-performance?period=month');
        $second = $this->performance('/branch-performance?period=month&page=2');

        $this->assertSame(31, $first['totals']['order_count']);
        $this->assertSame('3100.00', $first['totals']['order_value']);
        $this->assertSame('2500.00', $first['totals']['income']);
        $this->assertSame('500.00', $first['totals']['outstanding']);
        $this->assertSame('1240.00', $first['totals']['estimated_profit']);
        $byCode = collect($first['branches'])->keyBy('code');
        $this->assertSame(20, $byCode['DHK-01']['order_count']);
        $this->assertSame('0.00', $byCode['DHK-01']['outstanding']);
        $this->assertSame(11, $byCode['KHL-01']['order_count']);
        $this->assertSame('500.00', $byCode['KHL-01']['outstanding']);

        $this->assertSame(31, $first['orders']['total']);
        $this->assertCount(25, $first['orders']['data']);
        $this->assertCount(6, $second['orders']['data']);
        $rejected = collect([...$first['orders']['data'], ...$second['orders']['data']])->firstWhere('status', 'rejected');
        $this->assertSame('0.00', $rejected['outstanding']);

        $khulna = $this->performance('/branch-performance?period=month&branch_id='.$this->khulna->id);
        $this->assertSame(11, $khulna['totals']['order_count']);
        $this->assertSame(11, $khulna['orders']['total']);
        $this->assertSame($this->khulna->id, $khulna['selected_branch_id']);
    }

    public function test_excel_download_holds_every_order_for_the_period_and_branch(): void
    {
        $finance = User::factory()->create(['role' => 'finance', 'status' => 'active']);

        $response = $this->actingAs($finance)->onPortal('manage')->get('/exports/branch-performance?period=month')
            ->assertOk()
            ->assertHeader('content-type', 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet');
        $rows = $this->rows($response);
        $this->assertCount(33, $rows, 'Header, 31 orders and the totals row.');
        $this->assertSame(__('portal.branch_perf_outstanding'), $rows[0][7]);
        $this->assertSame([3100.0, 2500.0, 500.0, 1240.0], array_map('floatval', array_slice($rows[32], 5, 4)));

        $branchRows = $this->rows($this->actingAs($finance)->onPortal('manage')->get('/exports/branch-performance?period=month&branch_id='.$this->dhaka->id)->assertOk());
        $this->assertCount(22, $branchRows);
        $this->assertStringNotContainsString('Khulna Hub', json_encode($branchRows));
        $this->assertDatabaseHas('audit_events', ['action' => 'report.exported', 'subject' => 'branch-performance']);
    }

    public function test_only_staff_with_the_reports_permission_may_download(): void
    {
        $storekeeper = User::factory()->create(['role' => 'inventory', 'status' => 'active']);
        $dealer = User::factory()->dealer()->create(['status' => 'active']);

        $this->actingAs($storekeeper)->onPortal('manage')->get('/exports/branch-performance')->assertForbidden();
        $this->actingAs($dealer)->onPortal('dealer')->get('/exports/branch-performance')->assertForbidden();
    }

    private function order(User $partner, Product $product, CompanyBranch $branch, string $status, string $total, string $paid): void
    {
        $orderId = (string) Str::uuid();
        DB::table('orders')->insert([
            'id' => $orderId,
            'number' => 'MN-BP-'.Str::upper(Str::random(6)),
            'user_id' => $partner->id,
            'status' => $status,
            'total' => $total,
            'paid' => $paid,
            'due_credit' => '0.00',
            'idempotency_key' => (string) Str::uuid(),
            'payload_hash' => hash('sha256', $orderId),
            'fulfillment_branch_id' => $branch->id,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
        DB::table('order_lines')->insert([
            'order_id' => $orderId,
            'product_id' => $product->id,
            'name' => $product->name,
            'sku' => $product->sku,
            'quantity' => 1,
            'dispatched' => 0,
            'mrp' => $total,
            'unit_price' => $total,
            'total' => $total,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }

    /** @return array<string, mixed> */
    private function performance(string $path): array
    {
        $version = (string) (new HandleInertiaRequests)->version(Request::create('/'));

        return $this->actingAs($this->owner)->onPortal('sadmin')
            ->withHeaders(['X-Inertia' => 'true', 'X-Inertia-Version' => $version])
            ->get($path)->assertOk()->json('props.data.performance');
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
