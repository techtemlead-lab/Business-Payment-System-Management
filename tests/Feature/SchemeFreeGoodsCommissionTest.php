<?php

namespace Tests\Feature;

use App\Domain\Commissions\CommissionPosting;
use App\Domain\Commissions\CommissionService;
use App\Domain\Orders\OrderInvoice;
use App\Domain\Orders\OrderService;
use App\Domain\Orders\RepeatOrderOptions;
use App\Models\CommissionAllocation;
use App\Models\DatedScheme;
use App\Models\Order;
use App\Models\Product;
use App\Models\ReferralTreePath;
use App\Models\User;
use App\Support\Money;
use Carbon\Carbon;
use Database\Seeders\CommissionRuleSeeder;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;
use Tests\TestCase;

class SchemeFreeGoodsCommissionTest extends TestCase
{
    use RefreshDatabase;

    private User $owner;

    private User $dealer;

    private Product $paidProduct;

    private Product $freeProduct;

    protected function setUp(): void
    {
        parent::setUp();
        $this->withoutVite();
        Http::fake();
        $this->enableFinancialPosting();
        $this->seed(CommissionRuleSeeder::class);
        Carbon::setTestNow('2026-11-10 06:00:00');
        $this->owner = User::factory()->create(['role' => 'super_admin', 'status' => 'active']);
        $sponsor = User::factory()->dealer()->create(['status' => 'active']);
        ReferralTreePath::attachUser($sponsor->id, null);
        $this->dealer = User::factory()->dealer()->create(['status' => 'active', 'sponsor_id' => $sponsor->id]);
        ReferralTreePath::attachUser($this->dealer->id, $sponsor->id);
        $this->paidProduct = Product::factory()->create(['active' => true, 'name' => 'Paid item', 'mrp' => '200.00', 'dealer_price' => '133.37', 'stock' => 500]);
        $this->freeProduct = Product::factory()->create(['active' => true, 'name' => 'Free item', 'mrp' => '90.00', 'dealer_price' => '60.00', 'stock' => 500]);
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    public function test_a_qualifying_order_gets_one_explicit_free_line_and_the_total_stays_the_same(): void
    {
        $this->scheme(['min_quantity' => 10, 'free_quantity' => 2]);

        $order = $this->place(10);

        $this->assertSame('1333.70', (string) $order->total);
        $free = $order->lines()->where('is_free', true)->get();
        $this->assertCount(1, $free);
        $this->assertSame($this->freeProduct->id, (int) $free[0]->product_id);
        $this->assertSame(2, (int) $free[0]->quantity);
        $this->assertSame(0, bccomp((string) $free[0]->unit_price, '0', 2));
        $this->assertSame(0, bccomp((string) $free[0]->total, '0', 2));
        $this->assertSame('Winter offer', $free[0]->scheme_title);
        $this->assertSame(1, $order->lines()->where('is_free', false)->count());
    }

    public function test_the_database_refuses_a_free_line_with_a_price(): void
    {
        $order = $this->place(1);

        $this->expectException(QueryException::class);
        $order->lines()->first()->update(['is_free' => true]);
    }

    public function test_an_order_below_the_minimum_gets_nothing_free(): void
    {
        $this->scheme(['min_quantity' => 10, 'free_quantity' => 2]);

        $this->assertSame(0, $this->place(9)->lines()->where('is_free', true)->count());
    }

    public function test_quantity_is_counted_across_all_the_products_the_scheme_covers(): void
    {
        $second = Product::factory()->create(['active' => true, 'mrp' => '100.00', 'dealer_price' => '70.00', 'stock' => 50]);
        $this->scheme(['product_ids' => [$this->paidProduct->id, $second->id], 'min_quantity' => 10, 'free_quantity' => 1]);

        $order = app(OrderService::class)->create($this->dealer, ['items' => [
            ['product_id' => $this->paidProduct->id, 'quantity' => 6],
            ['product_id' => $second->id, 'quantity' => 4],
        ], 'idempotency_key' => (string) Str::uuid()]);

        $this->assertSame(1, $order->lines()->where('is_free', true)->count());
    }

    public function test_a_product_outside_the_scheme_does_not_count_toward_the_minimum(): void
    {
        $this->scheme(['min_quantity' => 10, 'free_quantity' => 1]);
        $other = Product::factory()->create(['active' => true, 'mrp' => '100.00', 'dealer_price' => '70.00', 'stock' => 50]);

        $order = app(OrderService::class)->create($this->dealer, ['items' => [
            ['product_id' => $this->paidProduct->id, 'quantity' => 9],
            ['product_id' => $other->id, 'quantity' => 5],
        ], 'idempotency_key' => (string) Str::uuid()]);

        $this->assertSame(0, $order->lines()->where('is_free', true)->count());
    }

    public function test_a_large_order_still_gets_the_free_goods_once(): void
    {
        $this->scheme(['min_quantity' => 10, 'free_quantity' => 2]);

        $this->assertSame(2, (int) $this->place(35)->lines()->where('is_free', true)->sum('quantity'));
    }

    /** @return array<string, array{0: string, 1: int}> */
    public static function datesAroundTheScheme(): array
    {
        return [
            'first day' => ['2026-11-01 06:00:00', 1],
            'last day' => ['2026-11-30 06:00:00', 1],
            'day before' => ['2026-10-31 06:00:00', 0],
            'day after' => ['2026-12-01 06:00:00', 0],
            'late on the last day in Dhaka' => ['2026-11-30 17:59:00', 1],
            'already the next day in Dhaka' => ['2026-11-30 18:01:00', 0],
        ];
    }

    /** @dataProvider datesAroundTheScheme */
    public function test_the_scheme_dates_are_inclusive_in_the_business_timezone(string $moment, int $expected): void
    {
        config(['madina.business_timezone' => 'Asia/Dhaka']);
        $this->scheme(['min_quantity' => 1, 'free_quantity' => 1]);
        Carbon::setTestNow($moment);

        $this->assertSame($expected, $this->place(5)->lines()->where('is_free', true)->count());
    }

    public function test_a_display_only_scheme_and_a_discount_scheme_add_nothing(): void
    {
        $this->scheme(['free_product_id' => null, 'free_quantity' => null]);
        $this->scheme(['benefit_type' => 'discount', 'free_quantity' => 1]);

        $this->assertSame(0, $this->place(20)->lines()->where('is_free', true)->count());
    }

    public function test_a_paused_or_inactive_free_product_is_skipped_and_the_order_still_goes_through(): void
    {
        $this->scheme(['free_quantity' => 1]);
        $this->freeProduct->update(['is_stock_paused' => true]);

        $this->assertSame(0, $this->place(10)->lines()->where('is_free', true)->count());

        $this->freeProduct->update(['is_stock_paused' => false, 'active' => false]);
        $this->assertSame(0, $this->place(10)->lines()->where('is_free', true)->count());
    }

    public function test_the_owner_can_save_the_free_goods_and_they_must_come_as_a_pair(): void
    {
        $base = ['title' => 'Winter offer', 'starts_on' => '2026-11-01', 'ends_on' => '2026-11-30', 'product_ids' => [$this->paidProduct->id],
            'min_quantity' => 10, 'benefit_type' => 'free_goods', 'benefit_text' => 'One box free with ten'];

        $this->actingAs($this->owner)->onPortal('sadmin')->post('/commands/schemes', [...$base, 'free_product_id' => $this->freeProduct->id])->assertSessionHasErrors('free_quantity');
        $this->actingAs($this->owner)->onPortal('sadmin')->post('/commands/schemes', [...$base, 'benefit_type' => 'discount', 'free_product_id' => $this->freeProduct->id, 'free_quantity' => 1])->assertSessionHasErrors();
        $this->assertSame(0, DatedScheme::query()->count());

        $this->actingAs($this->owner)->onPortal('sadmin')->post('/commands/schemes', [...$base, 'free_product_id' => $this->freeProduct->id, 'free_quantity' => 1])->assertSessionHasNoErrors();
        $this->assertSame(1, (int) DatedScheme::query()->value('free_quantity'));
    }

    public function test_free_lines_post_exactly_the_same_commission_as_the_same_order_without_them(): void
    {
        $withoutScheme = $this->deliveredAndPosted(7);
        $this->scheme(['min_quantity' => 5, 'free_quantity' => 3]);
        $withScheme = $this->deliveredAndPosted(7);

        $this->assertSame(1, $withScheme->lines()->where('is_free', true)->count());
        $this->assertSame(0, $withoutScheme->lines()->where('is_free', true)->count());
        $this->assertSame($this->batch($withoutScheme), $this->batch($withScheme));
        $this->assertSame($this->rows($withoutScheme), $this->rows($withScheme));
        $this->assertSame($this->journal($withoutScheme), $this->journal($withScheme));
        $this->assertJournalBalances($withScheme);
    }

    public function test_free_lines_leave_the_funding_check_and_the_simulator_alone(): void
    {
        $this->scheme(['min_quantity' => 1, 'free_quantity' => 50]);

        $order = $this->deliveredAndPosted(1);

        $this->assertSame(1, $order->lines()->where('is_free', true)->count());
        $this->assertSame(1, DB::table('commission_batches')->where('order_id', $order->id)->count());
        $this->assertSame('200.00', (string) DB::table('commission_batches')->where('order_id', $order->id)->value('mrp_basis'));
        $this->assertFalse(app(CommissionService::class)->simulatePlan([], null, null, 10)['is_synthetic']);
    }

    public function test_free_goods_leave_stock_through_the_lots_and_are_recorded_like_any_line(): void
    {
        $this->scheme(['min_quantity' => 2, 'free_quantity' => 3]);
        $order = $this->place(2);

        $this->ship($order);

        $free = $order->lines()->where('is_free', true)->first();
        $this->assertSame(3, (int) $free->dispatched);
        $this->assertSame(500 - 3, (int) $this->freeProduct->fresh()->stock);
        $this->assertSame(3, abs((int) DB::table('stock_lot_movements')->where('order_line_id', $free->id)->sum('quantity')));
    }

    public function test_the_invoice_shows_free_lines_at_zero_and_keeps_its_totals(): void
    {
        $this->scheme(['min_quantity' => 5, 'free_quantity' => 2]);
        $withFree = $this->place(5);
        $withoutFree = $this->orderWithoutScheme(5);

        $invoice = app(OrderInvoice::class)->payload($withFree->fresh(), 'dealer');
        $plain = app(OrderInvoice::class)->payload($withoutFree->fresh(), 'dealer');

        $freeRow = collect($invoice['lines'])->firstWhere('name', 'Free item');
        $this->assertSame('0.00', $freeRow['total']);
        $this->assertSame('0.00', $freeRow['mrp_bill']);
        $this->assertSame('0.00', $freeRow['commission']);
        $this->assertSame(__('schemes_commission.free_line_label'), $freeRow['remark']);
        $this->assertSame($plain['totals']['total'], $invoice['totals']['total']);
        $this->assertSame($plain['totals']['mrp_bill'], $invoice['totals']['mrp_bill']);
        $this->assertSame($plain['totals']['commission'], $invoice['totals']['commission']);
    }

    public function test_repeating_an_order_does_not_buy_the_free_item(): void
    {
        $this->scheme(['min_quantity' => 2, 'free_quantity' => 1]);
        $order = $this->place(2);

        $option = collect(app(RepeatOrderOptions::class)->for($this->dealer))->firstWhere('order_id', (string) $order->id);

        $this->assertNotNull($option);
        $this->assertSame([$this->paidProduct->id], array_map(fn (array $item): int => (int) $item['product']['id'], $option['items']));
    }

    /** @param array<string, mixed> $overrides */
    private function scheme(array $overrides = []): DatedScheme
    {
        return DatedScheme::query()->create([
            'title' => 'Winter offer',
            'starts_on' => '2026-11-01',
            'ends_on' => '2026-11-30',
            'product_ids' => [$this->paidProduct->id],
            'min_quantity' => 10,
            'benefit_type' => 'free_goods',
            'benefit_text' => 'Free box',
            'free_product_id' => $this->freeProduct->id,
            'free_quantity' => 1,
            'created_by' => $this->owner->id,
            ...$overrides,
        ]);
    }

    private function place(int $quantity): Order
    {
        return app(OrderService::class)->create($this->dealer, ['items' => [['product_id' => $this->paidProduct->id, 'quantity' => $quantity]], 'idempotency_key' => (string) Str::uuid()]);
    }

    private function orderWithoutScheme(int $quantity): Order
    {
        $running = DatedScheme::query()->pluck('id');
        DatedScheme::query()->whereIn('id', $running)->update(['ends_on' => '2026-10-31']);
        $order = $this->place($quantity);
        DatedScheme::query()->whereIn('id', $running)->update(['ends_on' => '2026-11-30']);

        return $order;
    }

    private function ship(Order $order): void
    {
        DB::table('orders')->where('id', $order->id)->update(['paid' => $order->total]);
        app(OrderService::class)->decision($this->owner, $order->fresh(), 'approved', 'Paid in full');
        app(OrderService::class)->dispatch(User::factory()->staff('inventory')->create(['status' => 'active']), $order->fresh(), [
            'courier' => 'Test Courier', 'tracking' => 'T-'.Str::random(6), 'idempotency_key' => (string) Str::uuid(),
            'items' => $order->lines()->get()->map(fn ($line): array => ['line_id' => $line->id, 'quantity' => (int) $line->quantity])->all(),
        ]);
    }

    private function deliveredAndPosted(int $quantity): Order
    {
        $order = $this->place($quantity);
        DB::table('orders')->where('id', $order->id)->update(['paid' => $order->total, 'status' => 'delivered']);
        app(CommissionPosting::class)->process($order->fresh(), $this->owner->id);

        return $order->fresh();
    }

    /** @return array<string, string> */
    private function batch(Order $order): array
    {
        $batch = DB::table('commission_batches')->where('order_id', $order->id)->first();
        $this->assertNotNull($batch);

        return ['cash_basis' => (string) $batch->cash_basis, 'mrp_basis' => (string) $batch->mrp_basis, 'company_share' => (string) $batch->company_share,
            'total_allocated' => (string) $batch->total_allocated, 'unallocated' => (string) $batch->unallocated];
    }

    /** @return list<string> */
    private function rows(Order $order): array
    {
        return CommissionAllocation::query()->where('order_id', $order->id)->get()
            ->map(fn (CommissionAllocation $row): string => implode('|', [$row->category, (string) $row->user_id, (string) $row->amount, (string) $row->rate_percentage, $row->status]))
            ->sort()->values()->all();
    }

    /** @return list<string> */
    private function journal(Order $order): array
    {
        return DB::table('journal_lines')->join('journal_entries', 'journal_entries.id', '=', 'journal_lines.entry_id')
            ->where('journal_entries.event_key', 'commissions.order.'.$order->id)->get(['account', 'user_id', 'debit', 'credit'])
            ->map(fn (object $line): string => implode('|', [$line->account, (string) $line->user_id, (string) $line->debit, (string) $line->credit]))
            ->sort()->values()->all();
    }

    private function assertJournalBalances(Order $order): void
    {
        $entry = DB::table('journal_entries')->where('event_key', 'commissions.order.'.$order->id)->value('id');
        $this->assertNotNull($entry);
        $lines = DB::table('journal_lines')->where('entry_id', $entry)->get();
        $debit = $lines->sum(fn (object $line): int => Money::cents((string) $line->debit));
        $this->assertGreaterThan(0, $debit);
        $this->assertSame($debit, $lines->sum(fn (object $line): int => Money::cents((string) $line->credit)));
    }
}
