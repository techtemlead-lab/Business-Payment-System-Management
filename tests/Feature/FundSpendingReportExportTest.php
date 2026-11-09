<?php

namespace Tests\Feature;

use App\Domain\Finance\FundSpending;
use App\Models\Order;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\TestCase;
use ZipArchive;

/** The downloaded report must hold what the screen shows, through the last day of the period. */
class FundSpendingReportExportTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->withoutVite();
        $this->travelTo(now()->setDate(2026, 11, 9)->setTime(13, 0));
    }

    public function test_a_spend_on_the_last_day_of_the_period_is_in_the_download(): void
    {
        $finance = User::factory()->staff('finance')->create();
        $this->paidSpend('Landlord on the last day', '2026-11-09', $finance);
        $this->paidSpend('Landlord earlier', '2026-11-03', $finance);
        $this->paidSpend('Landlord after the period', '2026-11-10', $finance);
        $this->paidSpend('Landlord before the period', '2026-10-31', $finance);

        $sheet = $this->sheetXml($this->actingAs($finance)->get('/api/v1/operations/reports?type=fund-spending&format=xlsx&from=2026-11-01&to=2026-11-09')->assertOk());

        $this->assertStringContainsString('Landlord on the last day', $sheet);
        $this->assertStringContainsString('Landlord earlier', $sheet);
        $this->assertStringNotContainsString('Landlord after the period', $sheet);
        $this->assertStringNotContainsString('Landlord before the period', $sheet);
        $this->assertStringContainsString('<v>200</v>', $sheet, 'Two rows of 100 add up to the total line.');
    }

    public function test_the_heading_of_a_bangla_report_is_in_bangla(): void
    {
        $finance = User::factory()->staff('finance')->create(['locale' => 'bn']);
        Order::create(['user_id' => User::factory()->dealer()->create()->id, 'number' => 'QA-ORDER-1', 'total' => '100.00', 'paid' => '0.00',
            'idempotency_key' => (string) Str::uuid(), 'payload_hash' => hash('sha256', 'QA-ORDER-1')]);

        $response = $this->actingAs($finance)->get('/api/v1/operations/reports?type=orders&format=xlsx&from=2026-11-01&to=2026-11-09')->assertOk();

        $strings = $this->workbookStrings($response);
        $this->assertStringContainsString(__('portal.ops_report_orders', [], 'bn'), $strings);
        $this->assertStringNotContainsString('Orders', $strings);
    }

    public function test_the_period_of_a_bangla_report_reads_from_one_date_to_the_other(): void
    {
        $finance = User::factory()->staff('finance')->create(['locale' => 'bn']);

        $response = $this->actingAs($finance)->get('/api/v1/operations/reports?type=orders&format=xlsx&from=2026-11-01&to=2026-11-09')->assertOk();

        $this->assertStringContainsString('2026-11-01 থেকে 2026-11-09', $this->workbookStrings($response));
    }

    public function test_a_bangla_commission_report_names_each_share_in_bangla(): void
    {
        $finance = User::factory()->staff('finance')->create(['locale' => 'bn']);
        $dealer = User::factory()->dealer()->create();
        $order = Order::create(['user_id' => $dealer->id, 'number' => 'QA-ORDER-2', 'total' => '1000.00', 'paid' => '1000.00',
            'idempotency_key' => (string) Str::uuid(), 'payload_hash' => hash('sha256', 'QA-ORDER-2')]);
        DB::table('commission_allocations')->insert(['order_id' => $order->id, 'user_id' => $dealer->id, 'category' => 'area_union',
            'rate_percentage' => '2.00', 'cash_basis' => '1000.00', 'amount' => '20.00', 'status' => 'held', 'metadata' => '{}', 'created_at' => now(), 'updated_at' => now()]);

        $strings = $this->sheetXml($this->actingAs($finance)->get('/api/v1/operations/reports?type=commissions&format=xlsx&from=2026-11-01&to=2026-11-09')->assertOk());

        $this->assertStringContainsString(__('portal.report_cat_area_union', [], 'bn'), $strings);
        $this->assertStringNotContainsString('Area Union', $strings);
    }

    public function test_a_bangla_settlement_report_names_rewards_and_salary_in_bangla(): void
    {
        $finance = User::factory()->staff('finance')->create(['locale' => 'bn']);
        $dealer = User::factory()->dealer()->create();
        $order = Order::create(['user_id' => $dealer->id, 'number' => 'QA-ORDER-3', 'total' => '1000.00', 'paid' => '1000.00',
            'idempotency_key' => (string) Str::uuid(), 'payload_hash' => hash('sha256', 'QA-ORDER-3')]);
        foreach (['award_4', 'salary', 'rank_mo'] as $kind) {
            $allocationId = DB::table('commission_allocations')->insertGetId(['order_id' => $order->id, 'category' => 'rank_incentive_pool',
                'rate_percentage' => '2.00', 'cash_basis' => '1000.00', 'amount' => '10.00', 'status' => 'settled', 'metadata' => '{}', 'created_at' => now(), 'updated_at' => now()]);
            DB::table('commission_settlement_payouts')->insert(['allocation_id' => $allocationId, 'user_id' => $dealer->id, 'kind' => $kind, 'period_month' => '2026-11',
                'amount' => '10.00', 'created_at' => now(), 'updated_at' => now()]);
        }

        $strings = $this->sheetXml($this->actingAs($finance)->get('/api/v1/operations/reports?type=settlements&format=xlsx&from=2026-11-01&to=2026-11-09')->assertOk());

        $this->assertStringContainsString('পুরস্কার #4', $strings);
        $this->assertStringContainsString(__('portal.settle_kind_salary', [], 'bn'), $strings);
        $this->assertStringContainsString('র‍্যাঙ্ক পুরস্কার (MO)', $strings);
        $this->assertStringNotContainsString('Award 4', $strings);
    }

    private function paidSpend(string $payee, string $date, User $maker): void
    {
        DB::table('reserve_disbursements')->insert([
            'program' => FundSpending::Program, 'fund_key' => 'company_share', 'payee_name' => $payee, 'amount' => '100.00', 'status' => 'paid',
            'spent_on' => $date, 'paid_at' => now(), 'payment_method' => 'bank', 'payment_reference' => Str::upper(Str::random(10)),
            'request_key' => (string) Str::uuid(), 'reason' => 'Rent', 'created_by' => $maker->id, 'created_at' => now(), 'updated_at' => now(),
        ]);
    }

    private function sheetXml($response): string
    {
        return $this->workbookPart($response, 'xl/worksheets/sheet1.xml').$this->workbookPart($response, 'xl/sharedStrings.xml');
    }

    private function workbookStrings($response): string
    {
        return $this->workbookPart($response, 'xl/sharedStrings.xml').$this->workbookPart($response, 'xl/worksheets/sheet2.xml');
    }

    private function workbookPart($response, string $part): string
    {
        $zip = new ZipArchive;
        $this->assertTrue($zip->open($response->baseResponse->getFile()->getPathname()));
        $xml = (string) $zip->getFromName($part);
        $zip->close();

        return $xml;
    }
}
