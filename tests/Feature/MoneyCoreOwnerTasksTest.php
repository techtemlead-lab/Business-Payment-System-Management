<?php

namespace Tests\Feature;

use App\Domain\Finance\MonthCloseStatus;
use App\Domain\Integrations\IntegrationSettings;
use App\Models\Order;
use App\Models\Product;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\TestCase;

/**
 * MA-14 (one step starts the books), MA-24 (money received, waiting for delivery) and the
 * commission tasks on the owner home and the Money tasks list (MA-15, MA-03, failed month close).
 */
class MoneyCoreOwnerTasksTest extends TestCase
{
    use RefreshDatabase;

    private const Reason = 'Nothing to bring in, starting from zero';

    private User $owner;

    protected function setUp(): void
    {
        parent::setUp();
        $this->withoutVite();
        $this->owner = User::factory()->staff('super_admin')->create();
    }

    public function test_the_start_step_shows_to_the_owner_until_it_is_done_and_the_button_really_starts_the_books(): void
    {
        $this->actingAs($this->owner)->onPortal('sadmin')->get('/')->assertOk()
            ->assertInertia(fn (Assert $page) => $page->where('data.admin_home.start_books', true));
        $this->actingAs($this->owner)->onPortal('sadmin')->get('/operations/data-imports')
            ->assertInertia(fn (Assert $page) => $page->where('ownerCanStart', true)->where('startup.mode', null));

        $this->actingAs($this->owner)->onPortal('sadmin')->post('/commands/operations/opening-balances/fresh-start', ['reason' => self::Reason, 'self_approve' => true])->assertRedirect();

        $this->assertDatabaseHas('financial_verifications', ['fresh_start' => true]);
        $this->assertSame(1, DB::table('financial_verifications')->whereNotNull('verified_at')->count());
        $this->assertSame('fresh', app(IntegrationSettings::class)->startupMode()['mode']);
        $this->assertDatabaseHas('audit_events', ['action' => 'opening_balances.fresh_start']);
        $this->assertDatabaseHas('audit_events', ['action' => 'finance.startup_mode_changed']);
        $this->actingAs($this->owner)->onPortal('sadmin')->get('/')
            ->assertInertia(fn (Assert $page) => $page->where('data.admin_home.start_books', false));
        $this->actingAs($this->owner)->onPortal('sadmin')->get('/operations/data-imports')
            ->assertInertia(fn (Assert $page) => $page->where('ownerCanStart', false)->where('startup.mode', 'fresh'));
    }

    public function test_only_the_owner_sees_the_step_or_can_press_it(): void
    {
        $finance = User::factory()->staff('finance')->create();

        $this->actingAs($finance)->onPortal('manage')->get('/')->assertOk()
            ->assertInertia(fn (Assert $page) => $page->where('data.admin_home.start_books', false));
        $this->actingAs($finance)->onPortal('manage')->post('/commands/operations/opening-balances/fresh-start', ['reason' => self::Reason, 'self_approve' => true])->assertForbidden();
        $this->assertDatabaseCount('financial_verifications', 0);
    }

    public function test_the_step_needs_the_confirmation_tick(): void
    {
        $this->actingAs($this->owner)->onPortal('sadmin')->post('/commands/operations/opening-balances/fresh-start', ['reason' => self::Reason, 'self_approve' => false])->assertSessionHasErrors();

        $this->assertDatabaseCount('financial_verifications', 0);
        $this->assertNull(app(IntegrationSettings::class)->startupMode()['mode']);
    }

    public function test_treasury_shows_money_received_for_orders_not_delivered_yet(): void
    {
        $dealer = User::factory()->dealer()->create();
        $this->order($dealer, 'delivered', '1000.00', '1000.00');
        $this->order($dealer, 'approved', '1000.00', '600.00');
        $this->order($dealer, 'dispatched', '1000.00', '400.00');
        $this->order($dealer, 'cancelled', '1000.00', '0.00');

        $this->actingAs($this->owner)->onPortal('sadmin')->get('/treasury/funds')->assertOk()
            ->assertInertia(fn (Assert $page) => $page->component('TreasuryFunds')->where('waiting_for_delivery', '1000.00'));
    }

    public function test_a_part_delivered_dispatched_order_keeps_only_the_undelivered_part_waiting(): void
    {
        $dealer = User::factory()->dealer()->create();
        $order = $this->order($dealer, 'dispatched', '1000.00', '1000.00');
        $line = $order->lines()->create(['product_id' => Product::factory()->create()->id, 'sku' => 'SKU-1', 'name' => 'Item', 'quantity' => 10, 'dispatched' => 10, 'unit_price' => '100.00', 'mrp' => '150.00', 'total' => '1000.00']);
        DB::table('shipments')->insert(['order_id' => $order->id, 'courier' => 'Test', 'tracking' => 'T-1', 'status' => 'delivered', 'courier_status' => 'delivered',
            'lines' => json_encode([['line_id' => (int) $line->getKey(), 'name' => 'Item', 'quantity' => 4, 'lots' => []]], JSON_THROW_ON_ERROR), 'actor_id' => $dealer->id,
            'idempotency_key' => (string) Str::uuid(), 'payload_hash' => hash('sha256', (string) Str::uuid()), 'delivered_at' => now(), 'created_at' => now(), 'updated_at' => now()]);

        $this->actingAs($this->owner)->onPortal('sadmin')->get('/treasury/funds')
            ->assertInertia(fn (Assert $page) => $page->where('waiting_for_delivery', '600.00'));
    }

    public function test_commission_tasks_show_with_count_and_amount_on_the_money_tasks_list_and_the_home(): void
    {
        $partner = User::factory()->dealer()->create();
        $order = $this->order($partner, 'delivered', '1000.00', '1000.00');
        $this->allocation($order, $partner, 'maturing', '30.00', now()->subDay());
        $this->allocation($order, $partner, 'maturing', '20.00', now()->subHours(2));
        $this->allocation($order, $partner, 'maturing', '99.00', now()->addDays(5));
        $this->allocation($order, $partner, 'held', '70.00');
        app(MonthCloseStatus::class)->recordFailure('salary', '2026-09', 'The close stopped');

        $this->actingAs($this->owner)->onPortal('sadmin')->get('/treasury/work')->assertOk()
            ->assertInertia(function (Assert $page): void {
                $tiles = collect($page->toArray()['props']['tiles'])->keyBy('key');
                $this->assertSame(2, $tiles['ready_to_pay']['count']);
                $this->assertSame('50.00', $tiles['ready_to_pay']['amount']);
                $this->assertSame('/settlements', $tiles['ready_to_pay']['href']);
                $this->assertSame(1, $tiles['holds']['count']);
                $this->assertSame('70.00', $tiles['holds']['amount']);
                $this->assertSame(1, $tiles['month_close_failed']['count']);
                $this->assertTrue($tiles['month_close_failed']['alert']);
                $this->assertSame('/settlements', $tiles['month_close_failed']['href']);
            });

        $this->actingAs($this->owner)->onPortal('sadmin')->get('/')
            ->assertInertia(function (Assert $page): void {
                $queue = collect($page->toArray()['props']['data']['admin_home']['work_queue'])->keyBy('key');
                $this->assertSame([2, '50.00'], [$queue['commission_ready_to_pay']['count'], $queue['commission_ready_to_pay']['amount']]);
                $this->assertSame([1, '70.00'], [$queue['commission_held']['count'], $queue['commission_held']['amount']]);
                $this->assertTrue($queue['month_close_failed']['alert']);
            });
    }

    public function test_the_commission_tasks_are_hidden_when_there_is_nothing_to_do(): void
    {
        $partner = User::factory()->dealer()->create();
        $order = $this->order($partner, 'delivered', '1000.00', '1000.00');
        $this->allocation($order, $partner, 'maturing', '99.00', now()->addDays(5));

        $this->actingAs($this->owner)->onPortal('sadmin')->get('/treasury/work')
            ->assertInertia(function (Assert $page): void {
                $keys = collect($page->toArray()['props']['tiles'])->pluck('key')->all();
                $this->assertNotContains('ready_to_pay', $keys);
                $this->assertNotContains('holds', $keys);
                $this->assertNotContains('month_close_failed', $keys);
            });
        $this->actingAs($this->owner)->onPortal('sadmin')->get('/')
            ->assertInertia(function (Assert $page): void {
                $keys = collect($page->toArray()['props']['data']['admin_home']['work_queue'])->pluck('key')->all();
                $this->assertNotContains('commission_ready_to_pay', $keys);
                $this->assertNotContains('commission_held', $keys);
                $this->assertNotContains('month_close_failed', $keys);
            });
    }

    private function order(User $buyer, string $status, string $total, string $paid): Order
    {
        return Order::create([
            'number' => 'ORD-'.Str::upper(Str::random(8)),
            'user_id' => $buyer->id,
            'status' => $status,
            'total' => $total,
            'paid' => $paid,
            'idempotency_key' => (string) Str::uuid(),
            'payload_hash' => hash('sha256', Str::random()),
        ]);
    }

    private function allocation(Order $order, User $partner, string $status, string $amount, ?\DateTimeInterface $dueAt = null): int
    {
        return DB::table('commission_allocations')->insertGetId([
            'order_id' => $order->id, 'user_id' => $partner->id, 'category' => 'direct_commission', 'rate_percentage' => '1.00', 'cash_basis' => '1000.00',
            'amount' => $amount, 'original_amount' => $amount, 'status' => $status,
            'metadata' => json_encode($dueAt ? ['maturation_due_at' => $dueAt->format(DATE_ATOM)] : [], JSON_THROW_ON_ERROR), 'created_at' => now(), 'updated_at' => now(),
        ]);
    }
}
