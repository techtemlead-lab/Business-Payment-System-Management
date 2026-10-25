<?php

namespace Tests\Feature;

use App\Domain\Commissions\CommissionPosting;
use App\Domain\Commissions\CommissionSettlement;
use App\Domain\Finance\ReserveService;
use App\Domain\Orders\OrderService;
use App\Models\AreaDealerAssignment;
use App\Models\CommissionAllocation;
use App\Models\Product;
use App\Models\User;
use Database\Seeders\CommissionRuleSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\TestCase;

class PartnerMoneyTrailLocaleTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->withoutVite();
        $this->travelTo(now()->setDate(2026, 10, 17)->startOfDay());
        $this->seed(CommissionRuleSeeder::class);
        $this->enableFinancialPosting();
    }

    public function test_order_commission_journal_uses_buyer_preferred_locale(): void
    {
        $buyer = User::factory()->create(['role' => 'dealer', 'status' => 'active', 'locale' => 'bn']);
        $product = Product::factory()->create(['mrp' => '200.00', 'dealer_price' => '100.00']);
        $order = app(OrderService::class)->create($buyer, [
            'items' => [['product_id' => $product->id, 'quantity' => 1]],
            'idempotency_key' => (string) Str::uuid(),
        ]);
        $order->update(['status' => 'delivered', 'paid' => '100.00']);
        app(CommissionPosting::class)->process($order);

        $description = DB::table('journal_entries')
            ->where('event_key', 'commissions.order.'.$order->id)
            ->value('description');

        $this->assertSame(
            __('portal.jr_commissions_earned', ['number' => $order->number], 'bn'),
            $description,
        );
    }

    public function test_hold_release_journal_and_notice_use_partner_locale(): void
    {
        $admin = User::factory()->create(['role' => 'finance', 'status' => 'active']);
        $area = User::factory()->create([
            'role' => 'dealer',
            'status' => 'active',
            'locale' => 'bn',
            'division' => 'Rangpur',
        ]);
        $buyer = User::factory()->create([
            'role' => 'dealer',
            'status' => 'active',
            'division' => 'Rangpur',
        ]);
        AreaDealerAssignment::create([
            'user_id' => $area->id,
            'level' => 'division',
            'division' => 'Rangpur',
            'zone_name' => 'Rangpur',
            'monthly_quota' => '100',
            'is_active' => true,
        ]);

        $product = Product::factory()->create(['mrp' => '200.00', 'dealer_price' => '100.00']);
        $order = app(OrderService::class)->create($buyer, [
            'items' => [['product_id' => $product->id, 'quantity' => 1]],
            'idempotency_key' => (string) Str::uuid(),
        ]);
        $order->update(['status' => 'delivered', 'paid' => '100.00']);
        app(CommissionPosting::class)->process($order);

        $hold = CommissionAllocation::where('order_id', $order->id)
            ->where('category', 'area_division')
            ->firstOrFail();
        $this->assertSame('held', $hold->status);

        $quotaOrder = app(OrderService::class)->create($area, [
            'items' => [['product_id' => $product->id, 'quantity' => 1]],
            'idempotency_key' => (string) Str::uuid(),
        ]);
        $quotaOrder->update(['status' => 'delivered', 'paid' => '100.00']);
        app(CommissionPosting::class)->process($quotaOrder);

        app(ReserveService::class)->release($admin, $hold->id, 'Original monthly quota achieved');

        $description = DB::table('journal_entries')
            ->where('event_key', 'commission.hold_release.'.$hold->id)
            ->value('description');
        $this->assertSame(__('portal.jr_hold_release', [], 'bn'), $description);

        $notice = DB::table('account_notices')->where('user_id', $area->id)->orderByDesc('id')->first();
        $this->assertNotNull($notice);
        $this->assertSame(__('portal.notice_commission_released_title', [], 'bn'), $notice->title);
        $this->assertStringContainsString(
            __('portal.report_cat_area_division', [], 'bn'),
            (string) $notice->body,
        );
    }

    public function test_maturity_release_journal_uses_partner_locale(): void
    {
        $cooling = \App\Models\CommissionRuleParameter::getRule('commission_cooling_off');
        $cooling?->update(['parameters' => ['cooling_off_days' => 7]]);

        $partner = User::factory()->create(['role' => 'dealer', 'status' => 'active', 'locale' => 'bn']);
        $product = Product::factory()->create(['mrp' => '200.00', 'dealer_price' => '100.00']);
        $order = app(OrderService::class)->create($partner, [
            'items' => [['product_id' => $product->id, 'quantity' => 1]],
            'idempotency_key' => (string) Str::uuid(),
        ]);
        $order->update(['status' => 'delivered', 'paid' => '100.00']);
        app(CommissionPosting::class)->process($order);

        $maturing = CommissionAllocation::where('order_id', $order->id)
            ->where('user_id', $partner->id)
            ->where('status', 'maturing')
            ->get();
        $this->assertNotEmpty($maturing);
        foreach ($maturing as $allocation) {
            $meta = $allocation->metadata ?? [];
            $meta['maturation_due_at'] = now()->subMinute()->toIso8601String();
            $allocation->update(['metadata' => $meta]);
        }

        app(CommissionSettlement::class)->release();

        $description = DB::table('journal_entries')
            ->where('event_key', 'commissions.matured.'.$maturing->first()->id)
            ->value('description');
        $this->assertSame(__('portal.jr_commission_matured', [], 'bn'), $description);
    }
}
