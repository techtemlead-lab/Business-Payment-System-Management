<?php

namespace Tests\Feature;

use App\Models\Order;
use App\Models\Product;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
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

    public function test_payment_tabs_count_claims_per_status(): void
    {
        $owner = User::factory()->staff()->create();
        $order = $this->order(User::factory()->dealer()->create(), 'approved');
        foreach (['pending', 'pending', 'verified'] as $index => $status) {
            DB::table('payment_claims')->insert(['order_id' => $order->id, 'channel' => 'bKash', 'reference' => 'TRX-TAB-'.$index,
                'amount' => '10.00', 'status' => $status, 'created_at' => now(), 'updated_at' => now()]);
        }

        $this->actingAs($owner)->onPortal('sadmin')->get('/payments?status=verified')
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->where('data.claim_status_counts', ['pending' => 2, 'verified' => 1])
                ->has('data.claims.data', 1));
    }

    public function test_withdrawal_tabs_only_count_the_partners_own_requests(): void
    {
        $dealer = User::factory()->dealer()->create();
        $other = User::factory()->dealer()->create();
        foreach ([[$dealer, 'requested'], [$dealer, 'paid'], [$other, 'requested']] as [$user, $status]) {
            DB::table('withdrawals')->insert(['id' => (string) Str::uuid(), 'user_id' => $user->id, 'amount' => '100.00', 'net_amount' => '100.00',
                'channel' => 'bKash', 'destination' => '01700000000', 'status' => $status, 'idempotency_key' => (string) Str::uuid(),
                'created_at' => now(), 'updated_at' => now()]);
        }

        $this->actingAs($dealer)->onPortal('dealer')->get('/withdrawals')
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->where('data.withdrawal_status_counts', ['requested' => 1, 'paid' => 1]));
    }

    public function test_dues_summary_totals_what_is_owed_and_how_old_it_is(): void
    {
        $owner = User::factory()->staff()->create();
        $dealer = User::factory()->dealer()->create();
        $this->order($dealer, 'approved')->forceFill(['created_at' => now()->subDays(75)])->save();
        $this->order($dealer, 'delivered')->forceFill(['paid' => '40.00', 'created_at' => now()->subDays(10)])->save();
        $this->order($dealer, 'submitted');

        $this->actingAs($owner)->onPortal('sadmin')->get('/dues')
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->where('data.dues_summary.outstanding', '160.00')
                ->where('data.dues_summary.orders', 2)
                ->where('data.dues_summary.over_60', '100.00')
                ->where('data.dues_summary.oldest_days', 75));
    }

    public function test_delivery_summary_counts_orders_and_units_still_to_send(): void
    {
        $owner = User::factory()->staff()->create();
        $dealer = User::factory()->dealer()->create();
        $product = Product::factory()->create();
        foreach ([[5, 2], [3, 0]] as [$quantity, $sent]) {
            $order = $this->order($dealer, $sent > 0 ? 'partially_dispatched' : 'approved');
            $order->lines()->create(['product_id' => $product->id, 'sku' => $product->sku, 'name' => $product->name,
                'quantity' => $quantity, 'dispatched' => $sent, 'mrp' => '10.00', 'unit_price' => '10.00', 'total' => '10.00']);
        }

        $this->actingAs($owner)->onPortal('sadmin')->get('/deliveries')
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->where('data.delivery_summary.orders', 2)
                ->where('data.delivery_summary.units', 6));
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
