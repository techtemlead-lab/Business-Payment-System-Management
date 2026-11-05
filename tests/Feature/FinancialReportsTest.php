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
        // Totals are numeric cells so Excel can add them up; 1250.50 - 250.00 = 1000.50 due.
        $this->assertStringContainsString('<v>1000.5</v>', $xml);
        $this->assertStringContainsString('Dealer', $xml);
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
        $this->assertStringContainsString(e(__('portal.ops_export_note_refunds')), $zip->getFromName('xl/worksheets/sheet2.xml'));
        $zip->close();
        unlink($file);
        $this->assertDatabaseCount('journal_entries', 0);
    }

    public function test_district_sales_summary_groups_confirmed_orders_by_area(): void
    {
        $rangpur = User::factory()->create(['division' => 'Rangpur', 'district' => 'Rangpur']);
        $dhaka = User::factory()->create(['division' => 'Dhaka', 'district' => 'Gazipur']);
        $this->order($rangpur, 'R-1')->update(['status' => 'approved']);
        $this->order($rangpur, 'R-2')->update(['status' => 'delivered']);
        $this->order($dhaka, 'D-1')->update(['status' => 'approved']);
        $this->order($dhaka, 'D-PENDING');

        $rows = app(ReportData::class)->query(User::factory()->create(['role' => 'finance']), 'district-summary', '2026-10-17', '2026-10-17')['query']->get();

        $this->assertCount(2, $rows);
        $this->assertSame('Rangpur', $rows[0]->district);
        $this->assertSame(2, (int) $rows[0]->order_count);
        $this->assertSame(1, (int) $rows[0]->partner_count);
        $this->assertSame('2501.00', number_format((float) $rows[0]->gross_sales, 2, '.', ''));
        $this->assertSame('Gazipur', $rows[1]->district);
        $this->assertSame(1, (int) $rows[1]->order_count);
    }

    public function test_dues_aging_splits_each_partners_unpaid_balance_by_order_age(): void
    {
        $dealer = User::factory()->create(['name' => 'Aging Dealer']);
        $paidUp = User::factory()->create(['name' => 'Paid Up Dealer']);
        foreach (['A-10' => 10, 'A-45' => 45, 'A-75' => 75, 'A-120' => 120] as $number => $daysAgo) {
            $order = $this->order($dealer, $number);
            $order->forceFill(['status' => 'approved', 'created_at' => now()->subDays($daysAgo)])->save();
        }
        $this->order($dealer, 'A-PENDING')->forceFill(['created_at' => now()->subDays(5)])->save();
        $settled = $this->order($paidUp, 'P-1');
        $settled->forceFill(['status' => 'delivered', 'paid' => '1250.50'])->save();

        $report = app(ReportData::class)->query(User::factory()->create(['role' => 'finance']), 'dues-aging', '2026-01-01', '2026-10-17');
        $rows = $report['query']->get();

        $this->assertSame(['dealer_code', 'name', 'phone', 'district', 'order_count', 'outstanding', 'due_0_30', 'due_31_60', 'due_61_90', 'due_over_90', 'days_outstanding'], $report['columns']);
        $this->assertCount(1, $rows);
        $row = $rows[0];
        $this->assertSame('Aging Dealer', $row->name);
        $this->assertSame(4, (int) $row->order_count);
        foreach (['outstanding' => '4002.00', 'due_0_30' => '1000.50', 'due_31_60' => '1000.50', 'due_61_90' => '1000.50', 'due_over_90' => '1000.50'] as $column => $expected) {
            $this->assertSame($expected, number_format((float) $row->{$column}, 2, '.', ''), $column);
        }
        $this->assertSame(120, (int) $row->days_outstanding);
    }

    public function test_dues_export_lists_division_and_days_due(): void
    {
        $dealer = User::factory()->create(['division' => 'Rangpur', 'district' => 'Rangpur']);
        $order = $this->order($dealer, 'DUE-OLD');
        $order->forceFill(['status' => 'approved', 'created_at' => now()->subDays(40)])->save();

        $report = app(ReportData::class)->query(User::factory()->create(['role' => 'finance']), 'dues', '2026-09-01', '2026-10-17');
        $row = $report['query']->first();

        $this->assertContains('division', $report['columns']);
        $this->assertContains('days_outstanding', $report['columns']);
        $this->assertNotContains('days_outstanding', app(ReportData::class)->query(User::factory()->create(['role' => 'finance']), 'orders', '2026-09-01', '2026-10-17')['columns']);
        $this->assertSame('Rangpur', $row->division);
        $this->assertSame(40, (int) $row->days_outstanding);
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
