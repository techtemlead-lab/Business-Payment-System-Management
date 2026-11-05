<?php

namespace Tests\Feature;

use App\Domain\Orders\OverdueDeliveries;
use App\Domain\Orders\StaffDashboardSummary;
use App\Models\Order;
use App\Models\User;
use Carbon\CarbonImmutable;
use DateTimeInterface;
use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\TestCase;

class OverdueDeliveriesTest extends TestCase
{
    use RefreshDatabase;

    private User $sender;

    protected function setUp(): void
    {
        parent::setUp();
        $this->travelTo(CarbonImmutable::parse('2026-11-05 11:00:00', 'Asia/Dhaka'));
        $this->sender = User::factory()->staff('inventory')->create();
    }

    public function test_order_sent_eight_days_ago_is_overdue_but_six_days_is_not(): void
    {
        $dealer = User::factory()->dealer()->create();
        $late = $this->sentOrder($dealer, 'dispatched', now()->subDays(8));
        $this->sentOrder($dealer, 'partially_dispatched', now()->subDays(6));

        $this->assertSame(1, app(OverdueDeliveries::class)->count());
        $this->assertSame([$late->id], app(OverdueDeliveries::class)->apply(Order::query())->pluck('id')->all());
    }

    public function test_delivered_orders_are_never_overdue(): void
    {
        $dealer = User::factory()->dealer()->create();
        $this->sentOrder($dealer, 'delivered', now()->subDays(20));

        $this->assertSame(0, app(OverdueDeliveries::class)->count());
    }

    public function test_only_the_latest_shipment_counts(): void
    {
        $dealer = User::factory()->dealer()->create();
        $order = $this->sentOrder($dealer, 'partially_dispatched', now()->subDays(12));
        $this->shipment($order, now()->subDays(2), 'Pathao', 'PT-2');

        $this->assertSame(0, app(OverdueDeliveries::class)->count());
    }

    public function test_days_are_counted_on_the_dhaka_calendar(): void
    {
        // 01:00 on 5 November in Dhaka is still 4 November in UTC.
        $this->travelTo(CarbonImmutable::parse('2026-11-05 01:00:00', 'Asia/Dhaka'));
        $dealer = User::factory()->dealer()->create();
        // 23:30 on 28 October in Dhaka: 8 Dhaka days ago, but only 7 UTC days ago.
        $this->sentOrder($dealer, 'dispatched', CarbonImmutable::parse('2026-10-28 23:30:00', 'Asia/Dhaka'));

        $this->assertSame(1, app(OverdueDeliveries::class)->count());
    }

    public function test_office_home_shows_the_overdue_count_to_staff_with_orders_or_inventory(): void
    {
        $dealer = User::factory()->dealer()->create();
        $this->sentOrder($dealer, 'dispatched', now()->subDays(9));

        foreach (['super_admin', 'inventory', 'support'] as $role) {
            $item = $this->queueItem(User::factory()->staff($role)->create());
            $this->assertNotNull($item, "{$role} should see overdue deliveries");
            $this->assertSame(1, $item['count']);
            $this->assertSame('deliveries', $item['section']);
            $this->assertSame(['status' => 'overdue_delivery'], $item['query']);
        }
    }

    public function test_staff_without_orders_or_inventory_do_not_see_the_item(): void
    {
        $dealer = User::factory()->dealer()->create();
        $this->sentOrder($dealer, 'dispatched', now()->subDays(9));

        $this->assertNull($this->queueItem(User::factory()->staff('editor')->create()));
    }

    public function test_deliveries_list_filter_shows_order_dealer_days_and_courier(): void
    {
        $owner = User::factory()->staff()->create();
        $dealer = User::factory()->dealer()->create(['name' => 'Rahim Traders']);
        $late = $this->sentOrder($dealer, 'dispatched', now()->subDays(8), 'Steadfast', 'SF-123');
        $this->sentOrder($dealer, 'dispatched', now()->subDays(3));
        $this->sentOrder($dealer, 'delivered', now()->subDays(30));

        $this->actingAs($owner)->onPortal('sadmin')
            ->get('/deliveries?status=overdue_delivery')
            ->assertOk()
            ->assertInertia(fn ($page) => $page
                ->has('data.orders.data', 1)
                ->where('data.orders.data.0.id', $late->id)
                ->where('data.orders.data.0.number', $late->number)
                ->where('data.orders.data.0.user.name', 'Rahim Traders')
                ->where('data.orders.data.0.days_since_sent', 8)
                ->where('data.orders.data.0.last_courier', 'Steadfast')
                ->where('data.orders.data.0.last_tracking', 'SF-123'));
    }

    public function test_command_posts_one_notice_per_eligible_staff_member_in_their_language(): void
    {
        $owner = User::factory()->staff()->create();
        $support = User::factory()->staff('support')->create(['locale' => 'bn']);
        $editor = User::factory()->staff('editor')->create();
        $dealer = User::factory()->dealer()->create();
        $this->sentOrder($dealer, 'dispatched', now()->subDays(8));
        $this->sentOrder($dealer, 'partially_dispatched', now()->subDays(10));
        $this->sentOrder($dealer, 'dispatched', now()->subDays(1));

        $this->artisan('orders:delivery-reminders')->assertSuccessful();

        foreach ([$owner, $support, $this->sender] as $user) {
            $this->assertSame(1, DB::table('account_notices')->where('user_id', $user->id)->count());
        }
        $this->assertSame(0, DB::table('account_notices')->where('user_id', $editor->id)->count());
        $this->assertSame(0, DB::table('account_notices')->where('user_id', $dealer->id)->count());
        $ownerNotice = DB::table('account_notices')->where('user_id', $owner->id)->first();
        $this->assertSame(__('portal.notice_deliveries_overdue_title', [], 'en'), $ownerNotice->title);
        $this->assertStringContainsString('2 orders were sent more than 7 days ago', $ownerNotice->body);
        $supportNotice = DB::table('account_notices')->where('user_id', $support->id)->first();
        $this->assertSame(__('portal.notice_deliveries_overdue_title', [], 'bn'), $supportNotice->title);
    }

    public function test_command_sends_nothing_when_no_delivery_is_overdue(): void
    {
        $dealer = User::factory()->dealer()->create();
        $this->sentOrder($dealer, 'dispatched', now()->subDays(6));

        $this->artisan('orders:delivery-reminders')->assertSuccessful();

        $this->assertSame(0, DB::table('account_notices')->count());
    }

    public function test_command_never_changes_orders_or_shipments(): void
    {
        $dealer = User::factory()->dealer()->create();
        $order = $this->sentOrder($dealer, 'dispatched', now()->subDays(9));

        $this->artisan('orders:delivery-reminders')->assertSuccessful();

        $this->assertSame('dispatched', $order->fresh()->status);
        $this->assertSame(0, DB::table('shipments')->whereNotNull('delivered_at')->count());
        $this->assertSame(0, DB::table('journal_entries')->count());
    }

    public function test_command_is_scheduled_daily_at_ten_in_dhaka(): void
    {
        $event = collect(app(Schedule::class)->events())
            ->first(fn ($event) => str_contains((string) $event->command, 'orders:delivery-reminders'));

        $this->assertNotNull($event);
        $this->assertSame('0 10 * * *', $event->expression);
        $this->assertSame('Asia/Dhaka', $event->timezone);
        $this->assertTrue($event->withoutOverlapping);
        $this->assertTrue($event->onOneServer);
    }

    /**
     * @return array{key: string, count: int, section: string, query?: array<string, string>}|null
     */
    private function queueItem(User $viewer): ?array
    {
        return collect(app(StaffDashboardSummary::class)->for($viewer)['work_queue'])
            ->firstWhere('key', 'deliveries_overdue');
    }

    private function sentOrder(User $buyer, string $status, DateTimeInterface $sentAt, string $courier = 'Sundarban', string $tracking = 'TRK-1'): Order
    {
        $order = Order::create([
            'number' => 'ORD-'.Str::upper(Str::random(8)),
            'user_id' => $buyer->id,
            'status' => $status,
            'total' => '500.00',
            'paid' => '0.00',
            'idempotency_key' => (string) Str::uuid(),
            'payload_hash' => hash('sha256', Str::random()),
        ]);
        $this->shipment($order, $sentAt, $courier, $tracking);

        return $order;
    }

    private function shipment(Order $order, DateTimeInterface $sentAt, string $courier, string $tracking): void
    {
        DB::table('shipments')->insert([
            'order_id' => $order->id,
            'courier' => $courier,
            'tracking' => $tracking,
            'status' => 'dispatched',
            'lines' => json_encode([]),
            'actor_id' => $this->sender->id,
            'created_at' => CarbonImmutable::instance($sentAt)->utc(),
            'updated_at' => CarbonImmutable::instance($sentAt)->utc(),
        ]);
    }
}
