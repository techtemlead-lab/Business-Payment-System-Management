<?php

namespace Tests\Feature;

use App\Domain\Inventory\BranchOperations;
use App\Domain\Inventory\BranchScope;
use App\Domain\Inventory\StockPrintList;
use App\Models\CompanyBranch;
use App\Models\Product;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\App;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Lang;
use Illuminate\Support\Str;
use Tests\TestCase;

/**
 * The printed stock list: who may download it, and what each row and the grand total say.
 */
class StockPrintTest extends TestCase
{
    use RefreshDatabase;

    private CompanyBranch $hub;

    private CompanyBranch $khulna;

    protected function setUp(): void
    {
        parent::setUp();
        $this->withoutVite();
        $this->hub = CompanyBranch::query()->where('code', 'HO-PIRGANJ')->firstOrFail();
        $this->khulna = CompanyBranch::factory()->create(['name' => 'Khulna Hub']);
    }

    public function test_staff_with_stock_access_download_the_pdf_and_it_is_audited(): void
    {
        $this->stockOf($this->product(['name' => 'Herbal Syrup', 'pack_size_en' => '100 ml', 'mrp' => '200.00']), $this->hub, 5, '2027-03-31');
        $storekeeper = User::factory()->create(['role' => 'inventory', 'status' => 'active']);

        $response = $this->actingAs($storekeeper)->onPortal('manage')->get('/exports/stock-print')
            ->assertOk()
            ->assertHeader('content-type', 'application/pdf');

        $this->assertStringStartsWith('%PDF', file_get_contents($response->baseResponse->getFile()->getPathname()));
        $this->assertStringContainsString('Madina-stock-list-', (string) $response->headers->get('content-disposition'));
        $this->assertDatabaseHas('audit_events', ['action' => 'report.exported', 'subject' => 'stock-print']);
    }

    public function test_the_pdf_also_renders_in_bangla_and_when_nothing_is_in_stock(): void
    {
        $owner = User::factory()->staff()->create();
        App::setLocale('bn');

        $this->actingAs($owner)->onPortal('sadmin')->get('/exports/stock-print')
            ->assertOk()
            ->assertHeader('content-type', 'application/pdf');
    }

    public function test_people_without_stock_access_are_refused(): void
    {
        $finance = User::factory()->create(['role' => 'finance', 'status' => 'active']);
        $dealer = User::factory()->create(['role' => 'dealer', 'status' => 'active']);

        $this->actingAs($finance)->onPortal('manage')->get('/exports/stock-print')->assertForbidden();
        $this->actingAs($dealer)->onPortal('dealer')->get('/exports/stock-print')->assertForbidden();
    }

    public function test_a_guest_cannot_download_the_list(): void
    {
        $this->onPortal('sadmin')->get('/exports/stock-print')->assertRedirect();
    }

    public function test_a_row_shows_pack_size_expiry_quantity_mrp_both_discounts_and_the_total(): void
    {
        $product = $this->product([
            'name' => 'Herbal Syrup', 'strength' => null, 'pack_size_en' => '100 ml', 'mrp' => '200.00',
            'dealer_price' => '160.00', 'sub_dealer_price' => '175.00',
        ]);
        $this->stockOf($product, $this->hub, 7, '2027-03-31');

        $list = (new StockPrintList)->build(new BranchScope(false, [$this->hub->id], $this->hub));

        $this->assertSame([[
            'serial' => 1,
            'name' => 'Herbal Syrup',
            'pack_size' => '100 ml',
            'expires_on' => '2027-03-31',
            'quantity' => 7,
            'mrp' => '200.00',
            'dealer_discount' => '20',
            'sub_dealer_discount' => '12.5',
            'total' => '1400.00',
        ]], $list['rows']);
        $this->assertSame(7, $list['total_quantity']);
        $this->assertSame('1400.00', $list['total_value']);
    }

    public function test_stock_with_no_expiry_date_is_listed_with_a_blank_date_not_dropped(): void
    {
        $this->stockOf($this->product(['name' => 'Undated Tonic', 'mrp' => '50.00']), $this->hub, 3, null);
        $this->stockOf($this->product(['name' => 'Dated Tonic', 'mrp' => '50.00']), $this->hub, 2, '2027-01-15');

        $rows = (new StockPrintList)->build(new BranchScope(false, [$this->hub->id], $this->hub))['rows'];

        $this->assertSame(['Dated Tonic', 'Undated Tonic'], array_column($rows, 'name'));
        $this->assertSame(['2027-01-15', null], array_column($rows, 'expires_on'));
        $this->assertSame([2, 3], array_column($rows, 'quantity'));
    }

    public function test_one_product_with_two_expiry_dates_gets_one_row_per_date_soonest_first_and_undated_last(): void
    {
        $product = $this->product(['name' => 'Herbal Syrup', 'mrp' => '100.00']);
        $this->stockOf($product, $this->hub, 4, null);
        $this->stockOf($product, $this->hub, 6, '2027-09-30');
        $this->stockOf($product, $this->hub, 10, '2027-02-28');
        // A second lot with the same date is added into that date's row.
        $this->stockOf($product, $this->hub, 5, '2027-02-28');

        $rows = (new StockPrintList)->build(new BranchScope(false, [$this->hub->id], $this->hub))['rows'];

        $this->assertSame(['2027-02-28', '2027-09-30', null], array_column($rows, 'expires_on'));
        $this->assertSame([15, 6, 4], array_column($rows, 'quantity'));
        $this->assertSame([1, 2, 3], array_column($rows, 'serial'));
    }

    public function test_units_that_were_never_put_in_a_lot_are_listed_under_the_products_own_date(): void
    {
        $product = $this->product(['name' => 'Old Stock Oil', 'mrp' => '80.00', 'expires_on' => '2027-06-30']);
        DB::table('branch_product_stocks')->insert(['product_id' => $product->id, 'branch_id' => $this->hub->id, 'quantity' => 9, 'created_at' => now(), 'updated_at' => now()]);

        $rows = (new StockPrintList)->build(new BranchScope(false, [$this->hub->id], $this->hub))['rows'];

        $this->assertCount(1, $rows);
        $this->assertSame(9, $rows[0]['quantity']);
        $this->assertSame('2027-06-30', $rows[0]['expires_on']);
        $this->assertSame('720.00', $rows[0]['total']);
    }

    public function test_a_missing_price_shows_no_discount_and_a_price_above_mrp_is_not_called_a_discount(): void
    {
        $this->stockOf($this->product(['name' => 'No Trade Price', 'mrp' => '100.00', 'dealer_price' => null, 'sub_dealer_price' => '0.00']), $this->hub, 1, null);
        $this->stockOf($this->product(['name' => 'Odd Price', 'mrp' => '100.00', 'dealer_price' => '120.00', 'sub_dealer_price' => '66.67']), $this->hub, 1, null);

        $rows = (new StockPrintList)->build(new BranchScope(false, [$this->hub->id], $this->hub))['rows'];

        $this->assertSame([null, null], [$rows[0]['dealer_discount'], $rows[0]['sub_dealer_discount']]);
        $this->assertNull($rows[1]['dealer_discount']);
        $this->assertSame('33.33', $rows[1]['sub_dealer_discount']);
    }

    public function test_the_grand_total_adds_every_row_with_exact_decimals(): void
    {
        $this->stockOf($this->product(['name' => 'A', 'mrp' => '0.10']), $this->hub, 3, null);
        $this->stockOf($this->product(['name' => 'B', 'mrp' => '0.20']), $this->hub, 3, null);
        $this->stockOf($this->product(['name' => 'C', 'mrp' => '1234567.89']), $this->hub, 2, null);

        $list = (new StockPrintList)->build(new BranchScope(false, [$this->hub->id], $this->hub));

        $this->assertSame(['0.30', '0.60', '2469135.78'], array_column($list['rows'], 'total'));
        $this->assertSame(8, $list['total_quantity']);
        $this->assertSame('2469136.68', $list['total_value']);
    }

    public function test_a_branch_list_leaves_out_other_branches_and_all_branches_adds_them_up(): void
    {
        $product = $this->product(['name' => 'Herbal Syrup', 'mrp' => '10.00']);
        $this->stockOf($product, $this->hub, 4, '2027-05-05');
        $this->stockOf($product, $this->khulna, 6, '2027-05-05');

        $hubOnly = (new StockPrintList)->build(new BranchScope(false, [$this->hub->id], $this->hub));
        $everywhere = (new StockPrintList)->build(new BranchScope(true, []));
        $nowhere = (new StockPrintList)->build(new BranchScope(false, []));

        $this->assertSame(4, $hubOnly['total_quantity']);
        $this->assertSame(10, $everywhere['total_quantity']);
        $this->assertCount(1, $everywhere['rows']);
        $this->assertSame([], $nowhere['rows']);
    }

    public function test_an_empty_stock_gives_an_empty_list_and_zero_totals(): void
    {
        $this->product(['name' => 'Sold Out', 'mrp' => '10.00']);

        $list = (new StockPrintList)->build(new BranchScope(true, []));

        $this->assertSame(['rows' => [], 'total_quantity' => 0, 'total_value' => '0.00'], $list);
    }

    public function test_both_languages_carry_the_same_keys_and_the_bangla_text_is_not_a_copy_of_the_english(): void
    {
        $english = require lang_path('en/stock_print.php');
        $bangla = require lang_path('bn/stock_print.php');

        $this->assertSame(array_keys($english), array_keys($bangla));
        foreach ($english as $key => $text) {
            $this->assertNotSame($text, $bangla[$key], $key.' is not translated.');
            $this->assertMatchesRegularExpression('/\p{Bengali}/u', $bangla[$key], $key.' has no Bangla letters.');
        }
        $this->assertTrue(Lang::has('stock_print.button', 'en'));
    }

    /** @param  array<string, mixed>  $attributes */
    private function product(array $attributes): Product
    {
        return Product::factory()->create(['stock' => 0, 'batch_number' => null, 'expires_on' => null, ...$attributes]);
    }

    private function stockOf(Product $product, CompanyBranch $branch, int $quantity, ?string $expiresOn): void
    {
        $owner = User::factory()->staff()->create();
        app(BranchOperations::class)->adjustStock($owner, [
            'product_id' => $product->id,
            'branch_id' => $branch->id,
            'kind' => BranchOperations::KindProductIn,
            'quantity' => $quantity,
            'reason_code' => 'supplier_receipt',
            'reason' => 'supplier_receipt',
            'reference' => (string) Str::uuid(),
            'expires_on' => $expiresOn,
        ]);
    }
}
