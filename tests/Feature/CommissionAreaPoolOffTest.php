<?php

namespace Tests\Feature;

use App\Domain\Commissions\CommissionService;
use App\Models\CommissionAllocation;
use App\Models\CommissionRuleParameter;
use App\Models\Order;
use App\Models\Product;
use App\Models\User;
use App\Support\Money;
use Database\Seeders\CommissionRuleSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Tests\TestCase;

/**
 * Owner rule: when the Area dealer pool is switched off, the management share that is
 * cut from the same pool stops too. Nothing may be taken for an area that is not paid.
 */
class CommissionAreaPoolOffTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->withoutVite();
        $this->enableFinancialPosting();
        $this->seed(CommissionRuleSeeder::class);
    }

    private function deliveredOrder(string $total): Order
    {
        $buyer = User::factory()->create(['role' => 'dealer', 'status' => 'active']);
        $order = Order::create([
            'idempotency_key' => (string) Str::uuid(),
            'payload_hash' => hash('sha256', 'area-off-'.$total),
            'number' => 'ORD-AREAOFF-'.Str::upper(Str::random(5)),
            'user_id' => $buyer->id,
            'status' => 'delivered',
            'total' => $total,
            'paid' => $total,
        ]);
        $product = Product::factory()->create(['mrp' => Money::decimal(Money::cents($total) * 2)]);
        $order->lines()->create(['product_id' => $product->id, 'name' => $product->name, 'sku' => $product->sku, 'quantity' => 1,
            'mrp' => $product->mrp, 'unit_price' => $total, 'total' => $total]);

        return $order;
    }

    public function test_the_management_share_is_not_taken_while_the_area_pool_is_off(): void
    {
        CommissionRuleParameter::where('rule_key', 'area_dealer_pool')->update(['is_active' => false]);
        $order = $this->deliveredOrder('10000.00');

        app(CommissionService::class)->processOrder($order);

        $area = CommissionAllocation::where('order_id', $order->id)
            ->whereIn('category', ['area_management', 'area_division', 'area_district', 'area_upazila', 'area_union'])
            ->get();
        $this->assertEquals(0.0, (float) $area->sum('amount'), 'No area money is cut while the pool is off.');
    }

    public function test_the_management_share_is_still_taken_while_the_area_pool_is_on(): void
    {
        $order = $this->deliveredOrder('10000.00');

        app(CommissionService::class)->processOrder($order);

        $management = CommissionAllocation::where('order_id', $order->id)->where('category', 'area_management')->first();
        $this->assertNotNull($management);
        $this->assertGreaterThan(0.0, (float) $management->amount);
    }
}
