<?php

namespace Tests\Feature;

use App\Domain\Finance\AccountingStatements;
use App\Domain\Finance\OrderMoneyReport;
use App\Domain\Inventory\PurchasingService;
use App\Domain\Orders\DeliveredMoney;
use App\Domain\Orders\OrderService;
use App\Models\CompanyBranch;
use App\Models\Order;
use App\Models\Product;
use App\Models\Supplier;
use App\Models\User;
use App\Support\Money;
use Database\Seeders\CommissionRuleSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;
use Tests\TestCase;

/**
 * An order of 5 units sent in two parcels of 3 and 2. The owner's rule: every money figure counts
 * after delivery, and a partly delivered order counts the delivered part. Commission still posts
 * once, when the whole order has arrived.
 */
class BackOrderDeliveredPartTest extends TestCase
{
    use RefreshDatabase;

    private const CostPerUnit = '55.00';

    private User $owner;

    private User $dealer;

    private CompanyBranch $hub;

    private Order $order;

    protected function setUp(): void
    {
        parent::setUp();
        $this->withoutVite();
        Http::fake();
        $this->enableFinancialPosting();
        $this->seed(CommissionRuleSeeder::class);
        $this->travelTo(now()->setDate(2026, 11, 8)->setTime(12, 0));
        $this->hub = CompanyBranch::query()->where('code', 'HO-PIRGANJ')->firstOrFail();
        $this->owner = User::factory()->staff('super_admin')->create(['active_branch_id' => $this->hub->id]);
        $this->dealer = User::factory()->dealer()->create(['status' => 'active']);
        $product = Product::factory()->create(['stock' => 0, 'cost_price' => '30.00', 'mrp' => '200.00', 'batch_number' => null, 'expires_on' => null]);

        $purchasing = app(PurchasingService::class);
        $purchase = $purchasing->createOrder($this->owner, ['supplier_id' => Supplier::factory()->create(['created_by' => $this->owner->id])->id, 'branch_id' => $this->hub->id, 'lines' => [['product_id' => $product->id, 'quantity' => 10]]]);
        $purchasing->sendOrder($this->owner, $purchase);
        $purchasing->receive($this->owner, $purchase, ['reference' => (string) Str::uuid(), 'lines' => [[
            'purchase_order_line_id' => (int) $purchase->lines()->value('id'), 'quantity' => 10, 'batch_number' => 'GRN-1', 'expires_on' => '2028-01-01', 'unit_cost' => self::CostPerUnit,
        ]]]);

        $orders = app(OrderService::class);
        $order = $orders->create($this->dealer, ['items' => [['product_id' => $product->id, 'quantity' => 5]], 'idempotency_key' => (string) Str::uuid()]);
        DB::table('orders')->where('id', $order->id)->update(['paid' => $order->total]);
        $claim = DB::table('payment_claims')->insertGetId(['order_id' => $order->id, 'channel' => 'bank', 'reference' => 'R'.Str::random(10), 'amount' => $order->total, 'status' => 'verified', 'created_at' => now(), 'updated_at' => now()]);
        DB::table('receipts')->insert(['order_id' => $order->id, 'claim_id' => $claim, 'channel' => 'bank', 'reference' => 'R'.Str::random(10), 'amount' => $order->total, 'created_at' => now(), 'updated_at' => now()]);
        $orders->decision($this->owner, $order->fresh(), 'approved', 'Paid in full');
        foreach ([3, 2] as $parcel => $quantity) {
            $orders->dispatch($this->owner, $order->fresh(), ['items' => [['line_id' => (int) $order->lines()->value('id'), 'quantity' => $quantity]], 'idempotency_key' => (string) Str::uuid(), 'courier' => 'Test courier', 'tracking' => 'TRK-'.$parcel, 'branch_id' => $this->hub->id]);
        }
        $this->order = $order->fresh();
    }

    public function test_an_order_with_nothing_delivered_counts_nothing(): void
    {
        $this->assertSame('0.00', DeliveredMoney::valueOf($this->order));
        $this->assertSame([], $this->treasuryRows());
        $this->assertSame(0, OrderMoneyReport::deliveredOrders()->where('o.user_id', $this->dealer->id)->count());
    }

    public function test_the_delivered_parcel_counts_while_the_rest_is_still_to_come(): void
    {
        $this->deliverParcel(0);
        $order = $this->order->fresh();
        $line = $order->lines()->firstOrFail();
        $deliveredValue = Money::decimal(Money::cents((string) $line->getAttribute('unit_price')) * 3);
        $deliveredMrp = Money::decimal(Money::cents((string) $line->getAttribute('mrp')) * 3);

        $this->assertNotSame('delivered', $order->status);
        $this->assertSame($deliveredValue, DeliveredMoney::valueOf($order));

        $rows = $this->treasuryRows();
        $this->assertCount(1, $rows);
        $this->assertSame($deliveredValue, $rows[0]['paid']);
        $this->assertSame($deliveredMrp, $rows[0]['mrp_total']);
        $this->assertCount(1, $this->treasuryRows(['status' => 'partly_delivered']), 'The partly delivered filter finds it.');

        $partner = OrderMoneyReport::deliveredOrders()->where('o.user_id', $this->dealer->id)
            ->selectRaw(OrderMoneyReport::mrpSql().' AS ordered_mrp, '.OrderMoneyReport::cashGivenSql().' AS cash_given')->first();
        $this->assertSame($deliveredMrp, Money::decimal(Money::cents((string) $partner->ordered_mrp)));
        $this->assertSame($deliveredValue, Money::decimal(Money::cents((string) $partner->cash_given)));

        $this->assertSame('waiting_rest', app(OrderMoneyReport::class)->detail($order, true)['posting']);
    }

    public function test_the_statements_count_the_delivered_parcel_and_keep_the_other_in_transit(): void
    {
        $this->deliverParcel(0);
        $order = $this->order->fresh();
        $unitCents = Money::cents((string) $order->lines()->value('unit_price'));

        $statement = app(AccountingStatements::class)->build('2026-11-08', '2026-11-08');

        $this->assertSame(Money::decimal($unitCents * 3), $this->amount($statement['profit_loss']['income'], 'product_sales'));
        $this->assertSame(Money::decimal($unitCents * 2), $this->amount($statement['balance_sheet']['liabilities'], 'paid_not_delivered'));
        $this->assertSame('110.00', $this->amount($statement['balance_sheet']['assets'], 'goods_in_transit'), 'Only the 2 units on the road, at 55 each.');
        $this->assertSame('0.00', $statement['balance_sheet']['difference']);
    }

    public function test_commission_waits_for_the_whole_order_and_posts_once(): void
    {
        $this->deliverParcel(0);
        $this->assertSame(0, DB::table('commission_batches')->where('order_id', $this->order->id)->count(), 'No commission on a parcel while goods are still to come.');

        $this->deliverParcel(1);
        $order = $this->order->fresh();
        $this->assertSame('delivered', $order->status);
        $this->assertSame((string) $order->total, DeliveredMoney::valueOf($order));
        $batch = DB::table('commission_batches')->where('order_id', $order->id)->sole();
        $this->assertSame(Money::cents((string) $order->total), Money::cents((string) $batch->cash_basis));
        $allocations = DB::table('commission_allocations')->where('order_id', $order->id)->count();

        $this->deliverParcel(1);
        $this->assertSame(1, DB::table('commission_batches')->where('order_id', $order->id)->count());
        $this->assertSame($allocations, DB::table('commission_allocations')->where('order_id', $order->id)->count());
    }

    public function test_salary_and_application_rows_keep_their_text_ids_when_opened(): void
    {
        foreach (['Payroll', 'DealerApplicationsView'] as $file) {
            $source = (string) file_get_contents(resource_path("js/components/office/sections/{$file}.tsx"));
            $this->assertStringNotContainsString('setOpenedId(Number(row.id))', $source, $file);
            $this->assertStringContainsString('setOpenedId(String(row.id))', $source, $file);
            $this->assertStringContainsString('String(row.id) === openedId', $source, $file);
        }
    }

    private function deliverParcel(int $position): void
    {
        $parcel = (int) DB::table('shipments')->where('order_id', $this->order->id)->orderBy('id')->offset($position)->value('id');
        app(OrderService::class)->deliver($this->owner, $this->order->fresh(), $parcel, 'Received by dealer');
    }

    /**
     * @param  array<string, string>  $query
     * @return list<array<string, mixed>>
     */
    private function treasuryRows(array $query = []): array
    {
        $rows = app(OrderMoneyReport::class)->table(Request::create('/treasury/orders', 'GET', $query), null)['rows'];

        return array_values($rows->items());
    }

    /** @param  list<array<string, mixed>>  $lines */
    private function amount(array $lines, string $account): ?string
    {
        return collect($lines)->firstWhere('account', $account)['amount'] ?? null;
    }
}
