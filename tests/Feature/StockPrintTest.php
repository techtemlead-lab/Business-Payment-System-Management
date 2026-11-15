<?php

namespace Tests\Feature;

use App\Domain\Inventory\BranchOperations;
use App\Domain\Inventory\BranchScope;
use App\Domain\Inventory\StockPrintList;
use App\Domain\Inventory\StockPrintName;
use App\Domain\Inventory\StockPrintPdf;
use App\Models\CompanyBranch;
use App\Models\Product;
use App\Models\User;
use App\Support\SiteCustomization;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\App;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Lang;
use Illuminate\Support\Str;
use PHPUnit\Framework\Attributes\DataProvider;
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

    public function test_a_row_shows_name_pack_size_category_mrp_and_both_discounts_and_nothing_about_quantity(): void
    {
        $product = $this->product([
            'name' => 'Herbal Syrup', 'strength' => null, 'category' => 'Syrup', 'pack_size_en' => '100 ml', 'mrp' => '200.00',
            'dealer_price' => '160.00', 'sub_dealer_price' => '175.00',
        ]);
        $this->stockOf($product, $this->hub, 7, '2027-03-31');

        $list = (new StockPrintList)->build(new BranchScope(false, [$this->hub->id], $this->hub));

        $this->assertSame(['rows'], array_keys($list));
        $this->assertSame([[
            'serial' => 1,
            'name' => 'Herbal Syrup',
            'pack_size' => '100 ml',
            'category' => 'Syrup',
            'mrp' => '200.00',
            'dealer_discount' => '20',
            'sub_dealer_discount' => '12.5',
        ]], $list['rows']);
    }

    public function test_the_size_in_the_name_moves_to_the_pack_size_column(): void
    {
        $this->stockOf($this->product(['name' => 'Asthma Kit (অাজমা কিট) 30Tab', 'category' => 'Medicine', 'mrp' => '400.00']), $this->hub, 2, null);
        $this->stockOf($this->product(['name' => 'Syrup Ulica (সিরাপ ইউলিকা) 100 ML', 'category' => 'Syrup', 'pack_size_en' => '100 ml', 'mrp' => '180.00']), $this->hub, 2, null);

        $rows = (new StockPrintList)->build(new BranchScope(false, [$this->hub->id], $this->hub))['rows'];

        $this->assertSame(['Asthma Kit (অাজমা কিট)', 'Syrup Ulica (সিরাপ ইউলিকা)'], array_column($rows, 'name'));
        $this->assertSame(['30Tab', '100 ml'], array_column($rows, 'pack_size'));
    }

    /** @return array<string, array{0: string, 1: ?string, 2: string, 3: ?string}> */
    public static function nameSplits(): array
    {
        return [
            'grams' => ['AD Ointment (এডি অয়েন্টমেন্ট) 10g', null, 'AD Ointment (এডি অয়েন্টমেন্ট)', '10g'],
            'tablets' => ['Ltab (এলট্যাব) 30Tab', null, 'Ltab (এলট্যাব)', '30Tab'],
            'strips' => ['Cap Faitocap (ফাইটোক্যাপ) 3x10\'s', null, 'Cap Faitocap (ফাইটোক্যাপ)', "3x10's"],
            'brackets' => ['Coffee Face Scrub (কফি ফেস স্ক্রাব) (250gm)', null, 'Coffee Face Scrub (কফি ফেস স্ক্রাব)', '250gm'],
            'repeated' => ['Dialess (ডায়ালেস) 30Cap-30 Cap', null, 'Dialess (ডায়ালেস)', '30Cap-30 Cap'],
            'space inside' => ['Ere X plus (ইরে এক্স প্লাস) 4 Cap', null, 'Ere X plus (ইরে এক্স প্লাস)', '4 Cap'],
            'after a dash' => ['Nvit-B (এনভিটি-বি)-450ml', null, 'Nvit-B (এনভিটি-বি)', '450ml'],
            'a percent is not a size' => ['Serum (ফেস সিরাম) 10%', null, 'Serum (ফেস সিরাম) 10%', null],
            'a letter before the number is part of the name' => ['Vitamin B12 Tab', null, 'Vitamin B12 Tab', null],
            'no size at all' => ['Herbal Oil', null, 'Herbal Oil', null],
            'the saved size wins and the same words leave the name' => ['Syrup Ulica 100 ML', '100 ml', 'Syrup Ulica', '100 ml'],
            'a saved size that differs keeps the name whole' => ['Syrup Ulica 100 ML', 'small', 'Syrup Ulica 100 ML', 'small'],
            'a name that is only a size keeps its words' => ['250 ML', null, '250 ML', null],
        ];
    }

    #[DataProvider('nameSplits')]
    public function test_names_are_split_into_name_and_pack_size(string $name, ?string $saved, string $expectedName, ?string $expectedSize): void
    {
        $this->assertSame([$expectedName, $expectedSize], StockPrintName::split($name, $saved));
    }

    public function test_products_are_grouped_by_category_and_those_without_one_come_last(): void
    {
        $this->stockOf($this->product(['name' => 'Zinc Tab', 'category' => 'Medicine', 'mrp' => '10.00']), $this->hub, 1, null);
        $this->stockOf($this->product(['name' => 'Orphan Product', 'category' => '', 'mrp' => '10.00']), $this->hub, 1, null);
        $this->stockOf($this->product(['name' => 'Cough Syrup', 'category' => 'Syrup', 'mrp' => '10.00']), $this->hub, 1, null);
        $this->stockOf($this->product(['name' => 'Amla Tab', 'category' => 'Medicine', 'mrp' => '10.00']), $this->hub, 1, null);
        $this->stockOf($this->product(['name' => 'Basil Syrup', 'category' => 'Syrup', 'mrp' => '10.00']), $this->hub, 1, null);

        $rows = (new StockPrintList)->build(new BranchScope(false, [$this->hub->id], $this->hub))['rows'];

        $this->assertSame(['Amla Tab', 'Zinc Tab', 'Basil Syrup', 'Cough Syrup', 'Orphan Product'], array_column($rows, 'name'));
        $this->assertSame(['Medicine', 'Medicine', 'Syrup', 'Syrup', null], array_column($rows, 'category'));
        $this->assertSame([1, 2, 3, 4, 5], array_column($rows, 'serial'));
    }

    public function test_stock_with_no_expiry_date_is_still_listed_and_batches_make_one_row_per_product(): void
    {
        $product = $this->product(['name' => 'Herbal Syrup', 'mrp' => '100.00']);
        $this->stockOf($product, $this->hub, 4, null);
        $this->stockOf($product, $this->hub, 6, '2027-09-30');
        $this->stockOf($product, $this->hub, 10, '2027-02-28');
        $this->stockOf($this->product(['name' => 'Undated Tonic', 'mrp' => '50.00']), $this->hub, 3, null);

        $rows = (new StockPrintList)->build(new BranchScope(false, [$this->hub->id], $this->hub))['rows'];

        $this->assertSame(['Herbal Syrup', 'Undated Tonic'], array_column($rows, 'name'));
    }

    public function test_units_that_were_never_put_in_a_lot_still_make_the_product_appear(): void
    {
        $product = $this->product(['name' => 'Old Stock Oil', 'mrp' => '80.00', 'expires_on' => '2027-06-30']);
        DB::table('branch_product_stocks')->insert(['product_id' => $product->id, 'branch_id' => $this->hub->id, 'quantity' => 9, 'created_at' => now(), 'updated_at' => now()]);

        $rows = (new StockPrintList)->build(new BranchScope(false, [$this->hub->id], $this->hub))['rows'];

        $this->assertSame(['Old Stock Oil'], array_column($rows, 'name'));
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

    public function test_a_branch_list_leaves_out_other_branches_and_all_branches_lists_every_stocked_product_once(): void
    {
        $this->stockOf($this->product(['name' => 'Hub Only', 'mrp' => '10.00']), $this->hub, 4, '2027-05-05');
        $shared = $this->product(['name' => 'Shared', 'mrp' => '10.00']);
        $this->stockOf($shared, $this->hub, 4, '2027-05-05');
        $this->stockOf($shared, $this->khulna, 6, '2027-05-05');
        $this->stockOf($this->product(['name' => 'Khulna Only', 'mrp' => '10.00']), $this->khulna, 2, null);

        $hubOnly = (new StockPrintList)->build(new BranchScope(false, [$this->hub->id], $this->hub));
        $everywhere = (new StockPrintList)->build(new BranchScope(true, []));
        $nowhere = (new StockPrintList)->build(new BranchScope(false, []));

        $this->assertSame(['Hub Only', 'Shared'], array_column($hubOnly['rows'], 'name'));
        $this->assertSame(['Hub Only', 'Khulna Only', 'Shared'], array_column($everywhere['rows'], 'name'));
        $this->assertSame([], $nowhere['rows']);
    }

    public function test_an_empty_stock_gives_an_empty_list(): void
    {
        $this->product(['name' => 'Sold Out', 'mrp' => '10.00']);

        $this->assertSame(['rows' => []], (new StockPrintList)->build(new BranchScope(true, [])));
    }

    public function test_the_letterhead_has_both_phone_numbers_65_first_and_the_licence_and_trademark_numbers(): void
    {
        $company = app(StockPrintPdf::class)->company();

        $this->assertSame('+8801730532965, +8801730532966', $company['phones']);
        $this->assertSame('madinanaturalhealthcare@gmail.com', $company['email']);
        $this->assertSame('2026-00563', $company['licence']);
        $this->assertSame('BD-H-1-335903', $company['trademark']);

        SiteCustomization::save(['trust_trade_licence' => 'TL-9', 'trust_trademark' => 'BD-H-1-111111 (Class 5)']);
        $changed = app(StockPrintPdf::class)->company();
        $this->assertSame('TL-9', $changed['licence']);
        $this->assertSame('BD-H-1-111111', $changed['trademark']);
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
