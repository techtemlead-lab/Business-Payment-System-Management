<?php

namespace Tests\Feature;

use App\Models\Order;
use App\Models\Product;
use App\Models\User;
use App\Support\PdfFonts;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Mpdf\Mpdf;
use Mpdf\Output\Destination;
use Tests\TestCase;

class OrderInvoicePdfTest extends TestCase
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
            'number' => 'INV-PDF-'.Str::random(6),
            'status' => 'approved',
            'total' => '810.00',
            'paid' => '810.00',
            'idempotency_key' => (string) Str::uuid(),
            'payload_hash' => hash('sha256', Str::uuid()),
        ]);
        $product = Product::factory()->create(['mrp' => '450.00', 'dealer_price' => '270.00']);
        $order->lines()->create([
            'product_id' => $product->id,
            'name' => $product->name,
            'sku' => $product->sku,
            'quantity' => 3,
            'mrp' => $product->mrp,
            'unit_price' => '270.00',
            'total' => '810.00',
        ]);

        return $order;
    }

    private function invoicePdf(User $viewer, Order $order, string $portal): string
    {
        $response = $this->actingAs($viewer)->onPortal($portal)->get('/orders/'.$order->id.'/invoice')->assertOk();

        return (string) file_get_contents($response->baseResponse->getFile()->getPathname());
    }

    private function pageCount(string $pdf): int
    {
        return preg_match_all('#/Type /Page\b(?!s)#', $pdf);
    }

    private function boldTextPdf(string $text): string
    {
        $pdf = new Mpdf(['tempDir' => sys_get_temp_dir(), ...PdfFonts::options()]);
        $pdf->WriteHTML('<p><b>'.$text.'</b></p>');

        return $pdf->Output('', Destination::STRING_RETURN);
    }

    public function test_a_partner_invoice_is_one_readable_page(): void
    {
        $dealer = User::factory()->create(['role' => 'dealer', 'status' => 'active']);

        $this->assertSame(1, $this->pageCount($this->invoicePdf($dealer, $this->orderFor($dealer), 'dealer')));
    }

    public function test_the_partner_copy_mark_is_see_through_so_figures_stay_readable(): void
    {
        $dealer = User::factory()->create(['role' => 'dealer', 'status' => 'active']);

        $this->assertStringContainsString('/ca 0.08', $this->invoicePdf($dealer, $this->orderFor($dealer), 'dealer'));
    }

    public function test_the_office_copy_has_no_mark(): void
    {
        $dealer = User::factory()->create(['role' => 'dealer', 'status' => 'active']);
        $admin = User::factory()->create(['role' => 'super_admin', 'status' => 'active']);

        $this->assertStringNotContainsString('/ca 0.08', $this->invoicePdf($admin, $this->orderFor($dealer), 'sadmin'));
    }

    public function test_bold_bangla_does_not_use_the_bold_font_that_has_no_bangla_letters(): void
    {
        $pdf = $this->boldTextPdf('অর্ডারের মোট');

        $this->assertStringContainsString('FreeSerif', $pdf);
        $this->assertStringNotContainsString('FreeSerifBold', $pdf);
    }

    public function test_bold_english_keeps_the_real_bold_font(): void
    {
        $this->assertStringContainsString('FreeSerifBold', $this->boldTextPdf('Order total'));
    }
}
