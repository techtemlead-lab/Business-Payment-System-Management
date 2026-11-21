<?php

namespace Tests\Feature;

use App\Domain\Inventory\BranchScope;
use App\Domain\Inventory\ProductListPdf;
use App\Domain\Inventory\StockPrintPdf;
use App\Domain\Orders\OrderInvoice;
use App\Models\Order;
use App\Models\Product;
use App\Models\User;
use App\Support\PdfPaper;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Request;
use Illuminate\Support\Str;
use Tests\TestCase;

/**
 * Every PDF the app makes is A4 unless it is on the list of deliberate exceptions.
 */
class PdfPaperTest extends TestCase
{
    use RefreshDatabase;

    private const A4Width = 595.28;

    private const A4Height = 841.89;

    protected function setUp(): void
    {
        parent::setUp();
        $this->withoutVite();
    }

    public function test_every_pdf_maker_names_its_paper_and_only_the_listed_exceptions_use_another_size(): void
    {
        $found = [];
        foreach (new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator(base_path('app'), \FilesystemIterator::SKIP_DOTS)) as $file) {
            if ($file->getExtension() !== 'php') {
                continue;
            }
            $source = (string) file_get_contents($file->getPathname());
            if (! str_contains($source, 'new Mpdf(')) {
                continue;
            }
            $relative = str_replace('\\', '/', substr($file->getPathname(), strlen(base_path()) + 1));
            $found[] = $relative;
            if (isset(PdfPaper::Special[$relative])) {
                continue;
            }
            $this->assertStringContainsString("'format' => PdfPaper::Portrait", $source, $relative.' must print on portrait A4 or be listed in PdfPaper::Special.');
            $this->assertDoesNotMatchRegularExpression("/'format'\s*=>\s*'A/", $source, $relative.' must not write a paper size by hand.');
            $this->assertStringNotContainsString('PdfPaper::Landscape', $source, $relative.' is landscape, so it must be listed in PdfPaper::Special.');
        }
        $this->assertNotEmpty($found);
        foreach (array_keys(PdfPaper::Special) as $listed) {
            $this->assertContains($listed, $found, $listed.' is listed as an exception but no longer makes a PDF.');
        }
    }

    public function test_the_product_list_and_the_stock_print_are_a4_landscape(): void
    {
        $owner = User::factory()->staff()->create();
        Product::factory()->create(['name' => 'Paper Syrup', 'active' => true]);

        $productList = $this->pdf(app(ProductListPdf::class)->download($owner)->getFile()->getPathname());
        $stockPrint = $this->pdf(app(StockPrintPdf::class)->download($owner, Request::create('/exports/stock-print'), new BranchScope(true, []))->getFile()->getPathname());

        $this->assertSame([self::A4Height, self::A4Width], $this->mediaBox($productList));
        $this->assertSame([self::A4Height, self::A4Width], $this->mediaBox($stockPrint));
    }

    public function test_an_order_invoice_is_a4_portrait(): void
    {
        $dealer = User::factory()->create(['role' => 'dealer', 'status' => 'active']);
        $order = Order::create([
            'user_id' => $dealer->id,
            'number' => 'INV-PAPER-'.Str::random(6),
            'status' => 'approved',
            'total' => '810.00',
            'paid' => '810.00',
            'idempotency_key' => (string) Str::uuid(),
            'payload_hash' => hash('sha256', Str::uuid()),
        ]);
        $product = Product::factory()->create(['mrp' => '450.00', 'dealer_price' => '270.00']);
        $order->lines()->create(['product_id' => $product->id, 'name' => $product->name, 'sku' => $product->sku, 'quantity' => 3, 'mrp' => '450.00', 'unit_price' => '270.00', 'total' => '810.00']);

        $pdf = $this->pdf(app(OrderInvoice::class)->downloadPdf($dealer, $order)->getFile()->getPathname());

        $this->assertSame([self::A4Width, self::A4Height], $this->mediaBox($pdf));
    }

    private function pdf(string $path): string
    {
        return (string) file_get_contents($path);
    }

    /** @return array{0: float, 1: float} width and height of the first page, in points */
    private function mediaBox(string $pdf): array
    {
        $this->assertSame(1, preg_match('#/MediaBox\s*\[\s*0\s+0\s+([\d.]+)\s+([\d.]+)\s*\]#', $pdf, $match), 'The PDF has no page size.');

        return [round((float) $match[1], 2), round((float) $match[2], 2)];
    }
}
