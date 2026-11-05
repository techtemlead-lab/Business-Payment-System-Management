<?php

namespace Tests\Feature;

use App\Http\Middleware\HandleInertiaRequests;
use App\Models\CompanyBranch;
use App\Models\Product;
use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Testing\TestResponse;
use OpenSpout\Reader\XLSX\Reader;
use Tests\TestCase;

class StockMovementFiltersTest extends TestCase
{
    use RefreshDatabase;

    private User $owner;

    private CompanyBranch $dhaka;

    private CompanyBranch $khulna;

    private Product $syrup;

    private Product $tablet;

    protected function setUp(): void
    {
        parent::setUp();
        $this->withoutVite();
        $this->owner = User::factory()->staff()->create();
        $this->dhaka = CompanyBranch::factory()->create(['name' => 'Dhaka Hub']);
        $this->khulna = CompanyBranch::factory()->create(['name' => 'Khulna Hub']);
        $this->syrup = Product::factory()->create(['name' => 'Herbal Syrup', 'sku' => 'SYR-100']);
        $this->tablet = Product::factory()->create(['name' => 'Neem Tablet', 'sku' => 'TAB-200']);
        // 25 receipts of 4 units each into Dhaka on 10 March (Dhaka time): more than one page.
        foreach (range(1, 25) as $index) {
            $this->move($this->syrup, $this->dhaka, 4, 'product_in', 'Supplier lot '.$index, '2026-03-10 04:00:00');
        }
        $this->move($this->syrup, $this->dhaka, -6, 'dispatch', 'ORD-STOCK001', '2026-03-11 05:00:00');
        $this->move($this->tablet, $this->khulna, -3, 'product_out', 'Damaged', '2026-03-11 06:00:00');
        // 9 March 23:30 Dhaka time is still 9 March, so it falls outside a 10–11 March range.
        $this->move($this->tablet, $this->khulna, 50, 'receipt', 'Opening count', '2026-03-09 17:30:00');
    }

    public function test_filters_run_on_the_server_and_totals_cover_the_whole_filtered_range(): void
    {
        $range = 'period=custom&from=2026-03-10&to=2026-03-11';

        $data = $this->page('/inventory?'.$range);
        $this->assertSame(27, $data['props']['data']['movements']['total']);
        $this->assertCount(20, $data['props']['data']['movements']['data']);
        $this->assertSame(['count' => 27, 'units_in' => 100, 'units_out' => 9, 'net' => 91], $data['props']['data']['movement_totals']);
        $this->assertSame('custom', $data['props']['filters']['period']);

        $branch = $this->page('/inventory?'.$range.'&branch_id='.$this->khulna->id)['props']['data'];
        $this->assertSame(['count' => 1, 'units_in' => 0, 'units_out' => 3, 'net' => -3], $branch['movement_totals']);
        $this->assertSame(3, $branch['movements']['data'][0]['units_out']);
        $this->assertSame(0, $branch['movements']['data'][0]['units_in']);

        $product = $this->page('/inventory?product_id='.$this->tablet->id)['props']['data'];
        $this->assertSame(['count' => 2, 'units_in' => 50, 'units_out' => 3, 'net' => 47], $product['movement_totals']);

        $dispatches = $this->page('/inventory?movement=dispatch')['props']['data'];
        $this->assertSame(1, $dispatches['movements']['total']);
        $this->assertSame('ORD-STOCK001', $dispatches['movements']['data'][0]['reason']);

        $search = $this->page('/inventory?q=stock001')['props']['data'];
        $this->assertSame(1, $search['movements']['total']);

        $this->actingAs($this->owner)->onPortal('sadmin')->get('/inventory?movement=teleport')->assertSessionHasErrors('movement');
    }

    public function test_excel_download_matches_the_filtered_list(): void
    {
        $storekeeper = User::factory()->create(['role' => 'inventory', 'status' => 'active']);

        $response = $this->actingAs($storekeeper)->onPortal('manage')->get('/exports/stock-movements?period=custom&from=2026-03-10&to=2026-03-11&product_id='.$this->syrup->id)
            ->assertOk()
            ->assertHeader('content-type', 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet');
        $rows = $this->rows($response);

        $this->assertCount(28, $rows, 'Header, 26 syrup movements and the totals row.');
        $this->assertSame('Herbal Syrup', $rows[1][1]);
        $this->assertSame(__('portal.label_dispatch'), $rows[1][4]);
        $this->assertSame(6, (int) $rows[1][6]);
        $this->assertSame(100, (int) $rows[27][5]);
        $this->assertSame(6, (int) $rows[27][6]);
        $this->assertStringNotContainsString('Neem Tablet', json_encode($rows));
        $this->assertDatabaseHas('audit_events', ['action' => 'report.exported', 'subject' => 'stock-movements']);
    }

    public function test_only_staff_with_the_inventory_permission_may_download_movements(): void
    {
        $finance = User::factory()->create(['role' => 'finance', 'status' => 'active']);
        $dealer = User::factory()->dealer()->create(['status' => 'active']);

        $this->actingAs($finance)->onPortal('manage')->get('/exports/stock-movements')->assertForbidden();
        $this->actingAs($dealer)->onPortal('dealer')->get('/exports/stock-movements')->assertForbidden();
    }

    private function move(Product $product, CompanyBranch $branch, int $quantity, string $kind, string $reason, string $createdAt): void
    {
        DB::table('stock_movements')->insert([
            'product_id' => $product->id,
            'branch_id' => $branch->id,
            'quantity' => $quantity,
            'kind' => $kind,
            'reference' => (string) Str::uuid(),
            'reason' => $reason,
            'actor_id' => $this->owner->id,
            'created_at' => CarbonImmutable::parse($createdAt, 'UTC'),
            'updated_at' => CarbonImmutable::parse($createdAt, 'UTC'),
        ]);
    }

    /** @return array<string, mixed> */
    private function page(string $path): array
    {
        $version = (string) (new HandleInertiaRequests)->version(Request::create('/'));

        return $this->actingAs($this->owner)->onPortal('sadmin')
            ->withHeaders(['X-Inertia' => 'true', 'X-Inertia-Version' => $version])
            ->get($path)->assertOk()->json();
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
