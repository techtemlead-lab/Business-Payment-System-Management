<?php

namespace Tests\Feature;

use App\Domain\Commissions\CommissionPosting;
use App\Domain\Orders\OrderService;
use App\Models\Product;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\TestCase;

class CompanyCommissionBenefitsFundTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->withoutVite();
        $this->enableFinancialPosting();
    }

    public function test_purchase_surplus_is_posted_to_the_owner_commission_benefits_fund(): void
    {
        $buyer = User::factory()->dealer()->create(['division' => 'Rangpur', 'district' => 'Rangpur']);
        Product::factory()->create(['mrp' => '200.00', 'dealer_price' => '120.00']);
        $product = Product::query()->firstOrFail();
        $order = app(OrderService::class)->create($buyer, [
            'items' => [['product_id' => $product->id, 'quantity' => 1]],
            'idempotency_key' => (string) Str::uuid(),
        ]);
        $finance = User::factory()->staff('finance')->create();
        $this->actingAs($finance)->postJson('/api/v1/orders/'.$order->id.'/decision', [
            'status' => 'approved',
            'allow_credit' => true,
            'reason' => 'Approve funded 40% discount purchase',
        ])->assertOk();
        $order->refresh()->update(['paid' => $order->total, 'status' => 'delivered']);
        app(CommissionPosting::class)->process($order->fresh());

        // MRP 200, pay 120 → company 60 + commissions 48 + surplus 12.
        $this->assertDatabaseHas('commission_batches', [
            'order_id' => $order->id,
            'cash_basis' => '120.00',
            'company_share' => '60.00',
            'total_allocated' => '48.00',
            'unallocated' => '12.00',
        ]);
        $this->assertDatabaseHas('commission_allocations', [
            'order_id' => $order->id,
            'category' => 'company_commission_benefits_fund',
            'user_id' => null,
            'amount' => '12.00',
            'status' => 'reserved',
        ]);
    }

    public function test_benefits_fund_is_visible_only_on_the_owner_portal(): void
    {
        $buyer = User::factory()->dealer()->create();
        $product = Product::factory()->create(['mrp' => '200.00', 'dealer_price' => '120.00']);
        $order = app(OrderService::class)->create($buyer, [
            'items' => [['product_id' => $product->id, 'quantity' => 1]],
            'idempotency_key' => (string) Str::uuid(),
        ]);
        $finance = User::factory()->staff('finance')->create();
        $this->actingAs($finance)->postJson('/api/v1/orders/'.$order->id.'/decision', [
            'status' => 'approved',
            'allow_credit' => true,
            'reason' => 'Approve for benefits fund visibility checks',
        ])->assertOk();
        $order->refresh()->update(['paid' => $order->total, 'status' => 'delivered']);
        app(CommissionPosting::class)->process($order->fresh());

        $owner = User::factory()->create(['role' => 'super_admin']);
        $this->actingAs($owner)->onPortal('sadmin')->get('/benefits-fund')
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->component('portals/sadmin/Dashboard')
                ->where('section', 'benefits-fund')
                ->where('data.benefits_fund.balance', 12)
                ->has('data.benefits_entries.data', 1));

        $this->actingAs($finance)->onPortal('manage')->get('/benefits-fund')->assertNotFound();
        $this->actingAs($finance)->onPortal('manage')->get('/treasury')
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->missing('data.flow.unallocated_pool_buffer')
                ->missing('data.flow.commission_benefits_fund')
                ->missing('data.flow.owner_benefits_fund_visible')
                ->has('data.payment_accounts'));

        $this->actingAs($owner)->onPortal('sadmin')->get('/treasury')
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->where('data.flow.owner_benefits_fund_visible', true)
                ->where('data.flow.commission_benefits_fund', 12)
                ->has('data.payment_accounts'));
    }

    public function test_sub_dealer_purchase_surplus_funds_the_owner_benefits_fund(): void
    {
        $sponsor = User::factory()->dealer()->create();
        $buyer = User::factory()->subDealer()->create(['sponsor_id' => $sponsor->id, 'division' => 'Rangpur']);
        $product = Product::factory()->create(['mrp' => '200.00', 'dealer_price' => '140.00', 'sub_dealer_price' => '140.00']);
        $order = app(OrderService::class)->create($buyer, [
            'items' => [['product_id' => $product->id, 'quantity' => 1]],
            'idempotency_key' => (string) Str::uuid(),
        ]);
        $finance = User::factory()->staff('finance')->create();
        $this->actingAs($finance)->postJson('/api/v1/orders/'.$order->id.'/decision', [
            'status' => 'approved',
            'allow_credit' => true,
            'reason' => 'Approve sub-dealer benefits fund posting',
        ])->assertOk();
        $order->refresh()->update(['paid' => $order->total, 'status' => 'delivered']);
        app(CommissionPosting::class)->process($order->fresh());

        $this->assertDatabaseHas('commission_batches', [
            'order_id' => $order->id,
            'account_type' => 'sub_dealer',
            'unallocated' => '10.00',
        ]);
        $this->assertDatabaseHas('commission_allocations', [
            'order_id' => $order->id,
            'category' => 'company_commission_benefits_fund',
            'amount' => '10.00',
        ]);
    }
}
