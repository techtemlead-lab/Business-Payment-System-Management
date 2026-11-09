<?php

namespace Tests\Feature;

use App\Models\Order;
use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\TestCase;
use ZipArchive;

/**
 * Owner's decision: a report total counts only money that was actually paid or received.
 * Rows that are not paid stay listed, and the total says what it counts.
 */
class ReportTotalsCountPaidMoneyTest extends TestCase
{
    use RefreshDatabase;

    private const Day = '2026-10-17';

    private User $finance;

    private User $partner;

    protected function setUp(): void
    {
        parent::setUp();
        $this->travelTo(CarbonImmutable::parse(self::Day.'T06:00:00Z'));
        $this->finance = User::factory()->create(['role' => 'finance']);
        $this->partner = User::factory()->create(['role' => 'dealer']);
    }

    /** @return array{0: string, 1: string} The rows sheet and the summary sheet of a downloaded workbook. */
    private function workbook(string $type): array
    {
        $response = $this->actingAs($this->finance)->get('/commands/operations/reports?type='.$type.'&format=xlsx&from='.self::Day.'&to='.self::Day)->assertOk();
        $file = $response->baseResponse->getFile()->getPathname();
        $zip = new ZipArchive;
        $this->assertTrue($zip->open($file));
        $sheets = [(string) $zip->getFromName('xl/worksheets/sheet1.xml'), (string) $zip->getFromName('xl/worksheets/sheet2.xml')];
        $zip->close();
        unlink($file);

        return $sheets;
    }

    private function order(string $number, string $total, string $paid, string $status = 'approved'): Order
    {
        return Order::create(['user_id' => $this->partner->id, 'number' => $number, 'total' => $total, 'paid' => $paid, 'status' => $status,
            'idempotency_key' => (string) Str::uuid(), 'payload_hash' => hash('sha256', $number)]);
    }

    public function test_rewards_total_counts_only_credited_or_paid_and_says_so(): void
    {
        foreach (['requested' => '9999.00', 'rejected' => '500.00', 'credited' => '20.00', 'paid' => '30.00'] as $status => $amount) {
            DB::table('reserve_disbursements')->insert(['program' => 'additional_rank', 'user_id' => $this->partner->id, 'amount' => $amount, 'status' => $status,
                'request_key' => (string) Str::uuid(), 'reason' => 'Test reward row', 'created_by' => $this->finance->id, 'created_at' => now(), 'updated_at' => now()]);
        }

        [$rows, $summary] = $this->workbook('rewards');

        $this->assertStringContainsString('<v>9999</v>', $rows, 'Rows that are not paid stay listed.');
        $this->assertStringContainsString('<v>50</v>', $rows);
        $this->assertStringNotContainsString('<v>10549</v>', $rows);
        $this->assertStringContainsString(e(__('portal.ops_total_note_rewards')), $rows);
        $this->assertStringContainsString(e(__('portal.ops_total_note_rewards')), $summary);
    }

    public function test_collections_total_counts_only_received_payments(): void
    {
        $order = $this->order('COLLECT-1', '1000.00', '0.00');
        foreach (['verified' => ['400.00', '500.00'], 'pending' => ['7000.00'], 'rejected' => ['800.00']] as $status => $amounts) {
            foreach ($amounts as $amount) {
                DB::table('payment_claims')->insert(['order_id' => $order->id, 'channel' => 'bKash', 'reference' => Str::random(10), 'amount' => $amount,
                    'status' => $status, 'created_at' => now(), 'updated_at' => now()]);
            }
        }

        [$rows] = $this->workbook('collections');

        $this->assertStringContainsString('<v>900</v>', $rows);
        $this->assertStringNotContainsString('<v>8700</v>', $rows);
    }

    public function test_withdrawals_total_counts_only_paid_rows(): void
    {
        foreach (['requested' => '3000.00', 'approved' => '2000.00', 'rejected' => '1000.00', 'paid' => '150.00'] as $status => $amount) {
            $this->withdrawal($status, $amount);
        }
        $this->withdrawal('paid', '250.00');

        [$rows] = $this->workbook('withdrawals');

        $this->assertStringContainsString('<v>400</v>', $rows);
        $this->assertStringNotContainsString('<v>6400</v>', $rows);
    }

    private function withdrawal(string $status, string $amount): void
    {
        DB::table('withdrawals')->insert(['id' => (string) Str::uuid(), 'user_id' => $this->partner->id, 'amount' => $amount, 'status' => $status, 'channel' => 'bKash',
            'destination' => 'masked', 'idempotency_key' => (string) Str::uuid(), 'withheld_amount' => '0.00', 'net_amount' => $amount, 'created_at' => now(), 'updated_at' => now()]);
    }

    public function test_payroll_total_counts_only_paid_rows(): void
    {
        $contract = DB::table('staff_contracts')->insertGetId(['staff_id' => $this->finance->id, 'effective_month' => '2026-07', 'base_salary' => '100.00', 'reason' => 'Test contract',
            'created_by' => $this->finance->id, 'created_at' => now(), 'updated_at' => now()]);
        foreach ([['2026-07', 'draft', '9000.00'], ['2026-08', 'paid', '700.00'], ['2026-09', 'paid', '100.00']] as [$month, $status, $net]) {
            DB::table('payroll_entries')->insert(['id' => (string) Str::uuid(), 'staff_id' => $this->finance->id, 'contract_id' => $contract, 'period_month' => $month, 'snapshot' => '{}',
                'gross' => $net, 'deduction' => '0.00', 'net' => $net, 'status' => $status, 'prepared_by' => $this->finance->id, 'created_at' => now(), 'updated_at' => now()]);
        }

        [$rows] = $this->workbook('payroll');

        $this->assertStringContainsString('<v>800</v>', $rows);
        $this->assertStringNotContainsString('<v>9800</v>', $rows);
    }

    public function test_commission_total_counts_only_money_already_in_a_wallet(): void
    {
        $order = $this->order('COMM-1', '10000.00', '10000.00', 'delivered');
        $rows = [['available', '110.00'], ['available', '95.00'], ['maturing', '5000.00'], ['held', '4000.00'], ['pending', '3000.00'], ['reversed', '0.00']];
        foreach ($rows as [$status, $amount]) {
            DB::table('commission_allocations')->insert(['order_id' => $order->id, 'user_id' => $status === 'pending' ? null : $this->partner->id,
                'category' => 'direct_referral', 'rate_percentage' => '5.00', 'cash_basis' => '10000.00', 'amount' => $amount, 'original_amount' => $amount, 'status' => $status,
                'metadata' => '{}', 'created_at' => now(), 'updated_at' => now()]);
        }

        [$sheet] = $this->workbook('commissions');

        $this->assertStringContainsString('<v>205</v>', $sheet);
        $this->assertStringNotContainsString('<v>12205</v>', $sheet);
        $this->assertStringNotContainsString('<v>60000</v>', $sheet, 'The order value behind each share is not money paid.');
    }

    public function test_orders_total_counts_delivered_goods_only(): void
    {
        $this->order('KEPT', '1000.00', '1000.00', 'delivered');
        $this->order('UNPAID', '5000.00', '0.00', 'submitted');
        $partRefund = $this->order('PART-REFUND', '700.00', '700.00', 'cancelled');
        DB::table('order_refunds')->insert(['id' => (string) Str::uuid(), 'order_id' => $partRefund->id, 'user_id' => $this->partner->id, 'amount' => '500.00',
            'paid_before' => '700.00', 'reason' => 'Part refund', 'refunded_by' => $this->finance->id, 'created_at' => now(), 'updated_at' => now()]);

        [$rows, $summary] = $this->workbook('orders');

        $this->assertStringContainsString('<v>1000</v>', $rows, 'Only the delivered order counts.');
        $this->assertStringNotContainsString('<v>1200</v>', $rows, 'Money on a cancelled order is never counted.');
        $this->assertStringNotContainsString('<v>6700</v>', $rows, 'Order value of undelivered orders is not counted.');
        $this->assertStringContainsString(e(__('portal.ops_total_note_orders')), $summary);
    }

    public function test_every_report_with_a_total_says_what_it_counts_in_both_languages(): void
    {
        $reports = ['orders', 'products', 'product_summary', 'category_summary', 'district_summary', 'commissions', 'settlements', 'payroll', 'withdrawals', 'ledger',
            'rewards', 'rank_incentives', 'collections', 'tds_register', 'fund_spending', 'order_refunds', 'refunds'];
        foreach ($reports as $report) {
            foreach (['en', 'bn'] as $locale) {
                $this->assertNotSame('portal.ops_total_note_'.$report, __('portal.ops_total_note_'.$report, [], $locale));
            }
        }
    }

    public function test_pdf_of_a_report_with_unpaid_rows_still_downloads(): void
    {
        DB::table('reserve_disbursements')->insert(['program' => 'additional_rank', 'user_id' => $this->partner->id, 'amount' => '9999.00', 'status' => 'requested',
            'request_key' => (string) Str::uuid(), 'reason' => 'Test reward row', 'created_by' => $this->finance->id, 'created_at' => now(), 'updated_at' => now()]);

        $response = $this->actingAs($this->finance)->get('/commands/operations/reports?type=rewards&format=pdf&from='.self::Day.'&to='.self::Day)->assertOk();
        $file = $response->baseResponse->getFile()->getPathname();

        $this->assertStringStartsWith('%PDF-', (string) file_get_contents($file));
        unlink($file);
    }

    public function test_report_downloads_allow_fifteen_a_minute(): void
    {
        $url = '/commands/operations/reports?type=ledger&format=xlsx&from='.self::Day.'&to='.self::Day;
        $this->actingAs($this->finance);
        for ($attempt = 1; $attempt <= 15; $attempt++) {
            $response = $this->get($url)->assertOk();
            unlink($response->baseResponse->getFile()->getPathname());
        }

        $this->get($url)->assertStatus(429);
    }
}
