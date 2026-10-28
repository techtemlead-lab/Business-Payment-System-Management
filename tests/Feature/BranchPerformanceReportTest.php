<?php

namespace Tests\Feature;

use App\Domain\Inventory\BranchPerformance;
use App\Models\CompanyBranch;
use App\Models\Product;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\TestCase;

class BranchPerformanceReportTest extends TestCase
{
    use RefreshDatabase;

    public function test_owner_can_view_branch_performance_breakdown_for_period(): void
    {
        $owner = User::factory()->staff('super_admin')->create();
        $partner = User::factory()->dealer()->create();
        $hub = CompanyBranch::query()->where('code', 'HO-PIRGANJ')->firstOrFail();
        $other = CompanyBranch::factory()->create(['code' => 'DHK-02', 'name' => 'Dhaka Hub']);
        $product = Product::factory()->create([
            'stock' => 20,
            'mrp' => '500.00',
            'dealer_price' => '400.00',
            'cost_price' => '250.00',
        ]);

        $this->createFulfilledOrder($partner, $product, $hub->id, '400.00', '400.00');
        $this->createFulfilledOrder($partner, $product, $other->id, '400.00', '0.00');
        $this->createFulfilledOrder($partner, $product, null, '400.00', '100.00');

        $this->actingAs($owner)
            ->onPortal('sadmin')
            ->get('/branch-performance?period=month')
            ->assertOk();

        $summary = app(BranchPerformance::class)->summary($owner, ['period' => 'month']);
        $this->assertSame(3, $summary['totals']['order_count']);
        $this->assertSame('1200.00', $summary['totals']['order_value']);
        $this->assertSame('500.00', $summary['totals']['income']);
        $this->assertSame('450.00', $summary['totals']['estimated_profit']);

        $byCode = collect($summary['branches'])->keyBy('code');
        $this->assertSame(1, $byCode['HO-PIRGANJ']['order_count']);
        $this->assertSame('400.00', $byCode['HO-PIRGANJ']['income']);
        $this->assertSame(1, $byCode['DHK-02']['order_count']);
        $this->assertSame('0.00', $byCode['DHK-02']['income']);
        $this->assertSame(1, $summary['unassigned']['order_count']);
    }

    public function test_product_editor_public_link_uses_public_portal_host(): void
    {
        $owner = User::factory()->staff('super_admin')->create();
        $product = Product::factory()->create(['active' => true, 'stock' => 5]);

        $response = $this->actingAs($owner)
            ->onPortal('sadmin')
            ->get('/products/'.$product->id)
            ->assertOk()
            ->assertInertia(fn ($page) => $page
                ->component('ProductEditor')
                ->has('product', fn ($p) => $p->where('id', $product->id)->etc())
                ->where('publicBase', fn ($base) => is_string($base) && $base !== ''));

        $publicBase = (string) $response->original->getData()['page']['props']['publicBase'];
        $this->assertStringNotContainsString('/sadmin', $publicBase);
        $this->assertStringNotContainsString('/manage', $publicBase);

        $this->get($publicBase.'/products/'.$product->id)->assertOk();
    }

    private function createFulfilledOrder(User $partner, Product $product, ?int $branchId, string $total, string $paid): void
    {
        $orderId = (string) Str::uuid();
        DB::table('orders')->insert([
            'id' => $orderId,
            'number' => 'MN-TEST-'.Str::upper(Str::random(6)),
            'user_id' => $partner->id,
            'status' => 'dispatched',
            'total' => $total,
            'paid' => $paid,
            'due_credit' => '0.00',
            'idempotency_key' => (string) Str::uuid(),
            'payload_hash' => hash('sha256', $orderId),
            'fulfillment_branch_id' => $branchId,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
        DB::table('order_lines')->insert([
            'order_id' => $orderId,
            'product_id' => $product->id,
            'name' => $product->name,
            'sku' => $product->sku,
            'quantity' => 1,
            'dispatched' => 1,
            'mrp' => $product->mrp,
            'unit_price' => $total,
            'total' => $total,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }
}
