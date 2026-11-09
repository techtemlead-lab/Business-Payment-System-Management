<?php

namespace Tests\Feature;

use App\Domain\Orders\StaffDashboardSummary;
use App\Models\Order;
use App\Models\PaymentClaim;
use App\Models\Product;
use App\Models\Receipt;
use App\Models\User;
use DateTimeInterface;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\TestCase;

class StaffDashboardSummaryTest extends TestCase
{
    use RefreshDatabase;

    public function test_owner_home_includes_work_queue_and_two_week_trend(): void
    {
        $owner = User::factory()->staff()->create();
        $dealer = User::factory()->dealer()->create();
        $this->order($dealer, 'submitted', '500.00');
        $this->order($dealer, 'approved', '1200.00');
        $this->order($dealer, 'delivered', '300.00');

        $this->actingAs($owner)->onPortal('sadmin')
            ->get('/')
            ->assertOk()
            ->assertInertia(fn ($page) => $page
                ->has('data.admin_home.work_queue')
                ->has('data.admin_home.trend', StaffDashboardSummary::TrendDays)
                ->where('data.admin_home.month.sales', '300.00')
                ->where('data.admin_home.month.orders', 1));
    }

    public function test_trend_counts_only_delivered_goods_on_the_day_they_arrived(): void
    {
        $this->travelTo('2026-11-20 12:00:00');
        $owner = User::factory()->staff()->create();
        $dealer = User::factory()->dealer()->create();
        $this->order($dealer, 'approved', '1200.00');
        $this->order($dealer, 'dispatched', '700.00');
        $this->order($dealer, 'rejected', '999.00');
        $placedEarlier = $this->order($dealer, 'delivered', '300.00', placedDaysAgo: 9);
        $this->parcelArrived($placedEarlier, now()->subDays(3));
        $this->parcelArrived($placedEarlier, now());
        $arrivedEarlier = $this->order($dealer, 'delivered', '450.00', placedDaysAgo: 9);
        $this->parcelArrived($arrivedEarlier, now()->subDays(5));

        $trend = collect(app(StaffDashboardSummary::class)->for($owner)['trend'])->keyBy('date');

        $this->assertSame(['date' => '2026-11-20', 'orders' => 1, 'value' => '300.00'], $trend['2026-11-20']);
        $this->assertSame(['date' => '2026-11-15', 'orders' => 1, 'value' => '450.00'], $trend['2026-11-15']);
        $this->assertSame(['date' => '2026-11-17', 'orders' => 0, 'value' => '0.00'], $trend['2026-11-17']);
        $this->assertSame(2, $trend->sum('orders'));
    }

    public function test_a_partly_delivered_order_counts_only_the_parcels_that_arrived(): void
    {
        $this->travelTo('2026-11-20 12:00:00');
        $owner = User::factory()->staff()->create();
        $dealer = User::factory()->dealer()->create();
        $order = $this->order($dealer, 'partly_delivered', '1000.00');
        $line = $order->lines()->create(['product_id' => Product::factory()->create()->id, 'sku' => 'SKU-1', 'name' => 'Item', 'quantity' => 10, 'dispatched' => 10, 'unit_price' => '100.00', 'mrp' => '150.00', 'total' => '1000.00']);
        $this->parcelArrived($order, now(), [['line_id' => $line->id, 'name' => 'Item', 'quantity' => 4, 'lots' => []]]);

        $summary = app(StaffDashboardSummary::class)->for($owner);

        $this->assertSame('400.00', end($summary['trend'])['value']);
        $this->assertSame('400.00', $summary['month']['sales']);
        $this->assertSame('400.00', $summary['month']['delivered_today']);
    }

    public function test_the_month_counts_goods_delivered_in_it_whenever_they_were_ordered(): void
    {
        $this->travelTo('2026-11-20 12:00:00');
        $owner = User::factory()->staff()->create();
        $dealer = User::factory()->dealer()->create();
        $this->parcelArrived($this->order($dealer, 'delivered', '200.00', placedDaysAgo: 30), now()->subDays(2));
        $this->parcelArrived($this->order($dealer, 'delivered', '900.00', placedDaysAgo: 40), now()->subDays(25));
        $this->order($dealer, 'approved', '5000.00');

        $month = app(StaffDashboardSummary::class)->for($owner)['month'];

        $this->assertSame('200.00', $month['sales']);
        $this->assertSame(1, $month['orders']);
        $this->assertSame('0.00', $month['delivered_today']);
    }

    public function test_imported_opening_balance_orders_are_not_todays_sales(): void
    {
        $owner = User::factory()->staff()->create();
        $order = $this->order(User::factory()->dealer()->create(), 'delivered', '8000.00');
        DB::table('orders')->where('id', $order->id)->update(['is_opening_balance' => true]);

        $summary = app(StaffDashboardSummary::class)->for($owner);

        $this->assertSame('0.00', end($summary['trend'])['value']);
        $this->assertSame('0.00', $summary['month']['sales']);
    }

    public function test_work_queue_only_lists_work_the_viewer_may_do(): void
    {
        $manager = User::factory()->staff('account_manager')->create();

        $keys = array_column(app(StaffDashboardSummary::class)->for($manager)['work_queue'], 'key');

        $this->assertContains('orders_to_review', $keys);
        $this->assertContains('applications_to_review', $keys);
        $this->assertNotContains('payments_to_check', $keys);
        $this->assertNotContains('withdrawals_to_review', $keys);
    }

    public function test_money_received_before_delivery_is_not_counted(): void
    {
        $owner = User::factory()->staff()->create();
        $dealer = User::factory()->dealer()->create();
        $this->receipt($dealer, '420.00');

        $month = app(StaffDashboardSummary::class)->for($owner)['month'];

        $this->assertSame('0.00', $month['delivered_today']);
        $this->assertSame('0.00', $month['sales']);
    }

    public function test_delivered_today_is_the_value_of_goods_that_arrived_today(): void
    {
        $this->travelTo('2026-11-20 12:00:00');
        $owner = User::factory()->staff()->create();
        $dealer = User::factory()->dealer()->create();
        $this->parcelArrived($this->order($dealer, 'delivered', '640.00', placedDaysAgo: 4), now());
        $this->parcelArrived($this->order($dealer, 'delivered', '310.00', placedDaysAgo: 4), now()->subDay());

        $this->assertSame('640.00', app(StaffDashboardSummary::class)->for($owner)['month']['delivered_today']);
    }

    /**
     * @param  list<array{line_id: int, name: string, quantity: int, lots: list<int>}>  $lines
     */
    private function parcelArrived(Order $order, DateTimeInterface $arrivedAt, array $lines = []): void
    {
        DB::table('shipments')->insert([
            'order_id' => $order->id,
            'courier' => 'Test',
            'tracking' => 'T-'.Str::random(6),
            'status' => 'delivered',
            'courier_status' => 'delivered',
            'lines' => json_encode($lines, JSON_THROW_ON_ERROR),
            'actor_id' => $order->user_id,
            'idempotency_key' => (string) Str::uuid(),
            'payload_hash' => hash('sha256', (string) Str::uuid()),
            'delivered_at' => $arrivedAt,
            'created_at' => $arrivedAt,
            'updated_at' => $arrivedAt,
        ]);
    }

    private function receipt(User $buyer, string $amount): Receipt
    {
        $order = $this->order($buyer, 'approved', $amount);
        $claim = PaymentClaim::create([
            'order_id' => $order->id,
            'user_id' => $buyer->id,
            'amount' => $amount,
            'channel' => 'bank',
            'reference' => 'REF-'.Str::random(8),
            'status' => 'verified',
        ]);

        return Receipt::create([
            'order_id' => $order->id,
            'claim_id' => $claim->id,
            'channel' => 'bank',
            'reference' => 'RCP-'.Str::random(8),
            'amount' => $amount,
        ]);
    }

    private function order(User $buyer, string $status, string $total, int $placedDaysAgo = 0): Order
    {
        $order = Order::create([
            'number' => 'ORD-'.Str::upper(Str::random(8)),
            'user_id' => $buyer->id,
            'status' => $status,
            'total' => $total,
            'paid' => '0.00',
            'idempotency_key' => (string) Str::uuid(),
            'payload_hash' => hash('sha256', Str::random()),
        ]);
        DB::table('orders')->where('id', $order->id)->update(['created_at' => now()->subDays($placedDaysAgo)]);

        return $order->fresh();
    }
}
