<?php

namespace Tests\Feature;

use App\Domain\Orders\OrderRefund;
use App\Domain\Orders\OrderService;
use App\Models\Order;
use App\Models\Product;
use App\Models\User;
use Database\Seeders\CommissionRuleSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\TestCase;

/**
 * The money pages must show real figures: money sent back is not money kept, and the books
 * check must follow a real check of the saved records.
 */
class TreasuryFiguresTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->withoutVite();
        $this->enableFinancialPosting();
        $this->seed(CommissionRuleSeeder::class);
    }

    public function test_total_received_leaves_out_money_refunded_for_an_order(): void
    {
        $owner = User::factory()->create(['role' => 'super_admin']);
        $this->paidOrder('300.00', 'bKash');
        $refunded = $this->paidOrder('100.00', 'Bank');

        app(OrderRefund::class)->refund($owner, $refunded, '100.00', 'Goods are not available');
        $this->everyOrderDelivered();

        $this->orderReport($owner)->assertInertia(fn (Assert $page) => $page
            ->where('table.totals.sums.paid', '300.00')
            ->etc());
    }

    public function test_a_partial_refund_takes_only_the_refunded_part_off_the_total_and_its_channel(): void
    {
        $owner = User::factory()->create(['role' => 'super_admin']);
        $order = $this->paidOrder('100.00', 'bKash');

        app(OrderRefund::class)->refund($owner, $order, '30.00', 'Part of the order could not be sent');
        $this->everyOrderDelivered();

        $this->orderReport($owner, '?method=bKash')->assertInertia(fn (Assert $page) => $page
            ->where('table.totals.sums.paid', '70.00')
            ->where('table.rows.data.0.paid', '70.00')
            ->etc());
        $this->orderReport($owner, '?method=Bank')->assertInertia(fn (Assert $page) => $page
            ->where('table.totals.count', 0)
            ->etc());
    }

    public function test_the_books_match_message_follows_a_real_check(): void
    {
        $owner = User::factory()->create(['role' => 'super_admin']);
        $this->paidOrder('100.00', 'Bank');

        $this->booksCheck($owner)->assertInertia(fn (Assert $page) => $page->where('readiness.checks.order_receipts', true)->etc());

        // A receipt for money the order does not show: the two records no longer agree.
        DB::table('orders')->update(['paid' => '75.00']);

        $this->booksCheck($owner)->assertInertia(fn (Assert $page) => $page->where('readiness.checks.order_receipts', false)->etc());
    }

    private function everyOrderDelivered(): void
    {
        DB::table('orders')->update(['status' => 'delivered']);
    }

    private function orderReport(User $owner, string $query = '')
    {
        return $this->actingAs($owner)->onPortal('sadmin')->get('/treasury/orders'.$query)->assertOk();
    }

    private function booksCheck(User $owner)
    {
        return $this->actingAs($owner)->onPortal('sadmin')->get('/operations/financial-readiness')->assertOk();
    }

    private function paidOrder(string $amount, string $channel): Order
    {
        $buyer = User::factory()->dealer()->create();
        $product = Product::factory()->create(['mrp' => number_format((float) $amount * 2, 2, '.', ''), 'dealer_price' => $amount, 'stock' => 5]);
        $order = app(OrderService::class)->create($buyer, ['items' => [['product_id' => $product->id, 'quantity' => 1]], 'idempotency_key' => (string) Str::uuid()]);
        $order->update(['status' => 'approved', 'paid' => $amount]);
        $reference = 'TRX'.Str::upper(Str::random(8));
        $claim = DB::table('payment_claims')->insertGetId(['order_id' => $order->id, 'channel' => $channel, 'reference' => $reference, 'amount' => $amount,
            'status' => 'verified', 'created_at' => now(), 'updated_at' => now()]);
        DB::table('receipts')->insert(['order_id' => $order->id, 'claim_id' => $claim, 'channel' => $channel, 'reference' => $reference, 'amount' => $amount,
            'created_at' => now(), 'updated_at' => now()]);

        return $order->fresh();
    }
}
