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
                ->where('data.benefits_entries.data.0.reason', 'Purchase surplus after company MRP share and the approved commission schedule.'));
    }
}
