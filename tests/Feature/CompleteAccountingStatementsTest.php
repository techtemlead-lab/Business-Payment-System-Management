<?php

namespace Tests\Feature;

use App\Domain\Clinic\ClinicDispensing;
use App\Domain\Finance\AccountingStatements;
use App\Domain\Inventory\PurchasingService;
use App\Domain\Inventory\StockService;
use App\Domain\Orders\OrderService;
use App\Models\CompanyBranch;
use App\Models\Prescription;
use App\Models\PrescriptionPatient;
use App\Models\Product;
use App\Models\Supplier;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\TestCase;

/**
 * The statements with the figures the journal does not hold. The seeded story below is worked
 * out by hand in the comments, so the expected numbers are not just the code's own output.
 */
class CompleteAccountingStatementsTest extends TestCase
{
    use RefreshDatabase;

    private User $staff;

    private CompanyBranch $branch;

    private Product $priced;

    private Product $unpriced;

    protected function setUp(): void
    {
        parent::setUp();
        $this->withoutVite();
        $this->staff = User::factory()->staff('finance')->create(['status' => 'active']);
        $this->branch = CompanyBranch::factory()->create();
        $this->priced = Product::factory()->create(['cost_price' => '40.00']);
        $this->unpriced = Product::factory()->create(['cost_price' => null]);
    }

    public function test_the_seeded_october_ties_out_by_hand(): void
    {
        $this->seedStory();

        $october = app(AccountingStatements::class)->build('2026-10-01', '2026-10-31');
        $pl = $october['profit_loss'];
        $sheet = $october['balance_sheet'];

        // Sales 900 + 150; clinic 700 shown whole. Opening-balance order 5000 is not a sale.
        $this->assertSame('1750.00', $pl['total_income']);
        $this->assertSame('1050.00', $this->amount($pl['income'], 'product_sales'));
        $this->assertSame('700.00', $this->amount($pl['income'], 'clinic_sales'));
        // Clinic income is the sale line only; the journal's 350 must not be added on top.
        $this->assertNull($this->amount($pl['income'], 'clinic_fund'));

        // Lots 300; estimate 1 x 40 (no lot record); manufacturer share 300.
        $this->assertSame('300.00', $this->amount($pl['cost_of_sales'], 'cost_of_goods'));
        $this->assertSame('40.00', $this->amount($pl['cost_of_sales'], 'cost_of_goods_estimate'));
        $this->assertSame('300.00', $this->amount($pl['cost_of_sales'], 'clinic_manufacturer_share'));
        $this->assertSame('640.00', $pl['total_cost_of_sales']);
        $this->assertSame('1110.00', $pl['gross_profit']);

        // Commission 100 + heads 120 + 30 + 50 + 70 + write-off 60. Payment to manufacturer and
        // owner withdrawal are not expenses, and the fund accounts are replaced by the heads.
        $this->assertSame('430.00', $pl['total_expenses']);
        $this->assertSame('120.00', $this->amount($pl['expenses'], 'head_'.$this->headId('Office rent')));
        $this->assertSame('70.00', $this->amount($pl['expenses'], 'head_'.$this->headId('Office supplies')));
        $this->assertNull($this->amount($pl['expenses'], 'company_fund_expense'));
        $this->assertNull($this->amount($pl['expenses'], 'company_marketing_expense'));
        $this->assertNull($this->amount($pl['expenses'], 'head_'.$this->headId("Owner's drawings")));
        $this->assertSame('60.00', $this->amount($pl['expenses'], 'stock_written_off'));
        $this->assertSame('680.00', $pl['result']);

        // Journal result -600 plus 1050 sales - 340 cost - 60 written off + 630 from heads.
        $this->assertSame('-600.00', $october['checks']['journal_result']);
        $this->assertSame('1280.00', $october['checks']['other_records_result']);
        $this->assertTrue($october['checks']['result_reconciled']);

        // Money in 1550; stock 890 + 400; in transit 80; journal assets -370.
        $this->assertSame('1550.00', $this->amount($sheet['assets'], 'order_money_received'));
        $this->assertSame('890.00', $this->amount($sheet['assets'], 'stock_at_cost'));
        $this->assertSame('400.00', $this->amount($sheet['assets'], 'stock_estimated'));
        $this->assertSame('80.00', $this->amount($sheet['assets'], 'goods_in_transit'));
        $this->assertSame('2550.00', $sheet['total_assets']);

        // Wallets 180; manufacturer fund 300 less 50 clinic goods cost; suppliers 1300 less 300
        // paid; orders paid and not delivered 300 + 200.
        $this->assertSame('250.00', $this->amount($sheet['liabilities'], 'manufacturer_fund'));
        $this->assertSame('1000.00', $this->amount($sheet['liabilities'], 'supplier_due'));
        $this->assertSame('500.00', $this->amount($sheet['liabilities'], 'paid_not_delivered'));
        $this->assertSame('1930.00', $sheet['total_liabilities']);

        $this->assertSame('440.00', $this->amount($sheet['equity'], 'opening_stock'));
        $this->assertSame('80.00', $this->amount($sheet['equity'], 'stock_without_receipt'));
        $this->assertSame('-500.00', $this->amount($sheet['equity'], 'owner_withdrawals'));
        $this->assertSame('-80.00', $sheet['earlier_result']);
        $this->assertSame('680.00', $sheet['period_result']);

        $this->assertSame('2550.00', $sheet['total_liabilities_and_equity']);
        $this->assertSame('0.00', $sheet['difference']);
        $this->assertTrue($october['checks']['statement_balances']);
        $this->assertTrue($october['checks']['ties_to_journal']);

        $this->assertSame('300.00', $october['purchases']['in_period']);
        $this->assertSame('1000.00', $october['purchases']['supplier_due']);
    }

    public function test_every_period_ties_out_and_a_sale_lands_when_it_is_delivered(): void
    {
        $this->seedStory();

        foreach ([['2026-09-01', '2026-09-30'], ['2026-10-01', '2026-10-31'], ['2026-10-05', '2026-10-05'], ['2026-11-01', '2026-11-30'], ['2026-09-01', '2026-12-31']] as [$from, $to]) {
            $period = app(AccountingStatements::class)->build($from, $to);
            $this->assertSame('0.00', $period['balance_sheet']['difference'], $from);
            $this->assertTrue($period['checks']['statement_balances'], $from);
            $this->assertTrue($period['checks']['result_reconciled'], $from);
        }

        $november = app(AccountingStatements::class)->build('2026-11-01', '2026-11-30');
        // The October parcel delivered in November: sold now, and no longer in transit.
        $this->assertSame('300.00', $this->amount($november['profit_loss']['income'], 'product_sales'));
        $this->assertSame('80.00', $this->amount($november['profit_loss']['cost_of_sales'], 'cost_of_goods_estimate'));
        $this->assertNull($this->amount($november['balance_sheet']['assets'], 'goods_in_transit'));
        // Earlier result now carries October's 680 on top of September's -80.
        $this->assertSame('600.00', $november['balance_sheet']['earlier_result']);
    }

    /**
     * The same figures from the app's real flows, not inserted rows: 10 units received at 55,
     * 2 added by hand at the product's 30, 3 sold and 1 dispensed from the received batch.
     */
    public function test_the_real_flows_from_goods_receipt_to_delivery_tie_out(): void
    {
        $this->travelTo(now()->setDate(2026, 11, 8)->setTime(12, 0));
        $day = '2026-11-08';
        $hub = CompanyBranch::query()->where('code', 'HO-PIRGANJ')->firstOrFail();
        $owner = User::factory()->staff('super_admin')->create(['active_branch_id' => $hub->id]);
        $product = Product::factory()->create(['stock' => 0, 'cost_price' => '30.00', 'mrp' => '200.00', 'batch_number' => null, 'expires_on' => null]);
        $purchasing = app(PurchasingService::class);
        $purchase = $purchasing->createOrder($owner, ['supplier_id' => Supplier::factory()->create(['created_by' => $owner->id])->id, 'branch_id' => $hub->id, 'lines' => [['product_id' => $product->id, 'quantity' => 10]]]);
        $purchasing->sendOrder($owner, $purchase);
        $purchasing->receive($owner, $purchase, ['reference' => (string) Str::uuid(), 'lines' => [[
            'purchase_order_line_id' => (int) $purchase->lines()->value('id'), 'quantity' => 10, 'batch_number' => 'GRN-1', 'expires_on' => '2028-01-01', 'unit_cost' => '55.00',
        ]]]);
        app(StockService::class)->stockIn($owner, ['product_id' => $product->id, 'quantity' => 2, 'reference' => (string) Str::uuid(), 'branch_id' => $hub->id]);

        $orders = app(OrderService::class);
        $order = $orders->create(User::factory()->dealer()->create(['status' => 'active']), ['items' => [['product_id' => $product->id, 'quantity' => 3]], 'idempotency_key' => (string) Str::uuid()]);
        DB::table('orders')->where('id', $order->id)->update(['paid' => $order->total]);
        $this->receipt($order->id, (string) $order->total, now()->toDateTimeString());
        $orders->decision($owner, $order->fresh(), 'approved', 'Paid in full');
        $orders->dispatch($owner, $order->fresh(), ['items' => [['line_id' => (int) $order->lines()->value('id'), 'quantity' => 3]], 'idempotency_key' => (string) Str::uuid(), 'courier' => 'Test courier', 'tracking' => 'TRK-1', 'branch_id' => $hub->id]);

        // On the road: no sale yet, and the 3 x 55 is an asset rather than a cost.
        $sent = app(AccountingStatements::class)->build($day, $day);
        $this->assertNull($this->amount($sent['profit_loss']['income'], 'product_sales'));
        $this->assertSame('165.00', $this->amount($sent['balance_sheet']['assets'], 'goods_in_transit'));
        $this->assertSame('firm', collect($sent['balance_sheet']['assets'])->firstWhere('account', 'goods_in_transit')['reliability']);
        $this->assertSame('385.00', $this->amount($sent['balance_sheet']['assets'], 'stock_at_cost'));
        $this->assertSame('60.00', $this->amount($sent['balance_sheet']['assets'], 'stock_estimated'), 'Stock added by hand carries a copied price, so it is an estimate.');
        $this->assertSame((string) $order->total, $this->amount($sent['balance_sheet']['liabilities'], 'paid_not_delivered'));
        $this->assertSame('550.00', $this->amount($sent['balance_sheet']['liabilities'], 'supplier_due'));
        $this->assertSame('60.00', $this->amount($sent['balance_sheet']['equity'], 'stock_without_receipt'));
        $this->assertSame('0.00', $sent['balance_sheet']['difference']);

        $orders->deliver($owner, $order->fresh(), (int) DB::table('shipments')->where('order_id', $order->id)->value('id'), 'Received by dealer');

        $patient = PrescriptionPatient::factory()->create(['prescriber_id' => $owner->id]);
        $this->actingAs($owner)->onPortal('sadmin')->post('/commands/prescriptions', [
            'patient_id' => $patient->id, 'prescribed_on' => $day, 'diagnosis' => 'Test',
            'lines' => [['product_id' => $product->id, 'dose' => '1', 'unit_price' => '150', 'quantity' => 1]],
        ])->assertRedirect()->assertSessionHasNoErrors();
        app(ClinicDispensing::class)->dispense($owner, Prescription::query()->where('prescriber_id', $owner->id)->firstOrFail(), $hub->id);

        $done = app(AccountingStatements::class)->build($day, $day);
        $pl = $done['profit_loss'];
        $sheet = $done['balance_sheet'];
        $this->assertSame((string) $order->total, $this->amount($pl['income'], 'product_sales'));
        $this->assertSame('165.00', $this->amount($pl['cost_of_sales'], 'cost_of_goods'));
        // 150 price, 60 manufacturer share at 30% of the 200 MRP, and the 90 is not added again.
        $this->assertSame('150.00', $this->amount($pl['income'], 'clinic_sales'));
        $this->assertSame('60.00', $this->amount($pl['cost_of_sales'], 'clinic_manufacturer_share'));
        $this->assertNull($this->amount($pl['income'], 'clinic_fund'));
        $this->assertNull($this->amount($sheet['assets'], 'goods_in_transit'));
        $this->assertNull($this->amount($sheet['liabilities'], 'paid_not_delivered'));
        // 6 received units left at 55; the manufacturer's 60 less the 55 the goods cost.
        $this->assertSame('330.00', $this->amount($sheet['assets'], 'stock_at_cost'));
        $this->assertSame('5.00', $this->amount($sheet['liabilities'], 'manufacturer_fund'));
        $this->assertSame('0.00', $sheet['difference']);
        $this->assertTrue($done['checks']['result_reconciled']);
        $this->assertTrue($done['checks']['ties_to_journal']);
        $this->assertNotContains(__('accounting.note_lots_do_not_match'), $done['notes']);
        $this->assertNotContains(__('accounting.note_purchases_do_not_match'), $done['notes']);
    }

    public function test_every_line_names_its_source_and_a_reliability(): void
    {
        $this->seedStory();

        $october = app(AccountingStatements::class)->build('2026-10-01', '2026-10-31');
        $lines = collect([
            ...$october['profit_loss']['income'], ...$october['profit_loss']['cost_of_sales'], ...$october['profit_loss']['expenses'],
            ...$october['balance_sheet']['assets'], ...$october['balance_sheet']['liabilities'], ...$october['balance_sheet']['equity'],
        ]);

        $this->assertTrue($lines->every(fn (array $line): bool => filled($line['source_label'])));
        $byAccount = $lines->keyBy('account');
        $this->assertSame('delivered_orders', $byAccount['product_sales']['source']);
        $this->assertSame('clinic_dispenses', $byAccount['clinic_sales']['source']);
        $this->assertSame('firm', $byAccount['cost_of_goods']['reliability']);
        $this->assertSame('estimate', $byAccount['cost_of_goods_estimate']['reliability']);
        $this->assertSame('needs_check', $byAccount['supplier_due']['reliability']);
        $this->assertSame('estimate', $byAccount['goods_in_transit']['reliability'], 'Part of what is on the road is valued from an opening lot.');
    }

    public function test_units_with_no_cost_are_reported_and_not_guessed(): void
    {
        $this->seedStory();

        $october = app(AccountingStatements::class)->build('2026-10-01', '2026-10-31');

        $this->assertContains(__('accounting.note_cost_missing', ['units' => '3']), $october['notes']);
        // Goods with no cost add nothing: the seeded story already proves the money, this proves the units are named.
        $this->assertSame('300.00', $this->amount($october['profit_loss']['cost_of_sales'], 'cost_of_goods'));
    }

    public function test_finance_sees_owners_own_fund_as_one_line_and_the_same_result(): void
    {
        $this->seedStory();
        $this->spend('benefits', 'Office rent', '40.00', '2026-10-30 06:00:00', 'company_fund_expense', 'manual_payout_clearing');

        $owner = app(AccountingStatements::class)->build('2026-10-01', '2026-10-31', true);
        $finance = app(AccountingStatements::class)->build('2026-10-01', '2026-10-31', false);

        $this->assertSame('160.00', $this->amount($owner['profit_loss']['expenses'], 'head_'.$this->headId('Office rent')));
        $this->assertSame('120.00', $this->amount($finance['profit_loss']['expenses'], 'head_'.$this->headId('Office rent')));
        $this->assertSame('40.00', $this->amount($finance['profit_loss']['expenses'], 'head_owners_own_fund'));
        $this->assertSame($owner['profit_loss']['result'], $finance['profit_loss']['result']);
        $this->assertSame($owner['balance_sheet']['difference'], $finance['balance_sheet']['difference']);
    }

    public function test_money_held_on_a_cancelled_order_is_flagged_for_checking(): void
    {
        $order = $this->order('cancelled', '250.00', '250.00');
        $this->receipt($order, '250.00', '2026-10-03 06:00:00');

        $october = app(AccountingStatements::class)->build('2026-10-01', '2026-10-31');

        $this->assertSame('250.00', $this->amount($october['balance_sheet']['liabilities'], 'paid_not_delivered'));
        $this->assertContains(__('accounting.note_money_on_closed_orders', ['count' => '1', 'amount' => '250.00']), $october['notes']);
    }

    public function test_reading_the_statements_writes_nothing(): void
    {
        $this->seedStory();
        $before = [DB::table('journal_entries')->count(), DB::table('journal_lines')->count(), DB::table('stock_lots')->sum('quantity')];

        app(AccountingStatements::class)->build('2026-10-01', '2026-10-31');

        $this->assertSame($before, [DB::table('journal_entries')->count(), DB::table('journal_lines')->count(), DB::table('stock_lots')->sum('quantity')]);
    }

    public function test_the_statements_have_the_new_lines_and_what_an_accountant_must_check(): void
    {
        $this->seedStory();

        $statements = app(AccountingStatements::class)->build('2026-10-01', '2026-10-31', true);

        $this->assertSame('680.00', $statements['profit_loss']['result']);
        $this->assertSame('0.00', $statements['balance_sheet']['difference']);
        $this->assertSame(AccountingStatements::accountantChecks(), $statements['accountant_checks']);
    }

    public function test_the_notice_and_every_accountant_check_exist_in_both_languages(): void
    {
        $english = require lang_path('en/accounting.php');
        $bangla = require lang_path('bn/accounting.php');

        $this->assertStringContainsString('not audited', $english['management_notice']);
        $this->assertStringContainsString('অডিট', $bangla['management_notice']);
        $this->assertSame(array_keys($english), array_keys($bangla));
        foreach (range(1, 7) as $number) {
            $this->assertNotSame('', $english['accountant_check_'.$number]);
            $this->assertNotSame('', $bangla['accountant_check_'.$number]);
        }
    }

    public function test_the_exports_carry_the_source_column(): void
    {
        $this->seedStory();

        $report = app(AccountingStatements::class)->report('profit-loss', '2026-10-01', '2026-10-31');

        $this->assertContains('source', $report['columns']);
        $sale = collect($report['rows'])->firstWhere('account', __('accounting.line_cost_of_goods_estimate'));
        $this->assertStringContainsString(__('accounting.reliability_estimate'), $sale['source']);
        $this->assertSame('680.00', $report['totals']['amount']);
    }

    /**
     * September: 10 opening units at 40, 20 received at 50, commission 80.
     * October: two delivered orders, one on the road, one paid and waiting, one refunded, one
     * old opening-balance order, goods received, a write-off, stock found, a clinic sale and
     * spends from four different funds.
     */
    private function seedStory(): void
    {
        $this->journal('2026-09-20 06:00:00', 'commission.sep', [['commission_expense', '80.00', '0.00'], ['wallet_liability', '0.00', '80.00']]);
        $openingLot = $this->lot($this->priced, 10, '40.00', 'opening', '2026-09-01 06:00:00', 'opening');
        $boughtLot = $this->receiveGoods($this->priced, 20, '50.00', '2026-09-10 06:00:00');

        // Delivered 10 October: 6 units at 50 = 300 cost, money received before.
        $delivered = $this->order('delivered', '900.00', '900.00');
        $deliveredLine = $this->orderLine($delivered, $this->priced, 6);
        $this->shipment($delivered, 'delivered', '2026-10-10 06:00:00');
        $this->receipt($delivered, '900.00', '2026-10-02 06:00:00');
        $this->movement($boughtLot, -6, 'dispatch', '2026-10-05 06:00:00', orderId: $delivered, orderLineId: $deliveredLine);

        // On the road at the end of October, delivered 5 November: 2 units at 40.
        $travelling = $this->order('delivered', '300.00', '300.00');
        $travellingLine = $this->orderLine($travelling, $this->priced, 2);
        $this->shipment($travelling, 'delivered', '2026-11-05 06:00:00');
        $this->receipt($travelling, '300.00', '2026-10-15 06:00:00');
        $this->movement($openingLot, -2, 'dispatch', '2026-10-20 06:00:00', orderId: $travelling, orderLineId: $travellingLine);

        // Imported from the old system: already in the journal through the opening balances.
        $opening = $this->order('delivered', '5000.00', '5000.00', true);
        $this->orderLine($opening, $this->priced, 5);
        $this->shipment($opening, 'delivered', '2026-10-06 06:00:00');
        $this->receipt($opening, '5000.00', '2026-10-06 06:00:00');

        $waiting = $this->order('approved', '200.00', '200.00');
        $this->receipt($waiting, '200.00', '2026-10-25 06:00:00');

        $refunded = $this->order('cancelled', '100.00', '100.00');
        $this->receipt($refunded, '100.00', '2026-10-03 06:00:00');
        DB::table('order_refunds')->insert(['id' => (string) Str::uuid(), 'order_id' => $refunded, 'user_id' => $this->staff->id, 'amount' => '100.00', 'paid_before' => '100.00', 'reason' => 'Changed their mind', 'refunded_by' => $this->staff->id, 'created_at' => '2026-10-04 06:00:00', 'updated_at' => '2026-10-04 06:00:00']);

        // Sent before lots existed: 1 unit at the product price, 3 units with no price at all.
        $noLots = $this->order('delivered', '150.00', '150.00');
        $this->orderLine($noLots, $this->priced, 1);
        $this->orderLine($noLots, $this->unpriced, 3);
        $this->shipment($noLots, 'delivered', '2026-10-20 06:00:00');
        $this->receipt($noLots, '150.00', '2026-10-02 06:00:00');

        $writtenOffLot = $this->receiveGoods($this->priced, 5, '60.00', '2026-10-12 06:00:00');
        $this->movement($writtenOffLot, -1, 'manual_out', '2026-10-22 06:00:00');
        $this->lot($this->priced, 2, null, 'stock_in', '2026-10-23 06:00:00', 'stock_in');

        $this->journal('2026-10-11 06:00:00', 'commission.oct', [['commission_expense', '100.00', '0.00'], ['wallet_liability', '0.00', '100.00']]);
        $this->clinicSale('700.00', '300.00', '400.00', '2026-10-08 06:00:00', $boughtLot);

        $this->spend('company_profit', 'Office rent', '120.00', '2026-10-09 06:00:00', 'company_fund_expense', 'manual_payout_clearing');
        $this->spend('marketing', 'Advertising and promotion', '30.00', '2026-10-09 07:00:00', 'company_marketing_expense', 'manual_payout_clearing');
        $this->spend('clinic', 'Clinic expenses', '50.00', '2026-10-09 08:00:00', 'clinic_fund', 'clinic_cash');
        $this->spend('company_share', 'Office supplies', '70.00', '2026-10-09 09:00:00', 'manufacturer_fund', 'manual_payout_clearing');
        $this->spend('company_profit', 'Payment to manufacturer', '200.00', '2026-10-09 10:00:00', 'company_fund_expense', 'manual_payout_clearing');
        $this->spend('benefits', "Owner's drawings", '500.00', '2026-10-09 11:00:00', 'company_fund_expense', 'manual_payout_clearing');
        $this->spend('company_share', 'Payment to manufacturer', '100.00', '2026-10-09 12:00:00', 'manufacturer_fund', 'manual_payout_clearing');
    }

    /** @param list<array{0: string, 1: string, 2: string}> $lines */
    private function journal(string $when, string $key, array $lines): void
    {
        $id = (string) Str::uuid();
        DB::table('journal_entries')->insert(['id' => $id, 'event_key' => $key, 'description' => 'Seeded', 'created_at' => $when]);
        foreach ($lines as [$account, $debit, $credit]) {
            DB::table('journal_lines')->insert(['entry_id' => $id, 'account' => $account, 'debit' => $debit, 'credit' => $credit]);
        }
    }

    private function headId(string $name): int
    {
        return (int) DB::table('fund_expense_heads')->where('name_en', $name)->value('id');
    }

    private function spend(string $fund, string $head, string $amount, string $when, string $debitAccount, string $creditAccount): void
    {
        $id = DB::table('reserve_disbursements')->insertGetId([
            'program' => 'company_spend', 'fund_key' => $fund, 'expense_head_id' => $this->headId($head), 'payee_name' => 'Payee', 'amount' => $amount,
            'spent_on' => substr($when, 0, 10), 'payment_method' => 'cash', 'request_key' => (string) Str::uuid(), 'reason' => 'Seeded', 'status' => 'paid',
            'created_by' => $this->staff->id, 'paid_at' => $when, 'created_at' => $when, 'updated_at' => $when,
        ]);
        $this->journal($when, 'fund.spend.'.$id, [[$debitAccount, $amount, '0.00'], [$creditAccount, '0.00', $amount]]);
    }

    private function order(string $status, string $total, string $paid, bool $openingBalance = false): string
    {
        $id = (string) Str::uuid();
        DB::table('orders')->insert([
            'id' => $id, 'number' => 'ORD-'.Str::random(8), 'user_id' => $this->staff->id, 'status' => $status, 'total' => $total, 'paid' => $paid,
            'idempotency_key' => (string) Str::uuid(), 'payload_hash' => Str::random(40), 'is_opening_balance' => $openingBalance, 'created_at' => '2026-09-25 06:00:00', 'updated_at' => '2026-09-25 06:00:00',
        ]);

        return $id;
    }

    private function orderLine(string $order, Product $product, int $quantity): int
    {
        return DB::table('order_lines')->insertGetId([
            'order_id' => $order, 'product_id' => $product->id, 'name' => $product->name, 'sku' => $product->sku, 'quantity' => $quantity, 'dispatched' => $quantity,
            'mrp' => '100.00', 'unit_price' => '100.00', 'total' => number_format($quantity * 100, 2, '.', ''), 'created_at' => '2026-09-25 06:00:00', 'updated_at' => '2026-09-25 06:00:00',
        ]);
    }

    private function shipment(string $order, string $status, string $deliveredAt): void
    {
        DB::table('shipments')->insert(['order_id' => $order, 'courier' => 'Own', 'tracking' => 'T'.Str::random(6), 'status' => $status, 'lines' => '[]', 'actor_id' => $this->staff->id, 'branch_id' => $this->branch->id, 'delivered_at' => $deliveredAt, 'created_at' => $deliveredAt, 'updated_at' => $deliveredAt]);
    }

    private function receipt(string $order, string $amount, string $when): void
    {
        $claim = DB::table('payment_claims')->insertGetId(['order_id' => $order, 'channel' => 'bank', 'reference' => 'R'.Str::random(10), 'amount' => $amount, 'status' => 'verified', 'created_at' => $when, 'updated_at' => $when]);
        DB::table('receipts')->insert(['order_id' => $order, 'claim_id' => $claim, 'channel' => 'bank', 'reference' => 'R'.Str::random(10), 'amount' => $amount, 'created_at' => $when, 'updated_at' => $when]);
    }

    private function lot(Product $product, int $quantity, ?string $cost, string $source, string $when, string $kind, ?string $reference = null): int
    {
        $id = DB::table('stock_lots')->insertGetId([
            'branch_id' => $this->branch->id, 'product_id' => $product->id, 'quantity' => $quantity, 'received_quantity' => $quantity, 'received_on' => substr($when, 0, 10),
            'unit_cost' => $cost, 'source' => $source, 'reference' => $reference, 'created_at' => $when, 'updated_at' => $when,
        ]);
        DB::table('stock_lot_movements')->insert(['lot_id' => $id, 'branch_id' => $this->branch->id, 'product_id' => $product->id, 'quantity' => $quantity, 'kind' => $kind, 'reference' => $reference, 'created_at' => $when, 'updated_at' => $when]);

        return $id;
    }

    private function receiveGoods(Product $product, int $quantity, string $cost, string $when): int
    {
        $reference = (string) Str::uuid();
        $lot = $this->lot($product, $quantity, $cost, 'stock_in', $when, 'stock_in', $reference);
        $supplier = Supplier::factory()->create(['created_by' => $this->staff->id]);
        $order = DB::table('purchase_orders')->insertGetId(['supplier_id' => $supplier->id, 'branch_id' => $this->branch->id, 'status' => 'received', 'created_by' => $this->staff->id, 'created_at' => $when, 'updated_at' => $when]);
        $line = DB::table('purchase_order_lines')->insertGetId(['purchase_order_id' => $order, 'product_id' => $product->id, 'quantity_ordered' => $quantity, 'quantity_received' => $quantity, 'created_at' => $when, 'updated_at' => $when]);
        $receipt = DB::table('goods_receipts')->insertGetId(['reference' => (string) Str::uuid(), 'purchase_order_id' => $order, 'branch_id' => $this->branch->id, 'received_by' => $this->staff->id, 'created_at' => $when, 'updated_at' => $when]);
        DB::table('goods_receipt_lines')->insert(['goods_receipt_id' => $receipt, 'purchase_order_line_id' => $line, 'product_id' => $product->id, 'quantity' => $quantity, 'batch_number' => 'B-'.$lot, 'expires_on' => '2028-01-01', 'unit_cost' => $cost, 'stock_reference' => $reference, 'created_at' => $when, 'updated_at' => $when]);

        return $lot;
    }

    private function movement(int $lot, int $quantity, string $kind, string $when, ?string $orderId = null, ?int $orderLineId = null): void
    {
        DB::table('stock_lot_movements')->insert(['lot_id' => $lot, 'branch_id' => $this->branch->id, 'product_id' => $this->priced->id, 'quantity' => $quantity, 'kind' => $kind, 'order_id' => $orderId, 'order_line_id' => $orderLineId, 'created_at' => $when, 'updated_at' => $when]);
        DB::table('stock_lots')->where('id', $lot)->update(['quantity' => DB::raw('quantity + '.$quantity)]);
    }

    private function clinicSale(string $price, string $manufacturer, string $clinicFund, string $when, int $lot): void
    {
        $prescription = Prescription::factory()->create([
            'kind' => Prescription::KindClinic, 'status' => Prescription::StatusDispensed, 'total' => $price, 'manufacturer_share' => $manufacturer, 'clinic_fund' => $clinicFund, 'dispensed_at' => $when, 'payment_method' => Prescription::PaymentCash,
        ]);
        $this->journal($when, 'clinic.dispense.'.$prescription->id, [['clinic_cash', $price, '0.00'], ['manufacturer_fund', '0.00', $manufacturer], ['clinic_fund', '0.00', $clinicFund]]);
        $this->movement($lot, -1, 'clinic_dispense', $when);
    }

    /** @param list<array<string, string>> $lines */
    private function amount(array $lines, string $account): ?string
    {
        return collect($lines)->firstWhere('account', $account)['amount'] ?? null;
    }
}
