<?php

namespace Tests\Feature;

use App\Domain\Commissions\CommissionService;
use App\Domain\Finance\FinancialReadiness;
use App\Domain\Finance\MissedOrderPostings;
use App\Domain\Orders\OrderService;
use App\Models\CommissionAllocation;
use App\Models\Order;
use App\Models\Product;
use App\Models\User;
use Database\Seeders\CommissionRuleSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\TestCase;

/**
 * An order delivered and paid while money posting was off (or in preview) posts nothing and is never tried again.
 * The readiness page names such orders and finance:post-missed-orders posts them once, through the normal posting.
 */
class MissedOrderPostingsTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->withoutVite();
        $this->seed(CommissionRuleSeeder::class);
        $this->seedClassicPlaceCodes();
    }

    public function test_an_order_delivered_while_posting_was_off_is_named_and_posted_once(): void
    {
        $order = $this->deliveredWhilePostingOff();
        $this->assertSame([], CommissionAllocation::query()->where('order_id', $order->id)->get()->all());

        $this->enableFinancialPosting();
        $readiness = app(FinancialReadiness::class);
        $this->assertFalse($readiness->checks()['missed_order_batches']);
        // Money waiting for a person is not a book check (the test orders have no bank receipt, so that check fails).
        $this->assertSame(['order_receipts'], FinancialReadiness::failedNames(FinancialReadiness::bookChecks($readiness->checks())));
        $this->assertSame(['missed_order_batches'], $readiness->inspect()['needs_attention']);

        // Dry run by default: listed, nothing posted.
        $this->artisan('finance:post-missed-orders')->expectsOutputToContain($order->number)->expectsOutputToContain('Dry run')->assertSuccessful();
        $this->assertFalse(DB::table('commission_batches')->where('order_id', $order->id)->exists());

        $this->artisan('finance:post-missed-orders', ['--apply' => true])->expectsOutputToContain('1 posted, 0 not posted.')->assertSuccessful();
        $this->assertSame(1, DB::table('commission_batches')->where('order_id', $order->id)->count());
        $rows = CommissionAllocation::query()->where('order_id', $order->id)->count();
        $this->assertGreaterThan(0, $rows);
        $this->assertTrue($readiness->checks()['missed_order_batches']);
        $this->assertSame([], $readiness->inspect()['needs_attention']);
        $this->assertSame(['order_receipts'], FinancialReadiness::failedNames(FinancialReadiness::bookChecks($readiness->checks())));

        // Running again never posts twice.
        $this->artisan('finance:post-missed-orders', ['--apply' => true])->expectsOutputToContain('Nothing to do')->assertSuccessful();
        app(CommissionService::class)->processOrder($order->fresh());
        $this->assertSame(1, DB::table('commission_batches')->where('order_id', $order->id)->count());
        $this->assertSame($rows, CommissionAllocation::query()->where('order_id', $order->id)->count());
        $this->assertDatabaseHas('audit_events', ['action' => 'commissions.missed_orders_posted']);
    }

    public function test_apply_is_refused_while_posting_is_still_off(): void
    {
        $order = $this->deliveredWhilePostingOff();

        $this->artisan('finance:post-missed-orders', ['--apply' => true])->expectsOutputToContain('nothing can be posted')->assertFailed();
        $this->assertFalse(DB::table('commission_batches')->where('order_id', $order->id)->exists());
    }

    public function test_an_order_whose_price_cannot_fund_the_commission_is_reported_not_posted(): void
    {
        $good = $this->deliveredWhilePostingOff();
        $short = $this->deliveredWhilePostingOff('5000.00', '20000.00');
        $this->enableFinancialPosting();

        $this->artisan('finance:post-missed-orders', ['--apply' => true])
            ->expectsOutputToContain('Not posted: '.$short->number)
            ->expectsOutputToContain('1 posted, 1 not posted.')
            ->assertFailed();

        $this->assertTrue(DB::table('commission_batches')->where('order_id', $good->id)->exists());
        $this->assertFalse(DB::table('commission_batches')->where('order_id', $short->id)->exists());
        $this->assertSame([$short->id], array_column(app(MissedOrderPostings::class)->list(), 'id'));
        $this->assertFalse(app(FinancialReadiness::class)->checks()['missed_order_batches']);
    }

    public function test_orders_that_never_post_are_not_named(): void
    {
        $unpaid = $this->deliveredWhilePostingOff();
        $unpaid->update(['paid' => '0.00']);
        $opening = $this->deliveredWhilePostingOff();
        $opening->forceFill(['is_opening_balance' => true])->save();
        $open = $this->deliveredWhilePostingOff();
        $open->forceFill(['status' => 'approved'])->save();

        $this->assertSame([], app(MissedOrderPostings::class)->list());
        $this->enableFinancialPosting();
        $this->assertTrue(app(FinancialReadiness::class)->checks()['missed_order_batches']);
    }

    private function deliveredWhilePostingOff(string $price = '10000.00', string $mrp = '20000.00'): Order
    {
        $active = config('madina.financial_activation');
        config(['madina.financial_activation' => false]);
        $buyer = User::factory()->dealer()->create(['status' => 'active']);
        $product = Product::factory()->create(['mrp' => $mrp, 'dealer_price' => $price]);
        $order = app(OrderService::class)->create($buyer, ['items' => [['product_id' => $product->id, 'quantity' => 1]], 'idempotency_key' => (string) Str::uuid()]);
        $order->update(['paid' => $price, 'status' => 'delivered']);
        $this->assertSame([], app(CommissionService::class)->processOrder($order->fresh()));
        config(['madina.financial_activation' => $active]);

        return $order->fresh();
    }
}
