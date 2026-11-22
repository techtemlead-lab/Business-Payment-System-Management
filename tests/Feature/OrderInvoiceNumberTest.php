<?php

namespace Tests\Feature;

use App\Domain\Inventory\StockService;
use App\Domain\Orders\OrderInvoice;
use App\Domain\Orders\OrderListFilter;
use App\Domain\Orders\OrderNumbers;
use App\Domain\Orders\OrderService;
use App\Domain\Search\GlobalSearch;
use App\Models\CompanyBranch;
use App\Models\Order;
use App\Models\Product;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\TestCase;

/**
 * Every new order is numbered MNHC-{Dhaka date}-{sequence of that day, from 100001}, shown on the invoice; orders placed
 * earlier keep the number they have, and the lists and the search find every form.
 */
class OrderInvoiceNumberTest extends TestCase
{
    use RefreshDatabase;

    private CompanyBranch $hub;

    private User $owner;

    private User $dealer;

    protected function setUp(): void
    {
        parent::setUp();
        $this->withoutVite();
        $this->hub = CompanyBranch::query()->where('code', 'HO-PIRGANJ')->firstOrFail();
        $this->owner = User::factory()->staff('super_admin')->create(['active_branch_id' => $this->hub->id]);
        $this->dealer = User::factory()->dealer()->create(['status' => 'active']);
    }

    private function placeOrder(): Order
    {
        $product = Product::factory()->create(['mrp' => '200.00', 'stock' => 0, 'active' => true]);
        app(StockService::class)->stockIn($this->owner, ['product_id' => $product->id, 'quantity' => 10, 'reference' => (string) Str::uuid(), 'branch_id' => $this->hub->id,
            'batch_number' => 'LOT-A', 'expires_on' => now()->addDays(200)->toDateString()]);

        return app(OrderService::class)->create($this->dealer, ['items' => [['product_id' => $product->id, 'quantity' => 1]], 'idempotency_key' => (string) Str::uuid()]);
    }

    private function dhaka(string $moment): Carbon
    {
        return Carbon::parse($moment, 'Asia/Dhaka');
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    public function test_two_orders_the_same_dhaka_day_get_100001_and_100002_in_the_mnhc_date_format(): void
    {
        Carbon::setTestNow($this->dhaka('2026-11-27 10:00:00'));

        $first = $this->placeOrder();
        $second = $this->placeOrder();

        $this->assertSame('MNHC-20261127-100001', $first->number);
        $this->assertSame('MNHC-20261127-100002', $second->number);
        foreach ([$first, $second] as $order) {
            $this->assertMatchesRegularExpression(OrderNumbers::Pattern, $order->number);
        }
    }

    public function test_the_sequence_starts_again_at_100001_the_next_day(): void
    {
        Carbon::setTestNow($this->dhaka('2026-11-27 10:00:00'));
        $this->placeOrder();
        $this->placeOrder();

        Carbon::setTestNow($this->dhaka('2026-11-28 09:00:00'));
        $this->assertSame('MNHC-20261128-100001', $this->placeOrder()->number);

        Carbon::setTestNow($this->dhaka('2026-11-27 18:00:00'));
        $this->assertSame('MNHC-20261127-100003', $this->placeOrder()->number, 'a day keeps counting from where it stopped');
    }

    public function test_the_date_and_the_reset_follow_dhaka_time_around_midnight_not_utc(): void
    {
        // 23:59 in Dhaka is 17:59 UTC the same day; 00:01 in Dhaka is 18:01 UTC the day before.
        Carbon::setTestNow($this->dhaka('2026-11-27 23:59:00'));
        $this->assertSame('MNHC-20261127-100001', OrderNumbers::next());

        Carbon::setTestNow($this->dhaka('2026-11-28 00:01:00'));
        $this->assertSame('MNHC-20261128-100001', OrderNumbers::next());
        $this->assertSame('2026-11-28', OrderNumbers::day());
        $this->assertSame('MNHC-20261128-100002', OrderNumbers::next());
    }

    public function test_a_rolled_back_order_gives_its_number_back_and_numbers_are_never_shared(): void
    {
        Carbon::setTestNow($this->dhaka('2026-11-27 10:00:00'));
        $this->assertSame('MNHC-20261127-100001', OrderNumbers::next());

        try {
            DB::transaction(function (): void {
                $this->assertSame('MNHC-20261127-100002', OrderNumbers::next());
                throw new \RuntimeException('order refused');
            });
        } catch (\RuntimeException) {
        }

        $numbers = [OrderNumbers::next(), OrderNumbers::next(), OrderNumbers::next()];
        $this->assertSame(['MNHC-20261127-100002', 'MNHC-20261127-100003', 'MNHC-20261127-100004'], $numbers);
        $this->assertSame(1, DB::table('order_number_days')->count(), 'one counter row per day');
    }

    public function test_the_counter_is_one_atomic_statement_so_a_second_connection_cannot_take_the_same_number(): void
    {
        $sql = (string) file_get_contents(base_path('app/Domain/Orders/OrderNumbers.php'));

        $this->assertStringContainsString('ON CONFLICT (day) DO UPDATE SET last_value = order_number_days.last_value + 1 RETURNING last_value', $sql);
    }

    public function test_the_invoice_shows_the_number_as_invoice_no(): void
    {
        Carbon::setTestNow($this->dhaka('2026-11-27 10:00:00'));
        $order = $this->placeOrder();

        $html = (string) app(OrderInvoice::class)->printHtml($this->dealer, $order)->getContent();

        $this->assertStringContainsString('Invoice No : MNHC-20261127-100001', $html);
    }

    public function test_the_order_form_the_api_and_the_service_all_give_the_same_number_format_and_a_repeat_post_takes_no_new_number(): void
    {
        Carbon::setTestNow($this->dhaka('2026-11-27 10:00:00'));
        $product = Product::factory()->create(['mrp' => '200.00', 'stock' => 0, 'active' => true]);
        app(StockService::class)->stockIn($this->owner, ['product_id' => $product->id, 'quantity' => 10, 'reference' => (string) Str::uuid(), 'branch_id' => $this->hub->id,
            'batch_number' => 'LOT-A', 'expires_on' => now()->addDays(200)->toDateString()]);

        $this->actingAs($this->dealer)->post('/commands/orders', ['items' => [['product_id' => $product->id, 'quantity' => 1]], 'idempotency_key' => (string) Str::uuid()])
            ->assertSessionHasNoErrors();
        $apiKey = (string) Str::uuid();
        $api = ['items' => [['product_id' => $product->id, 'quantity' => 1]], 'idempotency_key' => $apiKey];
        $created = $this->actingAs($this->dealer)->postJson('/api/v1/orders', $api)->assertCreated();
        $this->actingAs($this->dealer)->postJson('/api/v1/orders', $api)->assertOk();
        $viaService = app(OrderService::class)->create($this->dealer, ['items' => [['product_id' => $product->id, 'quantity' => 1]], 'idempotency_key' => (string) Str::uuid()]);

        $this->assertSame(['MNHC-20261127-100001', 'MNHC-20261127-100002', 'MNHC-20261127-100003'], Order::query()->orderBy('created_at')->orderBy('number')->pluck('number')->all());
        $this->assertSame('MNHC-20261127-100002', $created->json('data.number'), 'the API shows the stored number unchanged');
        $this->assertSame('MNHC-20261127-100003', $viaService->number);
    }

    public function test_the_search_finds_one_order_by_its_full_number_a_day_by_its_date_and_a_sequence_across_days(): void
    {
        Carbon::setTestNow($this->dhaka('2026-11-27 10:00:00'));
        $a = $this->placeOrder();
        Carbon::setTestNow($this->dhaka('2026-11-27 10:05:00'));
        $b = $this->placeOrder();
        Carbon::setTestNow($this->dhaka('2026-11-28 10:00:00'));
        $c = $this->placeOrder();
        $old = Order::create(['number' => 'MN-261121-P3UWUSTO', 'user_id' => $this->dealer->id, 'status' => 'approved', 'total' => '100.00', 'paid' => '0.00',
            'idempotency_key' => (string) Str::uuid(), 'payload_hash' => hash('sha256', Str::random())]);
        $earlierForm = Order::create(['number' => 'MNHC-100001', 'user_id' => $this->dealer->id, 'status' => 'approved', 'total' => '100.00', 'paid' => '0.00',
            'idempotency_key' => (string) Str::uuid(), 'payload_hash' => hash('sha256', Str::random())]);

        $ids = static fn (Order ...$orders): array => array_map(static fn (Order $order): string => (string) $order->id, $orders);
        $listed = static fn (string $query): array => Order::query()->latest('id')->tap(fn ($orders) => app(OrderListFilter::class)->apply($orders, new Request(['q' => $query]), 'orders', true))->pluck('id')->map(fn ($id): string => (string) $id)->all();
        $searched = fn (string $query): array => array_column(app(GlobalSearch::class)->run($this->owner, new Request, $query)['orders'], 'id');

        foreach (['list' => $listed, 'global search' => $searched] as $name => $find) {
            $this->assertSame($ids($a), $find('MNHC-20261127-100001'), $name.': the full number finds exactly one order');
            $this->assertSame($ids($b, $a), $find('MNHC-20261127'), $name.': the date with the prefix lists that day, newest first');
            $this->assertSame($ids($b, $a), $find('20261127'), $name.': the date digits list that day');
            $this->assertSame($ids($c), $find('20261128'), $name);
            $this->assertEqualsCanonicalizing($ids($earlierForm, $c, $a), $find('100001'), $name.': the bare sequence lists every order with it');
            $this->assertSame($ids($old), $find('P3UWUSTO'), $name.': an old number still works');
            $this->assertSame($ids($earlierForm), $find('MNHC-100001'), $name.': the numeric-only form is still found');
        }
        $this->assertSame('MN-261121-P3UWUSTO', $old->fresh()->number, 'an old order keeps its number');
    }
}
