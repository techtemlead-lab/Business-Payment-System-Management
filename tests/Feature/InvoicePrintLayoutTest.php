<?php

namespace Tests\Feature;

use App\Domain\Orders\OrderInvoice;
use App\Models\Order;
use App\Models\Product;
use App\Models\User;
use App\Support\SiteCustomization;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Tests\TestCase;

/**
 * The invoice in the browser's print preview: the company's TM and Trade Licence numbers top right, a compact partner
 * box with the "Referred by" line under it, and no browser header or footer lines on any page.
 */
class InvoicePrintLayoutTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->withoutVite();
    }

    private function orderFor(User $partner): Order
    {
        $order = Order::create([
            'user_id' => $partner->id,
            'number' => 'MNHC-20261122-'.random_int(100001, 999999),
            'status' => 'approved',
            'total' => '810.00',
            'paid' => '0.00',
            'idempotency_key' => (string) Str::uuid(),
            'payload_hash' => hash('sha256', Str::uuid()),
        ]);
        $product = Product::factory()->create(['mrp' => '450.00', 'dealer_price' => '270.00']);
        $order->lines()->create(['product_id' => $product->id, 'name' => $product->name, 'sku' => $product->sku, 'quantity' => 3, 'mrp' => $product->mrp, 'unit_price' => '270.00', 'total' => '810.00']);

        return $order;
    }

    private function printView(User $partner): string
    {
        return (string) app(OrderInvoice::class)->printHtml($partner, $this->orderFor($partner))->getContent();
    }

    /** @return array<string, mixed> */
    private function payloadWith(User $partner, string $licence, string $trademark, bool $printable): array
    {
        $payload = app(OrderInvoice::class)->payload($this->orderFor($partner), 'none');
        $payload['company']['licence'] = $licence;
        $payload['company']['trademark'] = $trademark;
        $payload['printable'] = $printable;

        return $payload;
    }

    public function test_the_print_view_shows_the_tm_and_trade_licence_lines_top_right(): void
    {
        SiteCustomization::forgetCache();
        $html = $this->printView(User::factory()->dealer()->create());

        $this->assertStringContainsString('TM No.: <b>BD-H-1-335903</b>', $html);
        $this->assertStringContainsString('Trade Licence: <b>2026-00563</b>', $html);
        $this->assertLessThan(strpos($html, 'dealer-box-compact'), strpos($html, 'legal-trademark'), 'The numbers sit above the partner box, in the top right slot.');
    }

    public function test_a_line_is_left_out_when_its_number_is_empty(): void
    {
        $partner = User::factory()->dealer()->create();

        $none = view('invoices.order-letter', $this->payloadWith($partner, '', '', true))->render();
        $this->assertStringNotContainsString('legal-trademark', $none);
        $this->assertStringNotContainsString('legal-licence', $none);
        $this->assertStringNotContainsString('TM No.', $none);
        $this->assertStringNotContainsString('Trade Licence:', $none);

        $licenceOnly = view('invoices.order-letter', $this->payloadWith($partner, 'L-77', '', true))->render();
        $this->assertStringContainsString('Trade Licence: <b>L-77</b>', $licenceOnly);
        $this->assertStringNotContainsString('TM No.', $licenceOnly);
    }

    public function test_the_page_margin_is_zero_in_the_print_view_so_the_browser_prints_no_header_or_footer(): void
    {
        $html = $this->printView(User::factory()->dealer()->create());

        $this->assertMatchesRegularExpression('/@page\s*\{\s*size:\s*A4;\s*margin:\s*0;\s*\}/', $html);
        $this->assertStringContainsString('page-frame', $html, 'Every page gets its top and bottom space from the repeating spacer rows.');
        $this->assertStringContainsString('sheet-print', $html);
        $this->assertStringContainsString('display: table-header-group', $html, 'The item table header repeats on every page.');
        $this->assertStringContainsString('break-inside: avoid', $html, 'The totals and payment block is not split across pages.');
    }

    public function test_the_pdf_keeps_its_own_page_margins(): void
    {
        $html = view('invoices.order-letter', $this->payloadWith(User::factory()->dealer()->create(), '2026-00563', 'BD-H-1-335903', false))->render();

        $this->assertStringContainsString('margin: 10mm 12mm 12mm 12mm', $html);
        $this->assertDoesNotMatchRegularExpression('/@page\s*\{\s*size:\s*A4;\s*margin:\s*0;/', $html);
        $this->assertStringNotContainsString('class="page-frame"', $html);
        $this->assertStringNotContainsString('sheet sheet-print', $html);
    }

    public function test_referred_by_comes_after_the_compact_partner_box(): void
    {
        $sponsor = User::factory()->dealer()->create(['dealer_code' => 'SPN-1']);
        $partner = User::factory()->subDealer()->create(['sponsor_id' => $sponsor->id]);
        $withSponsor = $this->printView($partner);

        $box = strpos($withSponsor, 'dealer-box-compact');
        $this->assertNotFalse($box);
        $referred = strpos($withSponsor, 'Referred by');
        $this->assertNotFalse($referred);
        $this->assertGreaterThan($box, $referred, 'Referred by is printed below the partner box, not above it.');
        $this->assertStringContainsString('SPN-1', $withSponsor);
        $this->assertGreaterThan(strpos($withSponsor, 'Invoice No'), $referred);

        $direct = $this->printView(User::factory()->dealer()->create());
        $this->assertStringContainsString('Referred by', $direct);
        $this->assertGreaterThan(strpos($direct, 'dealer-box-compact'), strpos($direct, 'Referred by'));
    }

    public function test_the_partner_box_is_compact_with_two_fields_per_row_and_keeps_every_field(): void
    {
        $html = $this->printView(User::factory()->dealer()->create(['name' => 'Rahim Traders', 'dealer_code' => 'MNHC-D-9', 'phone' => '01711000000']));

        $start = strpos($html, 'dealer-box-compact');
        $box = substr($html, $start, (int) strpos($html, '</table>', (int) strpos($html, '</table>', $start) + 1) - $start);
        foreach (['Name :', 'Dealer Code :', 'Area :', 'Mobile No :', 'Booking Address :', 'Rahim Traders', 'MNHC-D-9', '01711000000'] as $needle) {
            $this->assertStringContainsString($needle, $box);
        }
        // Name with Dealer Code, and Area with Mobile No, share a row.
        $this->assertMatchesRegularExpression('/Name :(?:(?!<\/tr>).)*Dealer Code :/s', $box);
        $this->assertMatchesRegularExpression('/Area :(?:(?!<\/tr>).)*Mobile No :/s', $box);
        $this->assertStringNotContainsString('font-size:14px;font-weight:600', $box, 'The old large field rows are gone.');
    }

    public function test_the_order_number_and_the_website_stay_on_the_print_view(): void
    {
        config(['madina.app_domain' => 'madinanhc.com', 'app.url' => 'https://madinanhc.com']);
        SiteCustomization::forgetCache();
        $html = $this->printView(User::factory()->dealer()->create());

        $this->assertMatchesRegularExpression('/MNHC-20261122-\d{6}/', $html);
        $this->assertStringContainsString('https://www.madinanhc.com', $html);
    }
}
