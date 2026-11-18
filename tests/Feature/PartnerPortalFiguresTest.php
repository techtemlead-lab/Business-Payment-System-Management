<?php

namespace Tests\Feature;

use App\Domain\Accounts\PartnerMoneyBook;
use App\Domain\Commissions\CommissionPosting;
use App\Domain\Orders\OrderService;
use App\Models\Order;
use App\Models\Product;
use App\Models\ReferralTreePath;
use App\Models\User;
use Database\Seeders\CommissionRuleSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\TestCase;

/**
 * Dealer and sub-dealer screens must show one true figure for each thing (PP-01 to PP-04):
 * the orders tiles show what was ordered beside what was delivered, the team page counts the
 * same month and the same people everywhere, a sub-dealer is never labelled a dealer, and an
 * income row opens only an order the partner can open.
 */
class PartnerPortalFiguresTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->withoutVite();
        $this->seed(CommissionRuleSeeder::class);
        $this->enableFinancialPosting();
    }

    private function partner(?User $sponsor, string $role = 'dealer'): User
    {
        $user = User::factory()->create(['role' => $role, 'status' => 'active', 'sponsor_id' => $sponsor?->id]);
        ReferralTreePath::attachUser($user->id, $sponsor?->id);

        return $user;
    }

    private function order(User $buyer, string $status = 'submitted'): Order
    {
        $product = Product::factory()->create(['mrp' => '200.00', 'dealer_price' => '100.00', 'sub_dealer_price' => '120.00']);
        $order = app(OrderService::class)->create($buyer, ['items' => [['product_id' => $product->id, 'quantity' => 10]], 'idempotency_key' => (string) Str::uuid()]);
        if ($status !== 'submitted') {
            $order->update(['status' => $status, 'paid' => $status === 'delivered' ? $order->total : '0.00']);
        }

        return $order->refresh();
    }

    public function test_orders_tiles_show_what_was_ordered_next_to_what_was_delivered(): void
    {
        $dealer = $this->partner(null);
        $this->order($dealer, 'approved');
        $delivered = $this->order($dealer, 'delivered');
        $cancelled = $this->order($dealer);
        $cancelled->update(['status' => 'cancelled']);

        $this->actingAs($dealer)->onPortal('dealer')->get('/orders')->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->where('data.order_totals.orders', 3)
                // The approved order and the delivered one were ordered; the cancelled one is not counted.
                ->where('data.order_totals.ordered', '2000.00')
                ->where('data.order_totals.value', (string) $delivered->total));
    }

    public function test_team_figures_use_the_delivery_month_and_leave_the_dealer_out(): void
    {
        $this->travelTo(now()->setDate(2026, 10, 17)->setTime(12, 0));
        $dealer = $this->partner(null);
        $member = $this->partner($dealer, 'sub_dealer');
        // Placed in September, delivered this month: it counts for this month, as the salary target does.
        $order = $this->order($member, 'delivered');
        $order->forceFill(['created_at' => now()->subMonth()->setDay(20)])->save();
        app(CommissionPosting::class)->process($order);

        $this->actingAs($dealer)->onPortal('dealer')->get('/network')->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->where('data.team_summary.members', 1)
                ->where('data.team_summary.bought_this_month', 1)
                ->where('data.team_summary.team_sales_month', fn ($amount): bool => (float) $amount > 0)
                ->where('data.dealers', fn ($nodes): bool => collect($nodes)->firstWhere('id', $member->id)['sales_month'] === collect($nodes)->firstWhere('id', $member->id)['qualifying_volume_month']
                    && (float) collect($nodes)->firstWhere('id', $member->id)['sales_month'] > 0));
    }

    public function test_a_sub_dealer_in_the_team_is_labelled_a_sub_dealer(): void
    {
        $dealer = $this->partner(null);
        $subDealer = $this->partner($dealer, 'sub_dealer');
        // Ranks are made for every upline member; the sub-dealer's row must not turn them into a dealer.
        app(CommissionPosting::class)->process($this->order($subDealer, 'delivered'));

        $this->actingAs($dealer)->onPortal('dealer')->get('/network')->assertOk()
            ->assertInertia(fn (Assert $page) => $page->where('data.dealers', fn ($nodes): bool => collect($nodes)->firstWhere('id', $subDealer->id)['rank'] === 'sub_dealer'));
    }

    public function test_an_income_row_for_a_team_order_does_not_open_an_order_page(): void
    {
        $dealer = $this->partner(null);
        $member = $this->partner($dealer);
        $own = $this->order($dealer, 'delivered');
        $teamOrder = $this->order($member, 'delivered');

        $owned = PartnerMoneyBook::ownOrderIds($dealer, [$own->id, $teamOrder->id, null]);

        $this->assertSame([(string) $own->id => true], $owned);
        $this->actingAs($dealer)->onPortal('dealer')->get('/orders/'.$teamOrder->id)->assertNotFound();
    }
}
