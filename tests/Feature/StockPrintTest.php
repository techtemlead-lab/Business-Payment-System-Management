<?php

namespace Tests\Feature;

use App\Domain\Inventory\BranchOperations;
use App\Domain\Inventory\BranchScope;
use App\Domain\Inventory\StockPrintRows;
use App\Models\CompanyBranch;
use App\Models\Product;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\App;
use Illuminate\Support\Facades\Lang;
use Illuminate\Support\Str;
use Tests\TestCase;

/**
 * The stock print: the Stock page's rows, branch and filters as one PDF, with the product details,
 * batches, last movement and, only for people who may see costs, the value at cost.
 */
class StockPrintTest extends TestCase
{
    use RefreshDatabase;

    private CompanyBranch $hub;

    protected function setUp(): void
    {
        parent::setUp();
        $this->withoutVite();
        $this->hub = CompanyBranch::query()->where('code', 'HO-PIRGANJ')->firstOrFail();
    }

    public function test_staff_with_stock_access_download_the_stock_pdf_and_it_is_audited(): void
    {
        $this->stocked('Herbal Syrup', 5);
        $storekeeper = User::factory()->create(['role' => 'inventory', 'status' => 'active']);

        $response = $this->actingAs($storekeeper)->onPortal('manage')->get('/exports/stock-print')
            ->assertOk()
            ->assertHeader('content-type', 'application/pdf');

        $this->assertStringStartsWith('%PDF', file_get_contents($response->baseResponse->getFile()->getPathname()));
        $this->assertStringContainsString('Madina-stock-print-', (string) $response->headers->get('content-disposition'));
        $this->assertDatabaseHas('audit_events', ['action' => 'report.exported', 'subject' => 'stock-print']);
    }

    public function test_people_without_stock_access_are_refused(): void
    {
        $finance = User::factory()->create(['role' => 'finance', 'status' => 'active']);
        $dealer = User::factory()->create(['role' => 'dealer', 'status' => 'active']);

        $this->actingAs($finance)->onPortal('manage')->get('/exports/stock-print')->assertForbidden();
        $this->actingAs($dealer)->onPortal('dealer')->get('/exports/stock-print')->assertForbidden();
    }

    public function test_a_guest_cannot_download_the_stock(): void
    {
        $this->onPortal('sadmin')->get('/exports/stock-print')->assertRedirect();
    }

    public function test_the_pdf_also_renders_in_bangla_and_when_nothing_is_in_stock(): void
    {
        $owner = User::factory()->staff()->create();
        App::setLocale('bn');

        $this->actingAs($owner)->onPortal('sadmin')->get('/exports/stock-print')->assertOk()->assertHeader('content-type', 'application/pdf');
    }

    public function test_a_row_carries_the_product_details_the_stages_the_batches_and_the_last_movement(): void
    {
        $product = $this->stocked('Honey Syrup 200 ML', 8, '2027-06-30', ['sku' => 'HS-200', 'category' => 'Syrup', 'unit' => 'bottle', 'mrp' => '250.00', 'cost_price' => '100.00', 'low_stock_at' => 3]);
        $owner = User::factory()->staff()->create();

        $stock = $this->build($owner);
        $row = $stock['rows'][0];

        $this->assertSame($product->id, $row['product_id']);
        $this->assertSame('Honey Syrup', $row['name']);
        $this->assertSame('200 ML', $row['pack_size']);
        $this->assertSame('HS-200', $row['sku']);
        $this->assertSame('Syrup', $row['category']);
        $this->assertSame('bottle', $row['unit']);
        $this->assertSame('250.00', $row['mrp']);
        $this->assertSame(8, $row['in_stock']);
        $this->assertSame(0, $row['booked']);
        $this->assertSame('full', $row['level']);
        $this->assertSame([['batch' => null, 'expires_on' => '2027-06-30', 'quantity' => 8]], $row['lots']);
        $this->assertSame(now('Asia/Dhaka')->format('Y-m-d'), $row['last_movement']);
        $this->assertSame('2000.00', $row['price_value']);
        $this->assertArrayNotHasKey('image', $row);
        $this->assertSame(8, $stock['totals']['in_stock']);
    }

    public function test_cost_figures_are_printed_only_for_people_who_may_see_costs(): void
    {
        $this->stocked('Costly Syrup', 4, null, ['cost_price' => '100.00', 'mrp' => '250.00']);
        $storekeeper = User::factory()->create(['role' => 'inventory', 'status' => 'active']);
        $owner = User::factory()->create(['role' => 'super_admin', 'status' => 'active']);
        $this->assertFalse($storekeeper->allows('cost_read'));

        $without = $this->build($storekeeper);
        $with = $this->build($owner);

        $this->assertFalse($without['shows_cost']);
        $this->assertNull($without['rows'][0]['cost_value']);
        $this->assertNull($without['totals']['cost_value']);
        $this->assertTrue($with['shows_cost']);
        $this->assertSame('400.00', $with['rows'][0]['cost_value']);
        $this->assertSame('400.00', $with['totals']['cost_value']);
    }

    public function test_the_filters_of_the_stock_page_narrow_the_print(): void
    {
        $this->stocked('Syrup One', 5, null, ['category' => 'Syrup', 'low_stock_at' => 1]);
        $this->stocked('Oil One', 1, null, ['category' => 'Oil', 'low_stock_at' => 3]);
        $owner = User::factory()->staff()->create();

        $byCategory = $this->build($owner, ['category' => 'Oil']);
        $low = $this->build($owner, ['level' => 'low']);
        $all = $this->build($owner);

        $this->assertSame(['Oil One'], array_column($byCategory['rows'], 'name'));
        $this->assertTrue($byCategory['filtered']);
        $this->assertSame(['Oil One'], array_column($low['rows'], 'name'));
        $this->assertSame('low', $low['rows'][0]['level']);
        $this->assertSame(['Oil One', 'Syrup One'], array_column($all['rows'], 'name'));
        $this->assertFalse($all['filtered']);
    }

    public function test_the_stock_print_has_no_product_pictures(): void
    {
        $this->stocked('Old Style', 2);
        $this->stocked('Other Style', 2, null, ['category' => 'Black seed oil']);
        $owner = User::factory()->create(['role' => 'super_admin', 'status' => 'active']);

        $response = $this->actingAs($owner)->onPortal('sadmin')->get('/exports/stock-print')->assertOk();
        $pdf = (string) file_get_contents($response->baseResponse->getFile()->getPathname());

        // Only the letterhead logo and its transparency layer are pictures; no product picture is added.
        $this->assertLessThanOrEqual(2, preg_match_all('#/Subtype\s*/Image#', $pdf));
    }

    public function test_both_languages_carry_the_same_keys_and_the_bangla_text_is_not_a_copy_of_the_english(): void
    {
        $english = require lang_path('en/stock_print.php');
        $bangla = require lang_path('bn/stock_print.php');

        $this->assertSame(array_keys($english), array_keys($bangla));
        foreach ($english as $key => $text) {
            $this->assertMatchesRegularExpression('/\p{Bengali}/u', $bangla[$key] ?? '', $key.' has no Bangla letters.');
        }
        $this->assertTrue(Lang::has('stock_print.title', 'en'));
    }

    /**
     * @param  array<string, string>  $query
     * @return array<string, mixed>
     */
    private function build(User $viewer, array $query = []): array
    {
        return app(StockPrintRows::class)->build(Request::create('/exports/stock-print', 'GET', $query), new BranchScope(true, []), $viewer);
    }

    /** @param  array<string, mixed>  $attributes */
    private function stocked(string $name, int $quantity, ?string $expiresOn = null, array $attributes = []): Product
    {
        $product = Product::factory()->create(['name' => $name, 'stock' => 0, 'batch_number' => null, 'expires_on' => null, 'active' => true, ...$attributes]);
        app(BranchOperations::class)->adjustStock(User::factory()->staff()->create(), [
            'product_id' => $product->id,
            'branch_id' => $this->hub->id,
            'kind' => BranchOperations::KindProductIn,
            'quantity' => $quantity,
            'reason_code' => 'supplier_receipt',
            'reason' => 'supplier_receipt',
            'reference' => (string) Str::uuid(),
            'expires_on' => $expiresOn,
        ]);

        return $product;
    }
}
