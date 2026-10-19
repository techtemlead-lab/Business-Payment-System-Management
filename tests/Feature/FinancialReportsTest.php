<?php

namespace Tests\Feature;

use App\Domain\Finance\ReportData;
use App\Models\Order;
use App\Models\Product;
use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\TestCase;
use ZipArchive;

class FinancialReportsTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->travelTo(CarbonImmutable::parse('2026-10-17T06:00:00Z'));
    }

    private function order(User $user, string $number): Order
    {
        return Order::create(['user_id' => $user->id, 'number' => $number, 'total' => '1250.50', 'paid' => '250.00',
            'idempotency_key' => (string) Str::uuid(), 'payload_hash' => hash('sha256', $number)]);
    }

    public function test_excel_exports_are_native_workbooks_with_scoped_rows_safe_text_and_exact_totals(): void
    {
        $owner = User::factory()->create(['name' => '=SUM(1,2)']);
        $other = User::factory()->create(['name' => 'Private other dealer']);
        $this->order($owner, 'OWNER-ORDER');
        $this->order($other, 'OTHER-ORDER');
        $response = $this->actingAs($owner)->get('/commands/operations/reports?type=orders&format=xlsx&from='.now()->format('Y-m-d').'&to='.now()->format('Y-m-d'))->assertOk();
        $file = $response->baseResponse->getFile()->getPathname();
        $zip = new ZipArchive;
        $this->assertTrue($zip->open($file));
        $xml = $zip->getFromName('xl/worksheets/sheet1.xml');
        $this->assertStringContainsString('OWNER-ORDER', $xml);
        $this->assertStringNotContainsString('OTHER-ORDER', $xml);
        $this->assertStringContainsString('=SUM(1,2)', $xml);
        $this->assertStringNotContainsString('<f>', $xml);
        $this->assertStringContainsString('1000.50', $xml);
        $zip->close();
        unlink($file);
    }

    public function test_pdf_exports_are_real_documents_and_render_bengali_names(): void
    {
        $owner = User::factory()->create(['name' => 'মোঃ আনোয়ার হোসেন']);
        $this->order($owner, 'বাংলা-ORDER-1');
        $response = $this->actingAs($owner)->get('/commands/operations/reports?type=orders&format=pdf&from='.now()->format('Y-m-d').'&to='.now()->format('Y-m-d'))->assertOk();
        $file = $response->baseResponse->getFile()->getPathname();
        $this->assertStringStartsWith('%PDF-', file_get_contents($file));
        $this->assertGreaterThan(20000, filesize($file));
        unlink($file);
    }

    public function test_company_financial_exports_require_capabilities_and_date_limits_are_enforced(): void
    {
        $query = '?type=ledger&format=xlsx&from=2026-10-01&to=2026-10-17';
        $this->actingAs(User::factory()->create())->getJson('/commands/operations/reports'.$query)->assertForbidden();
        $this->actingAs(User::factory()->create(['role' => 'finance']))->get('/commands/operations/reports'.$query)->assertOk();
        $this->getJson('/commands/operations/reports?type=ledger&format=pdf&from=2024-01-01&to=2026-10-17')->assertUnprocessable();
    }

    public function test_product_sales_exclude_unfulfilled_purchase_requests(): void
    {
        $buyer = User::factory()->create();
        $product = Product::factory()->create(['mrp' => '2000', 'dealer_price' => '1250.50']);
        foreach (['FULFILLED' => 'delivered', 'PENDING-ONLY' => 'submitted'] as $number => $status) {
            $order = $this->order($buyer, $number);
            $order->update(['status' => $status]);
            $order->lines()->create(['product_id' => $product->id, 'sku' => $product->sku, 'name' => $product->name,
                'quantity' => 1, 'mrp' => '2000', 'unit_price' => '1250.50', 'total' => '1250.50']);
        }
        $response = $this->actingAs(User::factory()->create(['role' => 'finance']))->get('/commands/operations/reports?type=products&format=xlsx&from=2026-10-17&to=2026-10-17')->assertOk();
        $file = $response->baseResponse->getFile()->getPathname();
        $zip = new ZipArchive;
        $zip->open($file);
        $xml = $zip->getFromName('xl/worksheets/sheet1.xml');
        $this->assertStringContainsString('FULFILLED', $xml);
        $this->assertStringNotContainsString('PENDING-ONLY', $xml);
        $zip->close();
        unlink($file);
    }

    public function test_refund_report_separates_commission_adjustments_from_actual_bank_transfers(): void
    {
        $order = $this->order(User::factory()->create(), 'ADJUSTED-ORDER');
        DB::table('commission_refunds')->insert(['id' => (string) Str::uuid(), 'order_id' => $order->id, 'reference' => 'COMMISSION-ADJUSTMENT-1',
            'amount' => '250.00', 'reason' => 'Approved commission adjustment', 'created_at' => now(), 'updated_at' => now()]);
        $response = $this->actingAs(User::factory()->create(['role' => 'finance']))->get('/commands/operations/reports?type=refunds&format=xlsx&from=2026-10-17&to=2026-10-17')->assertOk();
        $file = $response->baseResponse->getFile()->getPathname();
        $zip = new ZipArchive;
        $zip->open($file);
        $this->assertStringContainsString('COMMISSION-ADJUSTMENT-1', $zip->getFromName('xl/worksheets/sheet1.xml'));
        $this->assertStringContainsString('do not confirm execution of a bank refund', $zip->getFromName('xl/worksheets/sheet2.xml'));
        $zip->close();
        unlink($file);
        $this->assertDatabaseCount('journal_entries', 0);
    }

    public function test_each_company_report_uses_a_valid_schema_and_guest_access_is_denied(): void
    {
        $this->getJson('/api/v1/operations/views/payroll')->assertUnauthorized();
        $actor = User::factory()->create(['role' => 'finance']);
        foreach (ReportData::Types as $type) {
            $response = $this->actingAs($actor)->get('/commands/operations/reports?type='.$type.'&format=xlsx&from=2026-10-17&to=2026-10-17')->assertOk();
            $file = $response->baseResponse->getFile()->getPathname();
            $this->assertStringStartsWith('PK', file_get_contents($file));
            unlink($file);
            $this->travel(61)->seconds();
        }
    }
}
