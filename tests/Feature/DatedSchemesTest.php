<?php

namespace Tests\Feature;

use App\Domain\Orders\OrderService;
use App\Models\DatedScheme;
use App\Models\Product;
use App\Models\User;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\TestCase;

class DatedSchemesTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->withoutVite();
        Http::fake();
    }

    public function test_owner_defines_a_scheme_and_partners_see_it_at_order_time(): void
    {
        Carbon::setTestNow('2026-11-10 06:00:00');
        $owner = User::factory()->create(['role' => 'md', 'status' => 'active']);
        $dealer = User::factory()->dealer()->create();
        $product = Product::factory()->create(['active' => true, 'name' => 'Vitamin C']);

        $this->actingAs($owner)->onPortal('sadmin')->post('/commands/schemes', $this->payload([$product->id]))
            ->assertRedirect()->assertSessionHasNoErrors();

        $this->actingAs($dealer)->onPortal('dealer')->get('/purchase')
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->where('data.running_schemes.0.title', 'Winter offer')
                ->where('data.running_schemes.0.product_ids', [$product->id])
                ->where('data.running_schemes.0.min_quantity', 10)
                ->where('data.running_schemes.0.benefit_text', 'One box free with ten'));
    }

    public function test_schemes_outside_their_dates_are_not_shown(): void
    {
        Carbon::setTestNow('2026-11-10 06:00:00');
        $owner = User::factory()->create(['role' => 'super_admin', 'status' => 'active']);
        $dealer = User::factory()->dealer()->create();
        $product = Product::factory()->create(['active' => true]);
        DatedScheme::query()->create([...$this->payload([$product->id]), 'starts_on' => '2026-10-01', 'ends_on' => '2026-10-31', 'created_by' => $owner->id]);
        DatedScheme::query()->create([...$this->payload([$product->id]), 'starts_on' => '2026-12-01', 'ends_on' => '2026-12-31', 'created_by' => $owner->id]);

        $this->actingAs($dealer)->onPortal('dealer')->get('/purchase')
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page->where('data.running_schemes', []));
    }

    public function test_only_owners_define_schemes(): void
    {
        $dealer = User::factory()->dealer()->create();
        $staff = User::factory()->create(['role' => 'employee', 'status' => 'active']);
        $product = Product::factory()->create(['active' => true]);

        $this->actingAs($dealer)->onPortal('dealer')->post('/commands/schemes', $this->payload([$product->id]))->assertForbidden();
        $this->actingAs($staff)->onPortal('sadmin')->post('/commands/schemes', $this->payload([$product->id]))->assertForbidden();
        $this->assertSame(0, DatedScheme::query()->count());
    }

    public function test_end_date_must_not_be_before_the_start(): void
    {
        $owner = User::factory()->create(['role' => 'super_admin', 'status' => 'active']);
        $product = Product::factory()->create(['active' => true]);

        $this->actingAs($owner)->onPortal('sadmin')->post('/commands/schemes', [...$this->payload([$product->id]), 'ends_on' => '2026-10-31'])
            ->assertSessionHasErrors('ends_on');
    }

    public function test_a_running_scheme_changes_nothing_on_the_order(): void
    {
        Carbon::setTestNow('2026-11-10 06:00:00');
        $owner = User::factory()->create(['role' => 'super_admin', 'status' => 'active']);
        $dealer = User::factory()->dealer()->create();
        $product = Product::factory()->create(['active' => true, 'dealer_price' => '100.00', 'mrp' => '200.00', 'stock' => 50]);
        DatedScheme::query()->create([...$this->payload([$product->id]), 'created_by' => $owner->id]);

        $order = app(OrderService::class)->create($dealer, ['items' => [['product_id' => $product->id, 'quantity' => 10]], 'idempotency_key' => (string) Str::uuid()]);

        $this->assertSame('1000.00', (string) $order->total);
        $this->assertSame(1, DB::table('order_lines')->where('order_id', $order->id)->count());
        $this->assertSame('100.00', (string) DB::table('order_lines')->where('order_id', $order->id)->value('unit_price'));
    }

    /**
     * @param  list<int>  $productIds
     * @return array<string, mixed>
     */
    private function payload(array $productIds): array
    {
        return [
            'title' => 'Winter offer',
            'starts_on' => '2026-11-01',
            'ends_on' => '2026-11-30',
            'product_ids' => $productIds,
            'min_quantity' => 10,
            'benefit_type' => 'free_goods',
            'benefit_text' => 'One box free with ten',
        ];
    }
}
