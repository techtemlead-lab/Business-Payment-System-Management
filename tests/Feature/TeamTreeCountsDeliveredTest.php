<?php

namespace Tests\Feature;

use App\Domain\Commissions\CommissionPosting;
use App\Domain\Orders\MixedOutcomeDecisions;
use App\Domain\Orders\OrderService;
use App\Http\Middleware\HandleInertiaRequests;
use App\Models\DealerRank;
use App\Models\Order;
use App\Models\Product;
use App\Models\ReferralTreePath;
use App\Models\User;
use Database\Seeders\CommissionRuleSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;
use Tests\TestCase;

/**
 * The team tree and the rank page must show the same thing: what was delivered, not what was
 * only approved or sent.
 */
class TeamTreeCountsDeliveredTest extends TestCase
{
    use RefreshDatabase;

    private User $owner;

    private User $leader;

    private User $member;

    private Product $product;

    protected function setUp(): void
    {
        parent::setUp();
        $this->withoutVite();
        Http::fake();
        $this->enableFinancialPosting();
        $this->seed(CommissionRuleSeeder::class);
        $this->owner = User::factory()->create(['role' => 'super_admin', 'status' => 'active']);
        $this->leader = User::factory()->dealer()->create(['status' => 'active']);
        ReferralTreePath::attachUser($this->leader->id, null);
        $this->member = User::factory()->dealer()->create(['status' => 'active', 'sponsor_id' => $this->leader->id]);
        ReferralTreePath::attachUser($this->member->id, $this->leader->id);
        $this->product = Product::factory()->create(['active' => true, 'mrp' => '3000.00', 'dealer_price' => '2000.00', 'stock' => 100]);
    }

    public function test_approved_and_sent_orders_do_not_count_on_the_tree_until_delivered(): void
    {
        foreach (['approved', 'partially_dispatched', 'dispatched'] as $status) {
            $this->order($status, 5);
        }

        $node = $this->treeNode();
        $this->assertSame('0.00', $node['sales_total']);
        $this->assertSame('0.00', $node['sales_month']);
        $this->assertSame('0.00', $this->treeStats()['sales_total']);
        $summary = $this->teamSummary();
        $this->assertSame('0.00', $summary['team_sales_month']);
        $this->assertSame(0, $summary['bought_this_month']);

        $this->delivered(5);

        $node = $this->treeNode();
        $this->assertSame('10000.00', $node['sales_total']);
        $this->assertSame('10000.00', $node['sales_month']);
        $this->assertSame('10000.00', $this->treeStats()['sales_total']);
        $summary = $this->teamSummary();
        $this->assertSame('10000.00', $summary['team_sales_month']);
        $this->assertSame(1, $summary['bought_this_month']);
    }

    public function test_the_tree_and_the_rank_page_show_the_same_delivered_figure(): void
    {
        $this->order('approved', 5);
        $this->delivered(5);

        $this->assertSame(
            (string) DealerRank::query()->where('user_id', $this->member->id)->value('lifetime_purchase_total'),
            $this->treeNode()['sales_total'],
        );
    }

    public function test_a_partly_delivered_order_counts_only_its_delivered_part_on_the_tree(): void
    {
        $order = $this->mixedOrder(3, 2);

        $this->assertSame('0.00', $this->treeNode()['sales_total'], 'Nothing counts until staff decide.');
        $this->assertSame('0.00', $this->treeStats()['sales_total']);

        app(MixedOutcomeDecisions::class)->decide(User::factory()->staff('finance')->create(['status' => 'active']), $order->fresh(), MixedOutcomeDecisions::DeliveredPart, 'Dealer agreed on the phone');

        $node = $this->treeNode();
        $this->assertSame('6000.00', $node['sales_total']);
        $this->assertSame('6000.00', $node['sales_month']);
        $this->assertSame('6000.00', $this->treeStats()['sales_total']);
        $this->assertSame('6000.00', $this->teamSummary()['team_sales_month']);
        $this->assertSame('6000.00', (string) DealerRank::query()->where('user_id', $this->member->id)->value('lifetime_purchase_total'));
    }

    /** @return array<string, mixed> */
    private function treeNode(): array
    {
        $nodes = $this->actingAs($this->leader)->onPortal('dealer')
            ->withHeaders(['X-Inertia' => 'true', 'X-Inertia-Version' => $this->inertiaVersion()])
            ->get('/network')->assertOk()->json('props.data.dealers');

        return collect($nodes)->firstWhere('id', $this->member->id);
    }

    /** @return array<string, mixed> */
    private function teamSummary(): array
    {
        return $this->actingAs($this->leader)->onPortal('dealer')
            ->withHeaders(['X-Inertia' => 'true', 'X-Inertia-Version' => $this->inertiaVersion()])
            ->get('/network')->assertOk()->json('props.data.team_summary');
    }

    /** @return array<string, mixed> */
    private function treeStats(): array
    {
        return $this->actingAs($this->owner)->onPortal('sadmin')
            ->withHeaders(['X-Inertia' => 'true', 'X-Inertia-Version' => $this->inertiaVersion()])
            ->get('/network-tree')->assertOk()->json('props.data.tree_stats');
    }

    private function inertiaVersion(): string
    {
        return (string) (new HandleInertiaRequests)->version(Request::create('/'));
    }

    private function order(string $status, int $quantity): Order
    {
        $order = app(OrderService::class)->create($this->member, ['items' => [['product_id' => $this->product->id, 'quantity' => $quantity]], 'idempotency_key' => (string) Str::uuid()]);
        DB::table('orders')->where('id', $order->id)->update(['paid' => $order->total, 'status' => $status]);

        return $order->fresh();
    }

    private function delivered(int $quantity): void
    {
        app(CommissionPosting::class)->process($this->order('delivered', $quantity), $this->owner->id);
    }

    private function mixedOrder(int $delivered, int $returned): Order
    {
        $order = $this->order('partly_delivered', $delivered + $returned);
        $line = $order->lines()->where('is_free', false)->firstOrFail();
        foreach (['delivered' => $delivered, 'returned' => $returned] as $status => $quantity) {
            DB::table('order_lines')->where('id', $line->id)->increment('dispatched', $quantity);
            DB::table('shipments')->insert(['order_id' => $order->id, 'courier' => 'Test', 'tracking' => 'T-'.Str::random(6), 'status' => $status, 'courier_status' => $status,
                'lines' => json_encode([['line_id' => $line->id, 'name' => 'Item', 'quantity' => $quantity, 'lots' => []]], JSON_THROW_ON_ERROR),
                'actor_id' => $this->owner->id, 'idempotency_key' => (string) Str::uuid(), 'payload_hash' => hash('sha256', (string) Str::uuid()), 'created_at' => now(), 'updated_at' => now()]);
        }

        return $order->fresh();
    }
}
