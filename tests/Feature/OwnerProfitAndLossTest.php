<?php

namespace Tests\Feature;

use App\Domain\Finance\ProfitAndLoss;
use App\Models\CompanyBranch;
use App\Models\Product;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\TestCase;

/**
 * The owner's profit and loss. The November story below is worked out by hand in the comments,
 * so the expected numbers are not just the code's own output.
 */
class OwnerProfitAndLossTest extends TestCase
{
    use RefreshDatabase;

    private User $owner;

    private User $dealer;

    private User $upline;

    private CompanyBranch $branch;

    protected function setUp(): void
    {
        parent::setUp();
        $this->withoutVite();
        $this->owner = User::factory()->staff()->create();
        $this->dealer = User::factory()->dealer()->create();
        $this->upline = User::factory()->dealer()->create();
        $this->branch = CompanyBranch::factory()->create();
    }

    public function test_the_november_story_adds_up_by_hand(): void
    {
        $this->seedNovember();

        $report = app(ProfitAndLoss::class)->build('2026-11-01', '2026-11-30');
        $figures = $report['figures'];

        // Sales: tea order 1,500 + honey order 1,000 + partly delivered 600 (of 1,000) + one
        // delivered parcel of a dispatched order 150 = 3,250. October and opening orders are out.
        $this->assertSame(4, $report['orders']['count']);
        $this->assertSame(3, $report['orders']['with_commission']);
        $this->assertSame('3250.00', $figures['sales']);
        // The honey order was refunded 200 after delivery: 3,250 - 200 = 3,050.
        $this->assertSame('200.00', $figures['refunds']);
        $this->assertSame('3050.00', $figures['net_sales']);

        // Cost: tea 10 x 100 + honey 5 x 80 (its batch, not the 60 cost price) + tea 2 x 100 +
        // tea 1 x 100 = 1,700. Neem oil (4 units) has no cost and is listed, not guessed.
        $this->assertSame('1700.00', $figures['cost_of_goods']);
        $this->assertSame('400.00', $report['cost_basis']['from_batches']);
        $this->assertSame('1300.00', $report['cost_basis']['from_product_price']);
        $this->assertSame(4, $report['cost_basis']['missing_units']);
        $this->assertSame('1350.00', $figures['gross_margin']);

        // In wallets 300 + 200 (250 less the 20% refund) + 270 = 770; waiting 150 + 80 = 230;
        // funds: marketing 150, management 60, tour 30, car/bike 90, royalty 80 (100 less 20%) = 410.
        $this->assertSame('770.00', $figures['partners_credited']);
        $this->assertSame('230.00', $figures['partners_waiting']);
        $this->assertSame('410.00', $figures['funds_total']);
        $this->assertSame('1410.00', $figures['set_aside_total']);
        $this->assertSame(
            ['management' => '60.00', 'marketing' => '150.00', 'tour' => '30.00', 'car_bike' => '90.00', 'royalty_pool' => '80.00'],
            collect($report['funds'])->mapWithKeys(fn (array $fund): array => [$fund['key'] => $fund['amount']])->all(),
        );

        // Nothing records courier costs. Salary: one approved November slip of 500 (the draft
        // and the October slip are out). Office rent 120 from company profit; paying the
        // manufacturer, owners' drawings and a marketing spend are not running costs here.
        $this->assertNull($figures['courier']);
        $this->assertSame('500.00', $figures['payroll']);
        $this->assertSame(1, $report['payroll_slips']);
        $this->assertSame('120.00', $figures['office_spending']);

        // 1,350 - 1,410 - 500 - 120 = -680: these sales lose money once the plan pays out.
        $this->assertSame('-680.00', $figures['profit']);

        // Kept: company share 450 + 240 (300 less 20%) + 180 = 870; company profit 40 (the
        // forfeited 50 less 20%); owners' share 300 + 160 + 120 = 580; total 1,490.
        $this->assertSame(['company_share' => '870.00', 'company_profit' => '40.00', 'owners_share' => '580.00', 'total' => '1490.00'], $report['kept']);
        // Posted money left after refunds: 1,500 + 1,000 + 600 - 200 = 2,900 = 1,410 + 1,490.
        $this->assertTrue($report['check']['adds_up']);
        $this->assertSame('0.00', $report['check']['difference']);
        // 1,410 / 2,900 = 48.62%.
        $this->assertSame(['limit' => '50', 'actual' => '48.62'], $report['plan_share']);

        $this->assertSame(['orders' => 1, 'sales' => '150.00'], $report['warnings']['no_commission_yet']);
    }

    public function test_a_refund_after_delivery_comes_off_the_sale_and_its_commission(): void
    {
        $tea = $this->product('Herbal tea', '150.00', '100.00');
        $order = $this->order('delivered', '1000.00');
        $this->line($order, $tea, 10, '100.00');
        $this->parcel($order, 'delivered', '2026-11-05 06:00:00');
        // Posted on 1,000: company share 300, commission 500, owners 200. Then 400 refunded (40%).
        $this->batch($order, '1000.00', '300.00', '500.00', '200.00', '400.00');
        $this->allocation($order, 'direct_purchase_commission', $this->dealer->id, 'available', '500.00', '300.00');

        $report = app(ProfitAndLoss::class)->build('2026-11-01', '2026-11-30');

        // Net sales 1,000 - 400 = 600; commission 500 x 60% = 300; company share 180; owners 120.
        $this->assertSame('400.00', $report['figures']['refunds']);
        $this->assertSame('600.00', $report['figures']['net_sales']);
        $this->assertSame('300.00', $report['figures']['partners_credited']);
        $this->assertSame('180.00', $report['kept']['company_share']);
        $this->assertSame('120.00', $report['kept']['owners_share']);
        // 600 - cost 1,000 - commission 300 = -700.
        $this->assertSame('-700.00', $report['figures']['profit']);
        $this->assertTrue($report['check']['adds_up']);
    }

    public function test_a_partly_delivered_order_counts_only_its_delivered_part(): void
    {
        $tea = $this->product('Herbal tea', '150.00', '100.00');
        $order = $this->order('partly_delivered', '1000.00');
        $line = $this->line($order, $tea, 10, '100.00');
        $this->parcel($order, 'delivered', '2026-11-20 06:00:00', [[$line, 3]]);
        $this->parcel($order, 'returned', '2026-11-21 06:00:00', [[$line, 7]]);

        $report = app(ProfitAndLoss::class)->build('2026-11-01', '2026-11-30');

        // 3 of 10 units arrived: sales 3 x 100 = 300, cost 3 x 100 = 300, not 1,000 each.
        $this->assertSame('300.00', $report['figures']['sales']);
        $this->assertSame('300.00', $report['figures']['cost_of_goods']);
        $this->assertSame(['orders' => 1, 'sales' => '300.00'], $report['warnings']['no_commission_yet']);
    }

    public function test_delivered_products_with_no_cost_are_listed(): void
    {
        $neem = $this->product('Neem oil', '100.00', null);
        $order = $this->order('delivered', '400.00');
        $this->line($order, $neem, 4, '100.00');
        $this->parcel($order, 'delivered', '2026-11-08 06:00:00');

        $report = app(ProfitAndLoss::class)->build('2026-11-01', '2026-11-30');

        $this->assertSame('0.00', $report['figures']['cost_of_goods']);
        $this->assertSame([['product_id' => $neem->id, 'name' => 'Neem oil', 'sku' => $neem->sku, 'units' => 4, 'sales' => '400.00']], $report['warnings']['missing_cost']);
    }

    public function test_products_costing_more_than_the_company_part_of_the_dealer_price_are_flagged(): void
    {
        // Dealer 150, plan pays out 50%: the company keeps 75 and the tea costs 100, 25 over.
        $tea = $this->product('Herbal tea', '150.00', '100.00');
        // No cost price: its newest batch (130, not the older 90) against 100 left of 200.
        $ghee = $this->product('Ghee', '200.00', null);
        $this->lot($ghee, 5, '90.00', '2026-10-01');
        $this->lot($ghee, 5, '130.00', '2026-11-01');
        $this->product('Honey', '200.00', '100.00');
        $this->product('Neem oil', '100.00', null);
        $this->product('Old line', '100.00', '90.00', active: false);

        $over = app(ProfitAndLoss::class)->build('2026-11-01', '2026-11-30')['warnings']['over_margin'];

        $this->assertSame([
            ['product_id' => $ghee->id, 'name' => 'Ghee', 'sku' => $ghee->sku, 'dealer_price' => '200.00', 'cost' => '130.00', 'limit' => '100.00', 'over_by' => '30.00', 'cost_percent' => '65.00'],
            ['product_id' => $tea->id, 'name' => 'Herbal tea', 'sku' => $tea->sku, 'dealer_price' => '150.00', 'cost' => '100.00', 'limit' => '75.00', 'over_by' => '25.00', 'cost_percent' => '66.67'],
        ], $over);
    }

    public function test_the_owner_opens_the_page_for_a_month_in_both_languages(): void
    {
        $this->seedNovember();

        foreach (['bn', 'en'] as $locale) {
            $this->owner->forceFill(['locale' => $locale])->save();
            $this->actingAs($this->owner)->onPortal('sadmin')->get('/treasury/profit?month=2026-11')
                ->assertOk()
                ->assertInertia(fn (Assert $page) => $page->component('TreasuryProfit')
                    ->where('section', 'treasury')
                    ->where('month', '2026-11')
                    ->where('report.period', ['from' => '2026-11-01', 'to' => '2026-11-30'])
                    ->where('report.figures.profit', '-680.00')
                    ->where('copy.title', __('profit_loss.title', [], $locale)));
        }
    }

    public function test_the_owner_can_pick_dates_but_not_more_than_a_year(): void
    {
        $this->actingAs($this->owner)->onPortal('sadmin')->get('/treasury/profit?from=2026-11-05&to=2026-11-10')
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page->where('month', null)->where('report.period', ['from' => '2026-11-05', 'to' => '2026-11-10']));

        $this->actingAs($this->owner)->onPortal('sadmin')->get('/treasury/profit?from=2025-11-01&to=2026-11-01')->assertSessionHasErrors('to');
        $this->actingAs($this->owner)->onPortal('sadmin')->get('/treasury/profit?from=2026-11-10&to=2026-11-01')->assertSessionHasErrors('to');
    }

    public function test_staff_and_partners_cannot_open_it(): void
    {
        $this->actingAs(User::factory()->staff('finance')->create())->onPortal('manage')->get('/treasury/profit')->assertForbidden();
        $this->actingAs($this->dealer)->onPortal('dealer')->get('/treasury/profit')->assertForbidden();
        $this->actingAs(User::factory()->subDealer()->create())->onPortal('subdealer')->get('/treasury/profit')->assertForbidden();
    }

    /** The November story; every figure is worked out in test_the_november_story_adds_up_by_hand. */
    private function seedNovember(): void
    {
        $tea = $this->product('Herbal tea', '150.00', '100.00');
        $honey = $this->product('Honey', '200.00', '60.00');
        $neem = $this->product('Neem oil', '100.00', null);

        // 1. Tea, delivered 10 Nov: 10 x 150. Company 450 + commission 750 + owners 300 = 1,500.
        $teaOrder = $this->order('delivered', '1500.00');
        $this->line($teaOrder, $tea, 10, '150.00');
        $this->parcel($teaOrder, 'delivered', '2026-11-10 06:00:00');
        $this->batch($teaOrder, '1500.00', '450.00', '750.00', '300.00');
        $this->allocation($teaOrder, 'direct_purchase_commission', $this->dealer->id, 'available', '300.00');
        $this->allocation($teaOrder, 'referral_commission', $this->upline->id, 'maturing', '150.00');
        $this->allocation($teaOrder, 'company_marketing_fund', null, 'reserved', '150.00');
        $this->allocation($teaOrder, 'car_bike_incentive_fund', null, 'reserved', '90.00');
        $this->allocation($teaOrder, 'area_management', null, 'reserved', '60.00');
        $this->allocation($teaOrder, 'company_commission_benefits_fund', null, 'reserved', '300.00');

        // 2. Honey, delivered 15 Nov from a batch bought at 80: 5 x 200. Company 300 + commission
        // 500 + owners 200 = 1,000; 200 refunded later (20%), so every share is 80% of posted.
        $honeyLot = $this->lot($honey, 20, '80.00', '2026-11-01');
        $honeyOrder = $this->order('delivered', '1000.00');
        $honeyLine = $this->line($honeyOrder, $honey, 5, '200.00');
        $this->parcel($honeyOrder, 'delivered', '2026-11-15 06:00:00');
        $this->dispatchFrom($honeyLot, $honey, -5, $honeyOrder, $honeyLine);
        $this->batch($honeyOrder, '1000.00', '300.00', '500.00', '200.00', '200.00');
        $this->allocation($honeyOrder, 'direct_purchase_commission', $this->dealer->id, 'available', '250.00', '200.00');
        $this->allocation($honeyOrder, 'referral_commission', $this->upline->id, 'held', '100.00', '80.00');
        $this->allocation($honeyOrder, 'rank_royalty_pool', null, 'pending', '100.00', '80.00');
        // A held area share whose target was missed: moved to company profit by a new row.
        $forfeited = $this->allocation($honeyOrder, 'area_dealer_commission', $this->upline->id, 'forfeited', '50.00', '40.00');
        $this->allocation($honeyOrder, 'company_profit_fund', null, 'reserved', '40.00', '40.00', ['source_allocation_id' => $forfeited]);
        $this->allocation($honeyOrder, 'company_commission_benefits_fund', null, 'reserved', '200.00', '160.00');
        DB::table('commission_refunds')->insert(['id' => (string) Str::uuid(), 'order_id' => $honeyOrder, 'reference' => 'RET-1', 'amount' => '200.00', 'reason' => 'Two jars broken', 'created_at' => '2026-11-18 06:00:00', 'updated_at' => '2026-11-18 06:00:00']);

        // 3. Partly delivered 20 Nov: neem 4 x 100 and tea 2 of 6 x 100 arrived = 600 of 1,000.
        $partOrder = $this->order('partly_delivered', '1000.00');
        $neemLine = $this->line($partOrder, $neem, 4, '100.00');
        $teaLine = $this->line($partOrder, $tea, 6, '100.00');
        $this->parcel($partOrder, 'delivered', '2026-11-20 06:00:00', [[$neemLine, 4], [$teaLine, 2]]);
        $this->parcel($partOrder, 'returned', '2026-11-21 06:00:00', [[$teaLine, 4]]);
        $this->batch($partOrder, '600.00', '180.00', '300.00', '120.00');
        $this->allocation($partOrder, 'direct_purchase_commission', $this->dealer->id, 'available', '270.00');
        $this->allocation($partOrder, 'sub_dealer_tour', null, 'reserved', '30.00');

        // 4. Still going out: 1 of 3 tea delivered 25 Nov = 150; commission comes when it finishes.
        $goingOrder = $this->order('dispatched', '450.00');
        $goingLine = $this->line($goingOrder, $tea, 3, '150.00');
        $this->parcel($goingOrder, 'delivered', '2026-11-25 06:00:00', [[$goingLine, 1]]);
        $this->parcel($goingOrder, 'dispatched', '2026-11-25 06:00:00', [[$goingLine, 2]]);

        // Out of the period: delivered 28 October, and an opening-balance order.
        $october = $this->order('delivered', '500.00');
        $this->line($october, $tea, 5, '100.00');
        $this->parcel($october, 'delivered', '2026-10-28 06:00:00');
        $opening = $this->order('delivered', '900.00', openingBalance: true);
        $this->line($opening, $tea, 9, '100.00');
        $this->parcel($opening, 'delivered', '2026-11-12 06:00:00');

        $this->payslip('2026-11', 'approved', '500.00');
        $this->payslip('2026-11', 'draft', '999.00');
        $this->payslip('2026-10', 'paid', '700.00');

        $this->spend('company_profit', 'Office rent', '120.00', '2026-11-12');
        $this->spend('company_share', 'Payment to manufacturer', '200.00', '2026-11-12');
        $this->spend('benefits', "Owner's drawings", '500.00', '2026-11-12');
        $this->spend('marketing', 'Advertising and promotion', '30.00', '2026-11-12');
        $this->spend('company_profit', 'Office rent', '75.00', '2026-10-30');
    }

    private function product(string $name, string $dealerPrice, ?string $cost, bool $active = true): Product
    {
        return Product::factory()->create(['name' => $name, 'dealer_price' => $dealerPrice, 'cost_price' => $cost, 'active' => $active]);
    }

    private function order(string $status, string $total, bool $openingBalance = false): string
    {
        $id = (string) Str::uuid();
        DB::table('orders')->insert([
            'id' => $id, 'number' => 'ORD-'.Str::random(8), 'user_id' => $this->dealer->id, 'status' => $status, 'total' => $total, 'paid' => $total,
            'idempotency_key' => (string) Str::uuid(), 'payload_hash' => Str::random(40), 'is_opening_balance' => $openingBalance,
            'created_at' => '2026-10-25 06:00:00', 'updated_at' => '2026-10-25 06:00:00',
        ]);

        return $id;
    }

    private function line(string $order, Product $product, int $quantity, string $unitPrice): int
    {
        return DB::table('order_lines')->insertGetId([
            'order_id' => $order, 'product_id' => $product->id, 'name' => $product->name, 'sku' => $product->sku, 'quantity' => $quantity, 'dispatched' => $quantity,
            'mrp' => '200.00', 'unit_price' => $unitPrice, 'total' => number_format($quantity * (float) $unitPrice, 2, '.', ''),
            'created_at' => '2026-10-25 06:00:00', 'updated_at' => '2026-10-25 06:00:00',
        ]);
    }

    /** @param list<array{0: int, 1: int}> $lines order line id and units in this parcel */
    private function parcel(string $order, string $status, string $at, array $lines = []): void
    {
        DB::table('shipments')->insert([
            'order_id' => $order, 'courier' => 'Own', 'tracking' => 'T'.Str::random(6), 'status' => $status,
            'lines' => json_encode(array_map(fn (array $line): array => ['line_id' => $line[0], 'quantity' => $line[1]], $lines)),
            'actor_id' => $this->owner->id, 'branch_id' => $this->branch->id, 'delivered_at' => $status === 'delivered' ? $at : null, 'created_at' => $at, 'updated_at' => $at,
        ]);
    }

    private function batch(string $order, string $cash, string $company, string $allocated, string $owners, string $refunded = '0.00'): void
    {
        DB::table('commission_batches')->insert([
            'order_id' => $order, 'account_type' => 'dealer', 'period_month' => '2026-11', 'cash_basis' => $cash, 'mrp_basis' => $cash,
            'company_share' => $company, 'total_allocated' => $allocated, 'unallocated' => $owners, 'refunded_amount' => $refunded,
            'policy' => '{}', 'created_at' => '2026-11-10 06:00:00', 'updated_at' => '2026-11-10 06:00:00',
        ]);
    }

    /** @param array<string, mixed> $metadata */
    private function allocation(string $order, string $category, ?int $userId, string $status, string $original, ?string $now = null, array $metadata = []): int
    {
        return DB::table('commission_allocations')->insertGetId([
            'order_id' => $order, 'user_id' => $userId, 'category' => $category, 'rate_percentage' => '10.00', 'cash_basis' => '0.00',
            'amount' => $now ?? $original, 'original_amount' => $original, 'status' => $status, 'metadata' => json_encode($metadata ?: new \stdClass),
            'created_at' => '2026-11-10 06:00:00', 'updated_at' => '2026-11-10 06:00:00',
        ]);
    }

    private function lot(Product $product, int $quantity, string $cost, string $receivedOn): int
    {
        return DB::table('stock_lots')->insertGetId([
            'branch_id' => $this->branch->id, 'product_id' => $product->id, 'quantity' => $quantity, 'received_quantity' => $quantity, 'received_on' => $receivedOn,
            'unit_cost' => $cost, 'source' => 'stock_in', 'created_at' => $receivedOn.' 06:00:00', 'updated_at' => $receivedOn.' 06:00:00',
        ]);
    }

    private function dispatchFrom(int $lot, Product $product, int $quantity, string $order, int $line): void
    {
        DB::table('stock_lot_movements')->insert([
            'lot_id' => $lot, 'branch_id' => $this->branch->id, 'product_id' => $product->id, 'quantity' => $quantity, 'kind' => 'dispatch',
            'order_id' => $order, 'order_line_id' => $line, 'created_at' => '2026-11-14 06:00:00', 'updated_at' => '2026-11-14 06:00:00',
        ]);
    }

    private function payslip(string $month, string $status, string $gross): void
    {
        $staff = User::factory()->staff('finance')->create();
        $contract = DB::table('staff_contracts')->insertGetId([
            'staff_id' => $staff->id, 'effective_month' => $month, 'base_salary' => $gross, 'allowance' => '0.00', 'deduction' => '0.00',
            'reason' => 'Hired', 'created_by' => $this->owner->id, 'created_at' => now(), 'updated_at' => now(),
        ]);
        DB::table('payroll_entries')->insert([
            'id' => (string) Str::uuid(), 'staff_id' => $staff->id, 'contract_id' => $contract, 'period_month' => $month, 'snapshot' => '{}',
            'gross' => $gross, 'deduction' => '0.00', 'net' => $gross, 'status' => $status, 'prepared_by' => $this->owner->id, 'created_at' => now(), 'updated_at' => now(),
        ]);
    }

    private function spend(string $fund, string $head, string $amount, string $on): void
    {
        DB::table('reserve_disbursements')->insert([
            'program' => 'company_spend', 'fund_key' => $fund, 'expense_head_id' => DB::table('fund_expense_heads')->where('name_en', $head)->value('id'), 'payee_name' => 'Payee',
            'amount' => $amount, 'spent_on' => $on, 'payment_method' => 'cash', 'request_key' => (string) Str::uuid(), 'reason' => 'Seeded', 'status' => 'paid',
            'created_by' => $this->owner->id, 'paid_at' => $on.' 06:00:00', 'created_at' => $on.' 06:00:00', 'updated_at' => $on.' 06:00:00',
        ]);
    }
}
