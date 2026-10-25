<?php

namespace Tests\Feature;

use App\Domain\Commissions\CommissionPosting;
use App\Domain\Orders\OrderService;
use App\Models\AreaDealerAssignment;
use App\Models\CommissionAllocation;
use App\Models\Order;
use App\Models\Product;
use App\Models\User;
use Database\Seeders\CommissionRuleSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Tests\TestCase;

class AreaQuotaHoldReleaseTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->withoutVite();
        $this->travelTo(now()->setDate(2026, 10, 17)->startOfDay());
        $this->seed(CommissionRuleSeeder::class);
    }

    private function postedOrder(User $buyer, string $total = '100.00'): Order
    {
        $product = Product::factory()->create(['mrp' => '200.00', 'dealer_price' => '100.00', 'sub_dealer_price' => '120.00']);
        $order = app(OrderService::class)->create($buyer, [
            'items' => [['product_id' => $product->id, 'quantity' => 1]],
            'idempotency_key' => (string) Str::uuid(),
        ]);
        $order->update(['status' => 'delivered', 'paid' => $total]);
        app(CommissionPosting::class)->process($order);

        return $order;
    }

    public function test_quota_hold_auto_release_is_disabled_by_default(): void
    {
        $this->enableFinancialPosting();
        config(['madina.auto_release_quota_holds' => false]);
        $this->artisan('finance:release-quota-holds')
            ->expectsOutputToContain('No balances changed')
            ->assertSuccessful();
        $this->assertDatabaseCount('journal_entries', 0);
    }

    public function test_auto_release_credits_area_hold_after_original_period_quota_is_met(): void
    {
        $this->enableFinancialPosting();
        config(['madina.auto_release_quota_holds' => true]);

        $area = User::factory()->create(['division' => 'Rangpur', 'role' => 'dealer', 'status' => 'active']);
        $buyer = User::factory()->create(['division' => 'Rangpur', 'role' => 'dealer', 'status' => 'active']);
        AreaDealerAssignment::create([
            'user_id' => $area->id,
            'level' => 'division',
            'division' => 'Rangpur',
            'zone_name' => 'Rangpur',
            'monthly_quota' => '100',
            'is_active' => true,
        ]);

        $order = $this->postedOrder($buyer);
        $hold = CommissionAllocation::where('order_id', $order->id)
            ->where('category', 'area_division')
            ->firstOrFail();
        $this->assertSame('held', $hold->status);

        $this->artisan('finance:release-quota-holds')
            ->expectsOutputToContain('no_qualified_quota_holds')
            ->assertSuccessful();
        $this->assertSame('held', $hold->fresh()->status);

        $this->postedOrder($area);
        $this->artisan('finance:release-quota-holds')
            ->expectsOutputToContain('success')
            ->assertSuccessful();

        $released = $hold->fresh();
        $this->assertContains($released->status, ['available', 'maturing']);
        $this->assertSame('Automatic area quota achieved', $released->metadata['release_reason'] ?? null);
        $this->assertArrayHasKey('released_by', $released->metadata);
        $this->assertNull($released->metadata['released_by']);
    }

    public function test_auto_release_skips_when_financial_activation_is_off(): void
    {
        config([
            'madina.financial_activation' => false,
            'madina.preview_mode' => false,
            'madina.auto_release_quota_holds' => true,
        ]);
        $this->artisan('finance:release-quota-holds')
            ->expectsOutputToContain('No balances changed')
            ->assertSuccessful();
    }
}
