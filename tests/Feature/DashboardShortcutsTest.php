<?php

namespace Tests\Feature;

use App\Models\Order;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Tests\TestCase;

/** CR-09: the dashboard reaches "new account" and "find an order" for everyone who may use them. */
class DashboardShortcutsTest extends TestCase
{
    use RefreshDatabase;

    private function order(User $buyer, string $number): Order
    {
        return Order::create([
            'number' => $number,
            'user_id' => $buyer->id,
            'status' => 'submitted',
            'total' => '500.00',
            'paid' => '0.00',
            'idempotency_key' => (string) Str::uuid(),
            'payload_hash' => hash('sha256', Str::random()),
        ])->fresh();
    }

    public function test_one_matching_order_opens_at_once(): void
    {
        $owner = User::factory()->staff()->create();
        $dealer = User::factory()->dealer()->create();
        $order = $this->order($dealer, 'MN-DASH-0001');
        $this->order($dealer, 'MN-OTHER-0002');

        $this->actingAs($owner)->onPortal('sadmin')->get('/orders/find?q=DASH-0001')->assertRedirect('/orders/'.$order->id);
    }

    public function test_a_phone_number_with_several_orders_opens_the_list_with_the_same_words(): void
    {
        $owner = User::factory()->staff()->create();
        $dealer = User::factory()->dealer()->create(['phone' => '01712345678']);
        $this->order($dealer, 'MN-PH-0001');
        $this->order($dealer, 'MN-PH-0002');

        $this->actingAs($owner)->onPortal('sadmin')->get('/orders/find?q=01712345678')->assertRedirect('/orders?q=01712345678');
    }

    public function test_staff_without_order_permission_cannot_use_the_finder(): void
    {
        $editor = User::factory()->staff('editor')->create();

        $this->actingAs($editor)->onPortal('manage')->get('/orders/find?q=anything')->assertForbidden();
    }

    public function test_finance_staff_can_find_an_order_on_the_staff_panel(): void
    {
        $finance = User::factory()->staff('finance')->create();
        $order = $this->order(User::factory()->dealer()->create(), 'MN-FIN-0009');

        $this->actingAs($finance)->onPortal('manage')->get('/orders/find?q=FIN-0009')->assertRedirect('/orders/'.$order->id);
    }

    public function test_the_dashboard_offers_the_shortcuts_by_permission(): void
    {
        $owner = User::factory()->staff()->create();
        $editor = User::factory()->staff('editor')->create();

        $this->actingAs($owner)->onPortal('sadmin')->get('/')->assertOk()
            ->assertInertia(fn ($page) => $page->where('auth.capabilities', fn ($caps): bool => collect($caps)->contains('accounts') && collect($caps)->contains('orders')));
        $this->actingAs($editor)->onPortal('manage')->get('/')->assertOk()
            ->assertInertia(fn ($page) => $page->where('auth.capabilities', fn ($caps): bool => ! collect($caps)->contains('accounts') && ! collect($caps)->contains('orders')));
    }
}
