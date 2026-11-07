<?php

namespace Tests\Feature;

use App\Domain\Commissions\CommissionPosting;
use App\Domain\Orders\OrderService;
use App\Models\AreaDealerAssignment;
use App\Models\CommissionAllocation;
use App\Models\Order;
use App\Models\PlaceCode;
use App\Models\Product;
use App\Models\User;
use Database\Seeders\CommissionRuleSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
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

        $rangpur = PlaceCode::factory()->division()->create(['name' => 'Rangpur']);
        $area = User::factory()->inPlace($rangpur)->create(['role' => 'dealer', 'status' => 'active']);
        $buyer = User::factory()->inPlace($rangpur)->create(['role' => 'dealer', 'status' => 'active']);
        AreaDealerAssignment::create([
            'user_id' => $area->id,
            'level' => 'division',
            'place_code_id' => $rangpur->id,
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
        $this->assertSame('release_reason_quota_auto', $released->metadata['release_reason'] ?? null);
        $this->assertArrayHasKey('released_by', $released->metadata);
        $this->assertNull($released->metadata['released_by']);
    }

    public function test_release_pays_only_dealers_who_met_the_quota_and_reads_each_dealer_once(): void
    {
        $this->enableFinancialPosting();
        config(['madina.auto_release_quota_holds' => true]);
        $rangpur = PlaceCode::factory()->division()->create(['name' => 'Rangpur']);
        $dhaka = PlaceCode::factory()->division()->create(['name' => 'Dhaka']);
        $metQuota = User::factory()->inPlace($rangpur)->create(['role' => 'dealer', 'status' => 'active']);
        $missedQuota = User::factory()->inPlace($dhaka)->create(['role' => 'dealer', 'status' => 'active']);
        foreach ([[$metQuota, $rangpur, '100'], [$missedQuota, $dhaka, '100000']] as [$dealer, $place, $quota]) {
            AreaDealerAssignment::create(['user_id' => $dealer->id, 'level' => 'division', 'place_code_id' => $place->id,
                'division' => $place->name, 'zone_name' => $place->name, 'monthly_quota' => $quota, 'is_active' => true]);
        }
        $rangpurBuyer = User::factory()->inPlace($rangpur)->create(['role' => 'dealer', 'status' => 'active']);
        $dhakaBuyer = User::factory()->inPlace($dhaka)->create(['role' => 'dealer', 'status' => 'active']);
        for ($i = 0; $i < 3; $i++) {
            $this->postedOrder($rangpurBuyer);
            $this->postedOrder($dhakaBuyer);
        }
        $this->assertSame(6, CommissionAllocation::where('category', 'area_division')->where('status', 'held')->count());
        $this->postedOrder($metQuota);

        $volumeQueries = 0;
        DB::listen(function ($query) use (&$volumeQueries): void {
            if (str_contains($query->sql, 'cash_basis - refunded_amount')) {
                $volumeQueries++;
            }
        });
        $this->artisan('finance:release-quota-holds')->assertSuccessful();

        // One volume read for all held shares, plus the safety re-check for each share actually paid;
        // the dealer who missed the quota costs no extra reads however many holds pile up.
        $released = CommissionAllocation::where('category', 'area_division')->where('user_id', $metQuota->id)->where('status', '<>', 'held')->count();
        $this->assertGreaterThanOrEqual(3, $released);
        $this->assertSame(1 + $released, $volumeQueries);
        $this->assertSame(0, CommissionAllocation::where('category', 'area_division')->where('user_id', $metQuota->id)->where('status', 'held')->count());
        $this->assertSame(3, CommissionAllocation::where('category', 'area_division')->where('user_id', $missedQuota->id)->where('status', 'held')->count());
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
