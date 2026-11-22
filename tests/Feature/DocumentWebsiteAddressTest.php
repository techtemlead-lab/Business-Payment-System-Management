<?php

namespace Tests\Feature;

use App\Domain\Accounts\PartnerRegistrationForm;
use App\Domain\Inventory\ProductListPdf;
use App\Domain\Orders\OrderInvoice;
use App\Models\Order;
use App\Models\Product;
use App\Models\User;
use App\Support\CompanyWebsite;
use App\Support\PdfLetterhead;
use App\Support\SiteCustomization;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

/**
 * The company's website prints as https://www.madinanhc.com in every document header, whatever form the server
 * address or a saved setting has,.
 */
class DocumentWebsiteAddressTest extends TestCase
{
    use RefreshDatabase;

    private const Printed = 'https://www.madinanhc.com';

    protected function setUp(): void
    {
        parent::setUp();
        $this->withoutVite();
    }

    /** @return array<string, array{0: string, 1: string}> */
    public static function spellings(): array
    {
        return [
            'bare domain' => ['madinanhc.com', self::Printed],
            'with www' => ['www.madinanhc.com', self::Printed],
            'http' => ['http://madinanhc.com', self::Printed],
            'https' => ['https://madinanhc.com', self::Printed],
            'trailing slash' => ['https://madinanhc.com/', self::Printed],
            'capitals and spaces' => [' HTTPS://WWW.Madinanhc.com/ ', self::Printed],
            'already right' => [self::Printed, self::Printed],
            'another domain stays' => ['madina.example.com', 'madina.example.com'],
            'a look-alike domain stays' => ['https://notmadinanhc.com', 'https://notmadinanhc.com'],
            'a page address stays' => ['https://madinanhc.com/about', 'https://madinanhc.com/about'],
            'an email address stays' => ['info@madinanhc.com', 'info@madinanhc.com'],
            'a sentence stays' => ['Visit madinanhc.com today', 'Visit madinanhc.com today'],
            'empty stays' => ['', ''],
        ];
    }

    #[DataProvider('spellings')]
    public function test_the_company_domain_always_takes_the_printed_form_and_other_text_is_kept(string $given, string $expected): void
    {
        $this->assertSame($expected, CompanyWebsite::normalise($given));
    }

    private function useCompanyDomain(string $appUrl): void
    {
        config(['madina.app_domain' => (string) parse_url($appUrl, PHP_URL_HOST), 'app.url' => $appUrl]);
        SiteCustomization::forgetCache();
    }

    /** @return array<string, array{0: string}> */
    public static function serverAddresses(): array
    {
        return ['bare' => ['https://madinanhc.com'], 'with www' => ['https://www.madinanhc.com']];
    }

    #[DataProvider('serverAddresses')]
    public function test_the_invoice_stock_print_and_product_list_letterheads_print_the_full_address_as_text_and_link(string $appUrl): void
    {
        $this->useCompanyDomain($appUrl);
        $dealer = User::factory()->dealer()->create();

        $invoice = (string) app(OrderInvoice::class)->printHtml($dealer, $this->orderFor($dealer))->getContent();
        $stock = view('invoices.stock-print', [
            'company' => PdfLetterhead::company(),
            'printedAt' => '22/11/2026',
            'stock' => ['rows' => [], 'shows_cost' => false, 'all_branches' => false, 'branch_name' => 'Head office', 'filtered' => false, 'truncated' => false],
        ])->render();
        $products = view('invoices.product-list', ['company' => app(ProductListPdf::class)->company(), 'rows' => []])->render();

        foreach (['invoice' => $invoice, 'stock print' => $stock, 'product list' => $products] as $name => $html) {
            $this->assertStringContainsString('>'.self::Printed.'</a>', $html, $name.' shows the full address.');
            $this->assertStringContainsString('href="'.self::Printed.'"', $html, $name.' links to the full address.');
            $this->assertStringNotContainsString('>madinanhc.com<', $html, $name.' must not show the bare domain.');
            $this->assertStringNotContainsString('https://madinanhc.com', $html, $name.' must not carry the address without www.');
        }
    }

    public function test_the_registration_form_header_prints_the_full_address(): void
    {
        $this->useCompanyDomain('https://madinanhc.com');
        $partner = User::factory()->dealer()->create();

        $html = (new \ReflectionMethod(PartnerRegistrationForm::class, 'html'))->invoke(app(PartnerRegistrationForm::class), $partner, 'en', false);

        $this->assertStringContainsString('>'.self::Printed.'</div>', $html);
        $this->assertStringNotContainsString('>madinanhc.com<', $html);
    }

    public function test_a_copy_that_is_not_on_the_company_domain_prints_its_own_address(): void
    {
        $this->useCompanyDomain('https://madina.example.com');

        $html = view('invoices.product-list', ['company' => app(ProductListPdf::class)->company(), 'rows' => []])->render();

        $this->assertStringContainsString('>madina.example.com</a>', $html);
        $this->assertStringNotContainsString('madinanhc.com', $html);
    }

    private function orderFor(User $partner): Order
    {
        $order = Order::create([
            'user_id' => $partner->id,
            'number' => 'INV-WEB-'.Str::random(6),
            'status' => 'approved',
            'total' => '810.00',
            'paid' => '810.00',
            'idempotency_key' => (string) Str::uuid(),
            'payload_hash' => hash('sha256', Str::uuid()),
        ]);
        $product = Product::factory()->create(['mrp' => '450.00', 'dealer_price' => '270.00']);
        $order->lines()->create(['product_id' => $product->id, 'name' => $product->name, 'sku' => $product->sku, 'quantity' => 3, 'mrp' => $product->mrp, 'unit_price' => '270.00', 'total' => '810.00']);

        return $order;
    }
}
