<?php

namespace Tests\Feature;

use App\Domain\Inventory\BranchOperations;
use App\Domain\Inventory\StockPrintList;
use App\Domain\Inventory\StockPrintName;
use App\Domain\Inventory\StockPrintPdf;
use App\Models\CompanyBranch;
use App\Models\Product;
use App\Models\User;
use App\Support\SiteCustomization;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\App;
use Illuminate\Support\Facades\Lang;
use Illuminate\Support\Facades\Storage;
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
            'dealer_price' => '160.00', 'sub_dealer_price' => '175.00', 'description' => null,
        ]);
        $this->stockOf($product, $this->hub, 7, '2027-03-31');

        $list = (new StockPrintList)->build();

        $this->assertSame(['rows'], array_keys($list));
        $this->assertSame([[
            'serial' => 1,
            'name' => 'Herbal Syrup',
            'pack_size' => '100 ml',
            'category' => 'Syrup',
            'group' => null,
            'mrp' => '200.00',
            'dealer_discount' => '20',
            'sub_dealer_discount' => '12.5',
            'image' => null,
            'usage' => null,
            'efficacy' => null,
        ]], $list['rows']);
    }

    public function test_a_row_carries_the_photo_how_to_use_and_what_the_product_does(): void
    {
        Storage::fake('local');
        $stored = 'product-media/images/syrup.jpg';
        Storage::disk('local')->put($stored, 'photo');
        $product = $this->product([
            'name' => 'Herbal Syrup',
            'description' => '<b>Helps digestion</b>',
            'dose_en' => 'One spoon after food',
            'dose_bn' => 'খাবারের পর এক চামচ',
            'image_path' => $stored,
        ]);
        $this->stockOf($product, $this->hub, 1, null);

        $row = (new StockPrintList)->build()['rows'][0];

        $this->assertSame('Helps digestion', $row['efficacy']);
        $this->assertSame('One spoon after food', $row['usage']);
        $this->assertSame(Storage::disk('local')->path($stored), $row['image']);

        App::setLocale('bn');
        $bangla = (new StockPrintList)->build()['rows'][0];
        $this->assertSame('খাবারের পর এক চামচ', $bangla['usage']);
    }

    public function test_a_row_carries_the_group_in_the_page_language_and_the_efficacy_field_beats_the_description(): void
    {
        $product = $this->product([
            'name' => 'Napa Test', 'group_en' => 'Paracetamol', 'group_bn' => 'প্যারাসিটামল',
            'description' => 'Old description', 'efficacy_en' => 'Helps with fever',
        ]);
        $this->stockOf($product, $this->hub, 3, null);

        $row = (new StockPrintList)->build()['rows'][0];
        $this->assertSame('Paracetamol', $row['group']);
        $this->assertSame('Helps with fever', $row['efficacy']);

        App::setLocale('bn');
        $this->assertSame('প্যারাসিটামল', (new StockPrintList)->build()['rows'][0]['group']);
    }

    public function test_one_table_carries_photo_group_what_it_does_and_how_to_use_and_there_is_no_branch_or_date_line(): void
    {
        $html = view('invoices.stock-list', [
            'company' => app(StockPrintPdf::class)->company(),
            'rows' => [['serial' => 1, 'name' => 'Napa Test', 'pack_size' => null, 'category' => 'Tablet', 'group' => 'Paracetamol',
                'mrp' => '10.00', 'dealer_discount' => null, 'sub_dealer_discount' => null, 'image' => '/tmp/napa.jpg',
                'usage' => 'One after food', 'efficacy' => 'Helps with fever']],
        ])->render();

        $this->assertSame(1, substr_count($html, '<table class="items"'), 'The product list is one table.');
        $this->assertSame(1, substr_count($html, 'src="/tmp/napa.jpg"'));
        foreach (['col_image', 'col_group', 'col_category', 'col_efficacy', 'col_usage', 'col_mrp', 'col_dealer_discount', 'col_sub_dealer_discount'] as $column) {
            $this->assertSame(1, substr_count($html, '>'.__('stock_print.'.$column).'</th>'), $column);
        }
        $this->assertStringContainsString('Paracetamol', $html);
        $this->assertStringContainsString('One after food', $html);
        $this->assertStringContainsString('Helps with fever', $html);
        $this->assertStringNotContainsString('Branch', $html);
        $this->assertStringNotContainsString('class="meta"', $html);
    }

    public function test_the_size_in_the_name_moves_to_the_pack_size_column(): void
    {
        $this->stockOf($this->product(['name' => 'Asthma Kit (অাজমা কিট) 30Tab', 'category' => 'Medicine', 'mrp' => '400.00']), $this->hub, 2, null);
        $this->stockOf($this->product(['name' => 'Syrup Ulica (সিরাপ ইউলিকা) 100 ML', 'category' => 'Syrup', 'pack_size_en' => '100 ml', 'mrp' => '180.00']), $this->hub, 2, null);

        $rows = (new StockPrintList)->build()['rows'];

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

        $rows = (new StockPrintList)->build()['rows'];

        $this->assertSame(['Amla Tab', 'Zinc Tab', 'Basil Syrup', 'Cough Syrup', 'Orphan Product'], array_column($rows, 'name'));
        $this->assertSame(['Medicine', 'Medicine', 'Syrup', 'Syrup', null], array_column($rows, 'category'));
        $this->assertSame([1, 2, 3, 4, 5], array_column($rows, 'serial'));
    }

    public function test_only_live_products_are_listed_whatever_their_stock_and_branch(): void
    {
        $this->product(['name' => 'Live Without Stock', 'mrp' => '10.00']);
        $this->stockOf($this->product(['name' => 'Live In Khulna', 'mrp' => '10.00']), $this->khulna, 2, null);
        $this->product(['name' => 'Switched Off', 'mrp' => '10.00', 'active' => false]);
        $this->product(['name' => 'Removed From Sale', 'mrp' => '10.00', 'active' => false, 'archived_at' => now()]);

        $rows = (new StockPrintList)->build()['rows'];

        $this->assertSame(['Live In Khulna', 'Live Without Stock'], array_column($rows, 'name'));
    }

    public function test_no_live_products_gives_an_empty_list(): void
    {
        $this->product(['name' => 'Switched Off', 'mrp' => '10.00', 'active' => false]);

        $this->assertSame(['rows' => []], (new StockPrintList)->build());
    }

    public function test_a_missing_price_shows_no_discount_and_a_price_above_mrp_is_not_called_a_discount(): void
    {
        $this->stockOf($this->product(['name' => 'No Trade Price', 'mrp' => '100.00', 'dealer_price' => null, 'sub_dealer_price' => '0.00']), $this->hub, 1, null);
        $this->stockOf($this->product(['name' => 'Odd Price', 'mrp' => '100.00', 'dealer_price' => '120.00', 'sub_dealer_price' => '66.67']), $this->hub, 1, null);

        $rows = (new StockPrintList)->build()['rows'];

        $this->assertSame([null, null], [$rows[0]['dealer_discount'], $rows[0]['sub_dealer_discount']]);
        $this->assertNull($rows[1]['dealer_discount']);
        $this->assertSame('33.33', $rows[1]['sub_dealer_discount']);
    }

    public function test_the_letterhead_has_both_phone_numbers_65_first_and_the_licence_and_trademark_numbers(): void
    {
        $company = app(StockPrintPdf::class)->company();

        $this->assertSame('+8801730532966', $company['whatsapp']);
        $this->assertSame('+8801730532965', $company['mobile']);
        $this->assertStringEndsWith('madina-header-logo.png', (string) $company['logo_path']);
        $this->assertFileExists((string) $company['logo_path']);
        $this->assertSame('madinanaturalhealthcare@gmail.com', $company['email']);
        $this->assertSame(['whatsapp', 'mobile', 'email', 'website'], array_keys($company['icons']));
        $this->assertSame('2026-00563', $company['licence']);
        $this->assertSame('BD-H-1-335903', $company['trademark']);

        SiteCustomization::save(['trust_trade_licence' => 'TL-9', 'trust_trademark' => 'BD-H-1-111111 (Class 5)']);
        $changed = app(StockPrintPdf::class)->company();
        $this->assertSame('TL-9', $changed['licence']);
        $this->assertSame('BD-H-1-111111', $changed['trademark']);
    }

    public function test_the_whatsapp_line_follows_the_owners_whatsapp_link_not_the_last_digits(): void
    {
        SiteCustomization::save(['social_whatsapp' => 'https://wa.me/8801730532965?text=hello']);
        $swapped = app(StockPrintPdf::class)->company();
        $this->assertSame('+8801730532965', $swapped['whatsapp']);
        $this->assertSame('+8801730532966', $swapped['mobile']);

        SiteCustomization::save(['social_whatsapp' => 'https://wa.me/8801999999999']);
        $unmatched = app(StockPrintPdf::class)->company();
        $this->assertSame('+8801730532966', $unmatched['whatsapp']);
        $this->assertSame('+8801730532965', $unmatched['mobile']);
    }

    public function test_a_big_product_photo_is_shrunk_so_the_pdf_stays_small_and_no_copy_is_left_behind(): void
    {
        Storage::fake('local');
        $photo = $this->noisyPhoto();
        Storage::disk('local')->put('product-media/images/big.jpg', $photo);
        $product = $this->product(['name' => 'Photo Syrup', 'image_path' => 'product-media/images/big.jpg']);
        $this->stockOf($product, $this->hub, 3, null);
        $owner = User::factory()->staff()->create();

        $response = app(StockPrintPdf::class)->download($owner);
        $pdf = $response->getFile()->getPathname();

        $this->assertGreaterThan(200_000, strlen($photo), 'The test photo has to be big to prove anything.');
        $this->assertLessThan(strlen($photo), filesize($pdf), 'The PDF holds a small copy of the photo, not the whole file.');
        $this->assertSame([], glob(dirname($pdf).'/stock-photo-*.jpg') ?: [], 'The small copies are deleted once the PDF is written.');
    }

    public function test_photos_are_printed_big_enough_to_recognise_and_keep_their_shape(): void
    {
        $html = view('invoices.stock-list', [
            'company' => app(StockPrintPdf::class)->company(),
            'rows' => [['serial' => 1, 'name' => 'Wide Box', 'pack_size' => null, 'category' => null, 'group' => null, 'mrp' => '10.00',
                'dealer_discount' => null, 'sub_dealer_discount' => null, 'image' => '/tmp/wide.jpg', 'image_width' => 130, 'image_height' => 65,
                'usage' => null, 'efficacy' => null]],
        ])->render();

        // CR-00: drawn about 34 mm wide (it was 52 px, about 14 mm), and a wide box stays wide.
        $this->assertStringContainsString('src="/tmp/wide.jpg" alt="" width="130" height="65"', $html);
        $this->assertSame(400, StockPrintPdf::ThumbnailPixels);
        $this->assertGreaterThanOrEqual(2 * 52, StockPrintPdf::DisplayPixels);
    }

    public function test_a_list_with_photos_stays_small_enough_to_send_by_mail(): void
    {
        Storage::fake('local');
        $photo = $this->noisyPhoto();
        foreach (range(1, 5) as $n) {
            Storage::disk('local')->put("product-media/images/photo-{$n}.jpg", $photo);
            $this->stockOf($this->product(['name' => "Photo Product {$n}", 'image_path' => "product-media/images/photo-{$n}.jpg"]), $this->hub, 3, null);
        }

        $response = app(StockPrintPdf::class)->download(User::factory()->staff()->create());

        // Five busy 1000 px photos: well under 100 KB each in the PDF, so 95 products stay a few megabytes.
        $this->assertLessThan(5 * 100_000 + 150_000, filesize($response->getFile()->getPathname()));
    }

    public function test_a_photo_that_cannot_be_read_is_left_out_and_the_pdf_still_prints(): void
    {
        Storage::fake('local');
        Storage::disk('local')->put('product-media/images/broken.jpg', 'this is not a picture');
        $product = $this->product(['name' => 'Broken Photo Syrup', 'image_path' => 'product-media/images/broken.jpg']);
        $this->stockOf($product, $this->hub, 1, null);
        $owner = User::factory()->staff()->create();

        $response = app(StockPrintPdf::class)->download($owner);

        $this->assertStringStartsWith('%PDF', (string) file_get_contents($response->getFile()->getPathname()));
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

    /** A busy 1000 x 1000 picture that does not compress well, like a real photo of a bottle. */
    private function noisyPhoto(): string
    {
        mt_srand(7);
        $image = imagecreatetruecolor(1000, 1000);
        imagefilledrectangle($image, 0, 0, 1000, 1000, (int) imagecolorallocate($image, 245, 245, 240));
        for ($n = 0; $n < 260; $n++) {
            imagefilledellipse($image, mt_rand(0, 1000), mt_rand(0, 1000), mt_rand(20, 300), mt_rand(20, 300), (int) imagecolorallocate($image, mt_rand(0, 255), mt_rand(0, 255), mt_rand(0, 255)));
        }
        for ($n = 0; $n < 60000; $n++) {
            imagesetpixel($image, mt_rand(0, 999), mt_rand(0, 999), (int) imagecolorallocate($image, mt_rand(0, 255), mt_rand(0, 255), mt_rand(0, 255)));
        }
        ob_start();
        imagejpeg($image, null, 85);

        return (string) ob_get_clean();
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
