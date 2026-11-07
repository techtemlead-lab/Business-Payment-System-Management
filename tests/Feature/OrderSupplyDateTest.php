<?php

namespace Tests\Feature;

use App\Domain\Orders\OrderService;
use App\Models\CompanyBranch;
use App\Models\Order;
use App\Models\Product;
use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\TestCase;

/**
 * Back-order date (contract D03 / A07): the store team records when items still waiting
 * for stock should go out, and the dealer sees that date on the order.
 */
class OrderSupplyDateTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->withoutVite();
        $this->travelTo(CarbonImmutable::parse('2026-11-07 11:00:00', 'Asia/Dhaka'));
    }

    public function test_store_staff_set_the_date_and_the_dealer_sees_it_with_a_notice(): void
    {
        $dealer = User::factory()->dealer()->create();
        $order = $this->approvedOrder($dealer);
        $store = User::factory()->staff('inventory')->create();

        $this->actingAs($store)->onPortal('manage')
            ->post('/commands/orders/'.$order->id.'/supply-date', ['supply_eta' => '2026-11-15', 'supply_note' => 'New stock arrives on Thursday'])
            ->assertRedirect()
            ->assertSessionHasNoErrors()
            ->assertSessionHas('success', __('portal.flash_supply_eta_saved'));

        $order->refresh();
        $this->assertSame('2026-11-15', $order->supply_eta?->toDateString());
        $this->assertSame('New stock arrives on Thursday', $order->supply_note);
        $this->assertDatabaseHas('order_events', ['order_id' => $order->id, 'actor_id' => $store->id, 'action' => 'order.supply_date_set']);
        $this->assertDatabaseHas('audit_events', ['actor_id' => $store->id, 'action' => 'order.supply_date_set', 'subject' => $order->id]);
        $this->assertDatabaseHas('account_notices', ['user_id' => $dealer->id, 'title' => __('portal.notice_supply_eta_title', [], $dealer->preferredLocale())]);

        $this->actingAs($dealer)->onPortal('dealer')->get('/orders/'.$order->id)->assertOk()
            ->assertInertia(fn ($page) => $page->component('OrderDetail')
                ->where('order.supply_eta', '2026-11-15')
                ->where('order.supply_note', 'New stock arrives on Thursday'));
    }

    public function test_clearing_the_date_removes_the_note_and_sends_no_notice(): void
    {
        $dealer = User::factory()->dealer()->create();
        $order = $this->approvedOrder($dealer);
        $order->update(['supply_eta' => '2026-11-20', 'supply_note' => 'Waiting for stock']);
        $store = User::factory()->staff('inventory')->create();

        $this->actingAs($store)->onPortal('manage')
            ->post('/commands/orders/'.$order->id.'/supply-date', ['supply_eta' => null, 'supply_note' => 'ignored'])
            ->assertSessionHasNoErrors();

        $order->refresh();
        $this->assertNull($order->supply_eta);
        $this->assertNull($order->supply_note);
        $this->assertSame(0, DB::table('account_notices')->where('user_id', $dealer->id)->where('title', __('portal.notice_supply_eta_title', [], $dealer->preferredLocale()))->count());
    }

    public function test_only_store_staff_can_set_the_date(): void
    {
        $dealer = User::factory()->dealer()->create();
        $order = $this->approvedOrder($dealer);

        $this->actingAs($dealer)->onPortal('dealer')
            ->post('/commands/orders/'.$order->id.'/supply-date', ['supply_eta' => '2026-11-15'])
            ->assertForbidden();
        $this->actingAs(User::factory()->staff('support')->create())->onPortal('manage')
            ->post('/commands/orders/'.$order->id.'/supply-date', ['supply_eta' => '2026-11-15'])
            ->assertForbidden();

        $this->assertNull($order->fresh()->supply_eta);
    }

    public function test_the_date_must_be_today_or_later_and_the_order_must_have_items_left(): void
    {
        $dealer = User::factory()->dealer()->create();
        $order = $this->approvedOrder($dealer);
        $store = User::factory()->staff('inventory')->create();

        $this->actingAs($store)->onPortal('manage')
            ->post('/commands/orders/'.$order->id.'/supply-date', ['supply_eta' => '2026-11-01'])
            ->assertSessionHasErrors('supply_eta');

        DB::table('order_lines')->where('order_id', $order->id)->update(['dispatched' => DB::raw('quantity')]);
        $this->actingAs($store)->onPortal('manage')
            ->post('/commands/orders/'.$order->id.'/supply-date', ['supply_eta' => '2026-11-15'])
            ->assertSessionHasErrors(['supply_eta' => __('portal.err_supply_eta_nothing_left')]);

        $delivered = $this->approvedOrder($dealer);
        $delivered->update(['status' => 'delivered']);
        $this->actingAs($store)->onPortal('manage')
            ->post('/commands/orders/'.$delivered->id.'/supply-date', ['supply_eta' => '2026-11-15'])
            ->assertSessionHasErrors('supply_eta');

        $this->assertNull($order->fresh()->supply_eta);
        $this->assertNull($delivered->fresh()->supply_eta);
    }

    public function test_courier_phone_or_slip_number_is_saved_with_the_parcel_and_shown_to_the_dealer(): void
    {
        $hub = CompanyBranch::factory()->create(['name' => 'Hub', 'code' => 'HUB-1', 'is_active' => true, 'is_stock_hub' => true]);
        $dealer = User::factory()->dealer()->create();
        $order = $this->approvedOrder($dealer);
        $order->update(['paid' => $order->total]);
        $line = $order->lines()->firstOrFail();

        $this->actingAs(User::factory()->staff()->create())->onPortal('manage')
            ->postJson('/api/v1/orders/'.$order->id.'/dispatch', [
                'branch_id' => $hub->id,
                'courier' => 'Sundarban Courier', 'tracking' => 'SND-1', 'courier_contact' => '01711-000111 · slip 4471',
                'idempotency_key' => (string) Str::uuid(), 'items' => [['line_id' => $line->id, 'quantity' => 1]],
            ])
            ->assertOk();

        $this->assertDatabaseHas('shipments', ['order_id' => $order->id, 'courier_contact' => '01711-000111 · slip 4471']);
        $this->actingAs($dealer)->onPortal('dealer')->get('/orders/'.$order->id)->assertOk()
            ->assertInertia(fn ($page) => $page->where('shipments.0.courier_contact', '01711-000111 · slip 4471'));
    }

    private function approvedOrder(User $dealer): Order
    {
        $product = Product::factory()->create(['stock' => 2]);
        $order = app(OrderService::class)->create($dealer, [
            'items' => [['product_id' => $product->id, 'quantity' => 2]],
            'idempotency_key' => (string) Str::uuid(),
        ]);
        $order->update(['status' => 'approved']);

        return $order->fresh();
    }
}
