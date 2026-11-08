<?php

namespace Tests\Feature;

use App\Domain\Inventory\BranchOperations;
use App\Models\BranchProductStock;
use App\Models\BranchStaffAssignment;
use App\Models\CompanyBranch;
use App\Models\Order;
use App\Models\Product;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Testing\TestResponse;
use Inertia\Testing\AssertableInertia as Assert;
use OpenSpout\Reader\XLSX\Reader;
use Tests\TestCase;

/**
 * The low-stock card, the Excel downloads linked from the branch-scoped pages and
 * single-order views follow the same branch rule as the lists: branch staff never
 * reach another branch's rows, and a picked branch narrows what is downloaded.
 */
class BranchScopedExportsTest extends TestCase
{
    use RefreshDatabase;

    private CompanyBranch $dhaka;

    private CompanyBranch $rajshahi;

    private User $partner;

    private Product $product;

    protected function setUp(): void
    {
        parent::setUp();
        $this->withoutVite();
        $this->dhaka = CompanyBranch::factory()->create(['code' => 'DHK-01', 'name' => 'Dhaka Hub']);
        $this->rajshahi = CompanyBranch::factory()->create(['code' => 'RAJ-01', 'name' => 'Rajshahi Hub']);
        $this->partner = User::factory()->dealer()->create();
        $this->product = Product::factory()->create(['stock' => 500, 'low_stock_at' => 1, 'active' => true]);
    }

    public function test_the_low_stock_card_and_count_follow_the_picked_branch(): void
    {
        $lowAtDhaka = Product::factory()->create(['name' => 'Low at Dhaka', 'stock' => 60, 'low_stock_at' => 10, 'active' => true]);
        BranchProductStock::query()->create(['branch_id' => $this->dhaka->id, 'product_id' => $lowAtDhaka->id, 'quantity' => 2, 'low_stock_at' => 5]);
        BranchProductStock::query()->create(['branch_id' => $this->rajshahi->id, 'product_id' => $lowAtDhaka->id, 'quantity' => 58, 'low_stock_at' => 5]);
        $lowCompanyWide = Product::factory()->create(['name' => 'Low company wide', 'stock' => 3, 'low_stock_at' => 10, 'active' => true]);
        BranchProductStock::query()->create(['branch_id' => $this->rajshahi->id, 'product_id' => $lowCompanyWide->id, 'quantity' => 3, 'low_stock_at' => 2]);

        $expect = function (TestResponse $response, array $names, int $count): void {
            $response->assertOk()->assertInertia(fn (Assert $page) => $page
                ->where('data.lowStock', fn ($rows) => collect($rows)->pluck('stock', 'name')->all() === $names)
                ->where('data.admin_home.work_queue', fn ($queue) => (int) collect($queue)->firstWhere('key', 'low_stock_products')['count'] === $count));
        };

        $expect($this->actingAs($this->ownerAt($this->dhaka))->onPortal('sadmin')->get('/overview'), ['Low at Dhaka' => 2], 1);
        $expect($this->actingAs($this->ownerAt($this->rajshahi))->onPortal('sadmin')->get('/overview'), [], 0);
        $expect($this->ownerOnAll()->get('/overview'), ['Low company wide' => 3], 1);
    }

    public function test_the_orders_download_follows_the_switcher_and_stays_inside_the_branch(): void
    {
        $orders = $this->ordersPerBranch();
        $staff = $this->branchStaff($this->dhaka);

        $this->assertDownloadHasOnly($this->actingAs($staff)->onPortal('manage')->get('/exports/orders')->assertOk()->streamedContent(), $orders, ['dhaka']);
        $this->assertDownloadHasOnly($this->actingAs($staff)->onPortal('manage')->get('/exports/orders?all_branches=1')->assertOk()->streamedContent(), $orders, ['dhaka']);
        $this->actingAs($staff)->onPortal('manage')->get('/exports/orders?branch_id='.$this->rajshahi->id)->assertForbidden();

        $this->assertDownloadHasOnly($this->actingAs($this->ownerAt($this->rajshahi))->onPortal('sadmin')->get('/exports/orders')->assertOk()->streamedContent(), $orders, ['rajshahi']);
        $this->assertDownloadHasOnly($this->ownerOnAll()->get('/exports/orders')->assertOk()->streamedContent(), $orders, ['dhaka', 'rajshahi', 'none']);
    }

    public function test_the_account_list_download_follows_the_picked_branch(): void
    {
        $dhakaPartner = User::factory()->dealer()->create(['name' => 'Dhaka Partner', 'active_branch_id' => $this->dhaka->id]);
        $rajshahiPartner = User::factory()->dealer()->create(['name' => 'Rajshahi Partner', 'active_branch_id' => $this->rajshahi->id]);

        $picked = $this->sheetText($this->actingAs($this->ownerAt($this->dhaka))->onPortal('sadmin')->get('/exports/accounts?section=accounts')->assertOk());
        $this->assertStringContainsString($dhakaPartner->name, $picked);
        $this->assertStringNotContainsString($rajshahiPartner->name, $picked);

        $all = $this->sheetText($this->ownerOnAll()->get('/exports/accounts?section=accounts')->assertOk());
        $this->assertStringContainsString($dhakaPartner->name, $all);
        $this->assertStringContainsString($rajshahiPartner->name, $all);
    }

    public function test_the_payments_download_follows_the_picked_branch(): void
    {
        $orders = $this->ordersPerBranch();
        foreach ($orders as $number) {
            DB::table('payment_claims')->insert(['order_id' => Order::query()->where('number', $number)->value('id'), 'channel' => 'bKash',
                'reference' => 'TX-'.$number, 'amount' => '100.00', 'status' => 'verified', 'created_at' => now(), 'updated_at' => now()]);
        }
        $url = '/commands/operations/reports?type=collections&format=xlsx&from='.now()->subDay()->format('Y-m-d').'&to='.now()->addDay()->format('Y-m-d');

        $this->assertDownloadHasOnly($this->sheetText($this->actingAs($this->ownerAt($this->dhaka))->onPortal('sadmin')->get($url)->assertOk()), $orders, ['dhaka']);
        $this->assertDownloadHasOnly($this->sheetText($this->ownerOnAll()->get($url)->assertOk()), $orders, ['dhaka', 'rajshahi', 'none']);
    }

    public function test_the_report_centre_order_files_stay_inside_the_branch_for_branch_staff(): void
    {
        $orders = $this->ordersPerBranch();
        $url = '/commands/operations/reports?type=orders&format=xlsx&from='.now()->subDay()->format('Y-m-d').'&to='.now()->addDay()->format('Y-m-d');
        $finance = User::factory()->staff('finance')->create(['active_branch_id' => $this->dhaka->id]);

        $this->assertDownloadHasOnly($this->sheetText($this->actingAs($this->branchStaff($this->dhaka, ['orders', 'reports']))->onPortal('manage')->get($url)->assertOk()), $orders, ['dhaka']);
        $this->assertDownloadHasOnly($this->sheetText($this->actingAs($finance)->onPortal('manage')->get($url)->assertOk()), $orders, ['dhaka', 'rajshahi', 'none']);
    }

    public function test_branch_staff_cannot_open_an_order_of_another_branch(): void
    {
        $own = $this->order($this->dhaka->id);
        $other = $this->order($this->rajshahi->id);
        $noBranchYet = $this->order(null);
        $staff = $this->branchStaff($this->dhaka);

        $this->actingAs($staff)->onPortal('manage')->get('/orders/'.$own->id)->assertOk();
        $this->actingAs($staff)->onPortal('manage')->get('/orders/'.$other->id)->assertNotFound();
        $this->actingAs($staff)->onPortal('manage')->get('/orders/'.$noBranchYet->id)->assertOk();
        $this->actingAs($staff)->onPortal('manage')->get('/orders/'.$own->id.'/invoice/print')->assertOk();
        $this->actingAs($staff)->onPortal('manage')->get('/orders/'.$other->id.'/invoice/print')->assertForbidden();

        $this->actingAs(User::factory()->staff('finance')->create())->onPortal('manage')->get('/orders/'.$other->id)->assertOk();
    }

    public function test_the_orders_api_stays_inside_the_branch_for_branch_staff(): void
    {
        $own = $this->order($this->dhaka->id);
        $other = $this->order($this->rajshahi->id);
        $staff = $this->branchStaff($this->dhaka);

        $this->actingAs($staff)->getJson('/api/v1/orders')->assertOk()
            ->assertJsonCount(1, 'data')->assertJsonPath('data.0.number', $own->number);
        $this->actingAs($staff)->getJson('/api/v1/orders/'.$other->id)->assertNotFound();
        $this->actingAs($staff)->getJson('/api/v1/orders/'.$own->id)->assertOk();
    }

    /**
     * @param  array{dhaka: string, rajshahi: string, none: string}  $orders
     * @param  list<string>  $expected
     */
    private function assertDownloadHasOnly(string $content, array $orders, array $expected): void
    {
        foreach ($orders as $branch => $number) {
            if (in_array($branch, $expected, true)) {
                $this->assertStringContainsString($number, $content, "Expected the {$branch} order in the file.");
            } else {
                $this->assertStringNotContainsString($number, $content, "The {$branch} order must not be in the file.");
            }
        }
    }

    private function sheetText(TestResponse $response): string
    {
        $path = $response->baseResponse->getFile()->getPathname();
        $reader = new Reader;
        $reader->open($path);
        $text = [];
        foreach ($reader->getSheetIterator() as $sheet) {
            foreach ($sheet->getRowIterator() as $row) {
                $text[] = implode('|', array_map(fn (mixed $cell): string => is_scalar($cell) ? (string) $cell : '', $row->toArray()));
            }
        }
        $reader->close();
        @unlink($path);

        return implode("\n", $text);
    }

    private function ownerOnAll(): self
    {
        return $this->actingAs(User::factory()->staff()->create())
            ->onPortal('sadmin')
            ->withSession([BranchOperations::SessionView => 'all']);
    }

    private function ownerAt(CompanyBranch $branch): User
    {
        $this->flushSession();

        return User::factory()->staff()->create(['active_branch_id' => $branch->id]);
    }

    /**
     * A staff member who works at one branch and may not view every branch.
     *
     * @param  list<string>  $permissions
     */
    private function branchStaff(CompanyBranch $branch, array $permissions = ['orders']): User
    {
        $staff = User::factory()->staff('employee')->create([
            'active_branch_id' => $branch->id,
            'profile' => ['permissions' => $permissions],
        ]);
        BranchStaffAssignment::query()->create(['branch_id' => $branch->id, 'user_id' => $staff->id, 'duty' => 'storekeeper', 'is_primary' => true]);

        return $staff;
    }

    /**
     * @return array{dhaka: string, rajshahi: string, none: string}
     */
    private function ordersPerBranch(): array
    {
        return [
            'dhaka' => $this->order($this->dhaka->id)->number,
            'rajshahi' => $this->order($this->rajshahi->id)->number,
            'none' => $this->order(null)->number,
        ];
    }

    private function order(?int $branchId): Order
    {
        $order = Order::create([
            'number' => 'ORD-'.Str::upper(Str::random(8)),
            'user_id' => $this->partner->id,
            'status' => 'dispatched',
            'total' => '500.00',
            'paid' => '0.00',
            'due_credit' => '0.00',
            'idempotency_key' => (string) Str::uuid(),
            'payload_hash' => hash('sha256', Str::random()),
        ]);
        $order->forceFill(['fulfillment_branch_id' => $branchId])->save();
        DB::table('order_lines')->insert([
            'order_id' => $order->id,
            'product_id' => $this->product->id,
            'name' => $this->product->name,
            'sku' => $this->product->sku,
            'quantity' => 2,
            'dispatched' => 1,
            'mrp' => '250.00',
            'unit_price' => '250.00',
            'total' => '500.00',
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        return $order;
    }
}
