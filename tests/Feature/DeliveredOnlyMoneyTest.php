<?php

namespace Tests\Feature;

use App\Domain\Accounts\PartnerDashboardHome;
use App\Domain\Accounts\TeamOrdersReport;
use App\Domain\Finance\ReportData;
use App\Domain\Inventory\BranchPerformance;
use App\Domain\Orders\DeliveredMoney;
use App\Domain\Orders\OrderOverviewTotals;
use App\Domain\Orders\OrderService;
use App\Models\CompanyBranch;
use App\Models\Order;
use App\Models\Product;
use App\Models\ReferralTreePath;
use App\Models\User;
use Database\Seeders\CommissionRuleSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\TestCase;

/**
 * Six orders of one dealer, in taka:
 *  unpaid and waiting ৳2,000, approved and paid ৳4,000, delivered ৳6,000,
 *  partly delivered ৳10,000 of which ৳6,000 arrived, rejected ৳2,000, cancelled ৳2,000.
 * Only ৳6,000 + ৳6,000 = ৳12,000 of goods were delivered.
 */
class DeliveredOnlyMoneyTest extends TestCase
{
    use RefreshDatabase;

    private User $owner;

    private User $leader;

    private User $dealer;

    private Product $product;

    private CompanyBranch $hub;

    private Order $partly;

    private Order $rejected;

    private Order $cancelled;

    protected function setUp(): void
    {
        parent::setUp();
        $this->withoutVite();
        Http::fake();
        $this->enableFinancialPosting();
        $this->seed(CommissionRuleSeeder::class);
        $this->hub = CompanyBranch::query()->where('code', 'HO-PIRGANJ')->firstOrFail();
        $this->owner = User::factory()->staff('super_admin')->create(['active_branch_id' => $this->hub->id]);
        $this->leader = User::factory()->dealer()->create(['status' => 'active']);
        ReferralTreePath::attachUser($this->leader->id, null);
        $this->dealer = User::factory()->dealer()->create(['status' => 'active', 'sponsor_id' => $this->leader->id, 'division' => 'Dhaka', 'district' => 'Gazipur']);
        ReferralTreePath::attachUser($this->dealer->id, $this->leader->id);
        $this->product = Product::factory()->create(['active' => true, 'mrp' => '3000.00', 'dealer_price' => '2000.00', 'stock' => 100, 'category' => 'Herbal']);

        $this->order(1, 'submitted', false);
        $this->order(2, 'approved', true);
        $this->order(3, 'delivered', true);
        $this->partly = $this->order(5, 'partly_delivered', true);
        $line = $this->partly->lines()->where('is_free', false)->firstOrFail();
        foreach (['delivered' => 3, 'returned' => 2] as $status => $quantity) {
            DB::table('order_lines')->where('id', $line->id)->increment('dispatched', $quantity);
            DB::table('shipments')->insert(['order_id' => $this->partly->id, 'courier' => 'Test', 'tracking' => 'T-'.Str::random(6), 'status' => $status, 'courier_status' => $status,
                'lines' => json_encode([['line_id' => $line->id, 'name' => 'Item', 'quantity' => $quantity, 'lots' => []]], JSON_THROW_ON_ERROR),
                'actor_id' => $this->owner->id, 'idempotency_key' => (string) Str::uuid(), 'payload_hash' => hash('sha256', (string) Str::uuid()), 'created_at' => now(), 'updated_at' => now()]);
        }
        $this->rejected = $this->order(1, 'rejected', false, 'Payment proof was not clear');
        $this->cancelled = $this->order(1, 'cancelled', true, 'Parcel came back to the branch');
    }

    public function test_one_order_counts_its_delivered_goods(): void
    {
        $this->assertSame('6000.00', DeliveredMoney::valueOf($this->partly));
        $this->assertSame('0.00', DeliveredMoney::valueOf($this->rejected));
        $this->assertSame('0.00', DeliveredMoney::valueOf($this->cancelled));
    }

    public function test_the_orders_list_totals_and_rows_count_delivered_goods_only(): void
    {
        $this->actingAs($this->owner)->onPortal('sadmin')->get('/orders')->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->where('data.order_totals.orders', 6)
                ->where('data.order_totals.value', '12000.00')
                ->where('data.order_totals.paid', '12000.00'));

        $this->actingAs($this->dealer)->onPortal('dealer')->get('/orders?status=partly_delivered')->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->where('data.orders.data.0.delivered_value', '6000.00')
                ->where('data.orders.data.0.delivered_paid', '6000.00')
                ->where('data.order_totals.value', '6000.00'));
    }

    public function test_the_orders_report_lists_every_order_but_totals_delivered_goods_only(): void
    {
        $report = $this->report('orders');
        $rows = $report['query']->get();

        $this->assertCount(6, $rows);
        $this->assertSame(['delivered_value'], $report['totals']);
        $this->assertEqualsWithDelta(12000.0, $rows->sum(fn (object $row): float => (float) $row->delivered_value), 0.001);
    }

    public function test_product_reports_count_the_delivered_units_of_a_partly_delivered_order(): void
    {
        $rows = $this->report('products')['query']->get();

        $this->assertSame(['3', '3'], $rows->map(fn (object $row): string => (string) $row->quantity)->sort()->values()->all());
        $this->assertEqualsWithDelta(12000.0, $rows->sum(fn (object $row): float => (float) $row->total), 0.001);

        foreach (['product-summary', 'category-summary'] as $type) {
            $row = $this->report($type)['query']->first();
            $this->assertSame('2', (string) $row->order_count, $type);
            $this->assertSame('6', (string) $row->quantity, $type);
            $this->assertEqualsWithDelta(12000.0, (float) $row->gross_sales, 0.001, $type);
        }
    }

    public function test_the_district_report_waits_for_delivery(): void
    {
        $row = $this->report('district-summary')['query']->first();

        $this->assertSame('2', (string) $row->order_count);
        $this->assertEqualsWithDelta(12000.0, (float) $row->gross_sales, 0.001);
        $this->assertEqualsWithDelta(12000.0, (float) $row->paid, 0.001);
    }

    public function test_home_screen_figures_wait_for_delivery(): void
    {
        $orders = Order::query()->where('user_id', $this->dealer->id);

        $this->assertSame('12000.00', app(OrderOverviewTotals::class)->for($orders, now())['purchases']);
        $this->assertSame('12000.00', app(PartnerDashboardHome::class)->for($this->dealer)['snapshot']['own_sales_today']);
    }

    public function test_the_branch_report_waits_for_delivery(): void
    {
        DB::table('orders')->update(['fulfillment_branch_id' => $this->hub->id]);

        $totals = app(BranchPerformance::class)->summary($this->owner, ['period' => 'month'])['totals'];

        $this->assertSame(2, $totals['order_count']);
        $this->assertSame('12000.00', $totals['order_value']);
        $this->assertSame('12000.00', $totals['income']);
    }

    public function test_the_team_report_totals_wait_for_delivery(): void
    {
        $totals = app(TeamOrdersReport::class)->totals($this->leader, ['from' => now()->subDay()->toDateString(), 'to' => now()->addDay()->toDateString(), 'member' => null, 'status' => null]);

        $this->assertSame('12000.00', $totals['ordered']);
        $this->assertSame('12000.00', $totals['paid']);
    }

    public function test_every_report_says_what_it_counts_in_both_languages(): void
    {
        foreach (['orders', 'products', 'product_summary', 'category_summary', 'district_summary'] as $report) {
            $this->assertStringContainsString('partly delivered', __('portal.ops_total_note_'.$report, [], 'en'), $report);
            $this->assertStringContainsString('আংশিক ডেলিভারি', __('portal.ops_total_note_'.$report, [], 'bn'), $report);
        }
    }

    public function test_cancelled_rejected_and_returned_orders_stay_on_the_dealers_order_page_with_the_reason(): void
    {
        $this->actingAs($this->dealer)->onPortal('dealer')->get('/orders')->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->where('data.order_status_counts.rejected', 1)
                ->where('data.order_status_counts.cancelled', 1));

        $this->actingAs($this->dealer)->onPortal('dealer')->get('/orders?status=rejected')->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->has('data.orders.data', 1)
                ->where('data.orders.data.0.id', $this->rejected->id)
                ->where('data.orders.data.0.decision_reason', 'Payment proof was not clear')
                ->where('data.order_totals.value', '0.00')
                ->where('data.order_totals.paid', '0.00'));

        $this->actingAs($this->dealer)->onPortal('dealer')->get('/orders?status=cancelled')->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->has('data.orders.data', 1)
                ->where('data.orders.data.0.decision_reason', 'Parcel came back to the branch')
                ->where('data.order_totals.value', '0.00')
                ->where('data.order_totals.paid', '0.00'));
    }

    public function test_cancelled_and_rejected_orders_stay_on_the_admins_order_page_with_the_reason(): void
    {
        foreach (['rejected' => 'Payment proof was not clear', 'cancelled' => 'Parcel came back to the branch'] as $status => $reason) {
            $this->actingAs($this->owner)->onPortal('sadmin')->get('/orders?status='.$status)->assertOk()
                ->assertInertia(fn (Assert $page) => $page
                    ->has('data.orders.data', 1)
                    ->where('data.orders.data.0.decision_reason', $reason)
                    ->where('data.order_totals.value', '0.00'));
        }
    }

    public function test_the_list_offers_a_rejected_tab_even_before_any_order_is_rejected(): void
    {
        $portal = (string) file_get_contents(resource_path('js/pages/Portal.tsx'));

        $this->assertMatchesRegularExpression("/const orderStatuses=\[[^\]]*'rejected'[^\]]*'cancelled'\]/", $portal);
    }

    /** @return array<string, mixed> */
    private function report(string $type): array
    {
        return app(ReportData::class)->query($this->owner, $type, now('Asia/Dhaka')->subDay()->toDateString(), now('Asia/Dhaka')->addDay()->toDateString());
    }

    private function order(int $quantity, string $status, bool $paid, ?string $reason = null): Order
    {
        $order = app(OrderService::class)->create($this->dealer, ['items' => [['product_id' => $this->product->id, 'quantity' => $quantity]], 'idempotency_key' => (string) Str::uuid()]);
        DB::table('orders')->where('id', $order->id)->update(['status' => $status, 'paid' => $paid ? $order->total : '0.00', 'decision_reason' => $reason]);

        return $order->fresh();
    }
}
