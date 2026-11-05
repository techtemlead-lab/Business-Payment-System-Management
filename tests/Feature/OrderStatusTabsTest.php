<?php

namespace Tests\Feature;

use App\Models\Order;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\TestCase;

class OrderStatusTabsTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->withoutVite();
    }

    public function test_status_tabs_count_every_status_and_ignore_the_status_filter(): void
    {
        $owner = User::factory()->staff()->create();
        $rahim = User::factory()->dealer()->create(['name' => 'Rahim Traders']);
        $karim = User::factory()->dealer()->create(['name' => 'Karim Store']);
        $this->order($rahim, 'submitted');
        $this->order($rahim, 'submitted');
        $this->order($rahim, 'delivered');
        $this->order($karim, 'approved');

        $this->actingAs($owner)->onPortal('sadmin')->get('/orders?status=delivered')
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->where('data.order_status_counts.submitted', 2)
                ->where('data.order_status_counts.delivered', 1)
                ->where('data.order_status_counts.approved', 1)
                ->has('data.orders.data', 1));
    }

    public function test_status_tabs_follow_the_search_filter(): void
    {
        $owner = User::factory()->staff()->create();
        $this->order(User::factory()->dealer()->create(['name' => 'Rahim Traders']), 'submitted');
        $this->order(User::factory()->dealer()->create(['name' => 'Karim Store']), 'approved');

        $this->actingAs($owner)->onPortal('sadmin')->get('/orders?q=Rahim')
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->where('data.order_status_counts', ['submitted' => 1]));
    }

    private function order(User $buyer, string $status): Order
    {
        return Order::create([
            'number' => 'ORD-'.Str::upper(Str::random(8)),
            'user_id' => $buyer->id,
            'status' => $status,
            'total' => '100.00',
            'paid' => '0.00',
            'idempotency_key' => (string) Str::uuid(),
            'payload_hash' => hash('sha256', Str::random()),
        ]);
    }
}
