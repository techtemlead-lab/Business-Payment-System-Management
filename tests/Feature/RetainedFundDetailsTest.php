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

class RetainedFundDetailsTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->withoutVite();
        config(['madina.financial_activation' => true]);
    }

    public function test_treasury_and_benefits_fund_expose_detailed_retained_amount_rows(): void
    {
        $buyer = User::factory()->dealer()->create([
            'division' => 'Rangpur',
            'district' => 'Pirganj',
            'profile' => ['upazila' => 'Pirganj', 'union' => 'Khalashpir'],
        ]);
        $product = Product::factory()->create(['mrp' => '200.00', 'dealer_price' => '120.00']);
        $order = app(OrderService::class)->create($buyer, [
            'items' => [['product_id' => $product->id, 'quantity' => 1]],
            'idempotency_key' => (string) Str::uuid(),
        ]);
        $finance = User::factory()->staff('finance')->create();
        $this->actingAs($finance)->postJson('/api/v1/orders/'.$order->id.'/decision', [
            'status' => 'approved',
            'allow_credit' => true,
            'reason' => 'Approve for retained fund detail coverage',
        ])->assertOk();
        $order->refresh()->update(['paid' => $order->total, 'status' => 'delivered']);
        app(CommissionPosting::class)->process($order->fresh());

        $this->actingAs($finance)->onPortal('manage')->get('/treasury')
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->has('data.retained_fund_details.unassigned_geographic')
                ->has('data.retained_fund_details.company_share_batches', 1)
                ->where('data.retained_fund_details.company_share_batches.0.order_number', $order->number)
                ->where('data.retained_fund_details.company_share_batches.0.company_share', '60.00')
                ->where('data.retained_fund_details.company_share_batches.0.division', 'Rangpur')
                ->where('data.retained_fund_details.unassigned_geographic.0.district', 'Pirganj')
                ->where('data.retained_fund_details.unassigned_geographic.0.amount', '2.40')
                ->missing('data.retained_fund_details.benefits_fund.0'));

        $owner = User::factory()->create(['role' => 'super_admin']);
        $this->actingAs($owner)->onPortal('sadmin')->get('/treasury')
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->has('data.retained_fund_details.benefits_fund', 1)
                ->where('data.retained_fund_details.benefits_fund.0.amount', '12.00')
                ->where('data.retained_fund_details.benefits_fund.0.buyer_name', $buyer->name));

        $this->actingAs($owner)->onPortal('sadmin')->get('/benefits-fund')
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->has('data.benefits_entries.data', 1)
                ->where('data.benefits_entries.data.0.amount', '12.00')
                ->where('data.benefits_entries.data.0.company_share', '60.00')
                ->where('data.benefits_entries.data.0.total_allocated', '48.00')
                ->where('data.benefits_entries.data.0.division', 'Rangpur')
                ->where('data.benefits_entries.data.0.district', 'Pirganj')
                ->where('data.benefits_entries.data.0.reason', 'Purchase surplus from dealer order after company MRP share and the approved commission schedule.'));
    }

    public function test_sub_dealer_purchases_post_detailed_unassigned_and_benefits_fund_rows(): void
    {
        $sponsor = User::factory()->dealer()->create();
        $buyer = User::factory()->subDealer()->create([
            'sponsor_id' => $sponsor->id,
            'division' => 'Rangpur',
            'district' => 'Rangpur',
            'profile' => ['upazila' => 'Sadar', 'union' => 'Mahiganj'],
        ]);
        // 30% discount (pay 140 of MRP 200) leaves surplus under the 50% sub-dealer schedule.
        $product = Product::factory()->create(['mrp' => '200.00', 'dealer_price' => '140.00', 'sub_dealer_price' => '140.00']);
        $order = app(OrderService::class)->create($buyer, [
            'items' => [['product_id' => $product->id, 'quantity' => 1]],
            'idempotency_key' => (string) Str::uuid(),
        ]);
        $finance = User::factory()->staff('finance')->create();
        $this->actingAs($finance)->postJson('/api/v1/orders/'.$order->id.'/decision', [
            'status' => 'approved',
            'allow_credit' => true,
            'reason' => 'Approve sub-dealer retained fund coverage',
        ])->assertOk();
        $order->refresh()->update(['paid' => $order->total, 'status' => 'delivered']);
        app(CommissionPosting::class)->process($order->fresh());

        // cash 140, company 60, commissions 70, benefits surplus 10; district slice 2% = 2.80
        $this->assertDatabaseHas('commission_batches', [
            'order_id' => $order->id,
            'account_type' => 'sub_dealer',
            'cash_basis' => '140.00',
            'company_share' => '60.00',
            'total_allocated' => '70.00',
            'unallocated' => '10.00',
        ]);
        $this->assertDatabaseHas('commission_allocations', [
            'order_id' => $order->id,
            'category' => 'company_commission_benefits_fund',
            'amount' => '10.00',
            'status' => 'reserved',
        ]);

        $owner = User::factory()->create(['role' => 'super_admin']);
        $this->actingAs($owner)->onPortal('sadmin')->get('/treasury')
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->where('data.retained_fund_details.company_share_batches.0.buyer_role', 'sub_dealer')
                ->where('data.retained_fund_details.unassigned_geographic.0.buyer_role', 'sub_dealer')
                ->where('data.retained_fund_details.unassigned_geographic.0.amount', '2.80')
                ->where('data.retained_fund_details.benefits_fund.0.amount', '10.00')
                ->where('data.retained_fund_details.benefits_fund.0.buyer_role', 'sub_dealer'));

        $this->actingAs($owner)->onPortal('sadmin')->get('/benefits-fund')
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->where('data.benefits_entries.data.0.buyer_role', 'sub_dealer')
                ->where('data.benefits_entries.data.0.amount', '10.00')
                ->where('data.benefits_entries.data.0.company_share', '60.00')
                ->where('data.benefits_entries.data.0.total_allocated', '70.00')
                ->where('data.benefits_entries.data.0.reason', 'Purchase surplus from sub-dealer order after company MRP share and the approved commission schedule.'));
    }
}
