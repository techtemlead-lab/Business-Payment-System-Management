<?php

namespace Tests\Feature;

use App\Domain\Commissions\CommissionPosting;
use App\Domain\Commissions\RetainedFundLedger;
use App\Domain\Orders\OrderService;
use App\Models\PlaceCode;
use App\Models\Product;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Illuminate\Testing\Fluent\AssertableJson;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\TestCase;

class RetainedFundDetailsTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->withoutVite();
        $this->enableFinancialPosting();
    }

    public function test_treasury_and_benefits_fund_expose_detailed_retained_amount_rows(): void
    {
        // Area shares and their place names come from the buyer's place code.
        $district = PlaceCode::factory()->district(PlaceCode::factory()->division()->create(['name' => 'Rangpur']))->create(['name' => 'Pirganj']);
        $union = PlaceCode::factory()->union(PlaceCode::factory()->upazila($district)->create(['name' => 'Pirganj']))->create(['name' => 'Khalashpir']);
        $buyer = User::factory()->dealer()->inPlace($union)->create();
        $product = Product::factory()->create(['mrp' => '200.00', 'dealer_price' => '120.00']);
        $order = app(OrderService::class)->create($buyer, [
            'items' => [['product_id' => $product->id, 'quantity' => 1]],
            'idempotency_key' => (string) Str::uuid(),
        ]);
        $finance = User::factory()->staff('finance')->create();
        $order->forceFill(['paid' => $order->fresh()->total])->save(); // The dealer pays in full before approval.
        $this->actingAs($finance)->postJson('/api/v1/orders/'.$order->id.'/decision', [
            'status' => 'approved',
            'reason' => 'Approve for retained fund detail coverage',
        ])->assertOk();
        $order->refresh()->update(['paid' => $order->total, 'status' => 'delivered']);
        app(CommissionPosting::class)->process($order->fresh());

        $this->assertDetails(app(RetainedFundLedger::class)->detail(false), fn (AssertableJson $page) => $page
            ->has('data.retained_fund_details.company_profit')
            ->has('data.retained_fund_details.company_share_batches', 1)
            ->where('data.retained_fund_details.company_share_batches.0.order_number', $order->number)
            ->where('data.retained_fund_details.company_share_batches.0.company_share', '60.00')
            ->where('data.retained_fund_details.company_share_batches.0.division', 'Rangpur')
                // Owner rule: an area level with no dealer seat is company profit at order time.
            ->where('data.retained_fund_details.company_profit', fn ($rows): bool => collect($rows)->contains(fn ($row): bool => ($row['district'] ?? null) === 'Pirganj' && $row['amount'] === '2.40'))
            ->missing('data.retained_fund_details.company_share_batches.0.batch_unallocated')
            ->missing('data.retained_fund_details.benefits_fund.0'));

        $owner = User::factory()->create(['role' => 'super_admin']);
        $this->assertDetails(app(RetainedFundLedger::class)->detail(true), fn (AssertableJson $page) => $page
            ->has('data.retained_fund_details.benefits_fund', 1)
            ->where('data.retained_fund_details.benefits_fund.0.amount', '12.00')
            ->where('data.retained_fund_details.benefits_fund.0.buyer_name', $buyer->name));

        $this->actingAs($owner)->onPortal('sadmin')->get('/treasury/funds/benefits?period=')
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->has('table.rows.data', 1)
                ->where('table.rows.data.0.amount', '12.00')
                ->where('table.rows.data.0.order_number', $order->number));
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
        $order->forceFill(['paid' => $order->fresh()->total])->save(); // The dealer pays in full before approval.
        $this->actingAs($finance)->postJson('/api/v1/orders/'.$order->id.'/decision', [
            'status' => 'approved',
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
        $this->assertDetails(app(RetainedFundLedger::class)->detail(true), fn (AssertableJson $page) => $page
            ->where('data.retained_fund_details.company_share_batches.0.buyer_role', 'sub_dealer')
            ->where('data.retained_fund_details.company_profit', fn ($rows): bool => collect($rows)->contains(fn ($row): bool => ($row['buyer_role'] ?? null) === 'sub_dealer' && $row['amount'] === '2.80'))
            ->where('data.retained_fund_details.benefits_fund.0.amount', '10.00')
            ->where('data.retained_fund_details.benefits_fund.0.buyer_role', 'sub_dealer'));

        $this->actingAs($owner)->onPortal('sadmin')->get('/treasury/funds/benefits?period=')
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->where('table.rows.data.0.partner_role', 'sub_dealer')
                ->where('table.rows.data.0.amount', '10.00'));
    }

    /**
     * @param  array<string, mixed>  $details
     * @param  \Closure(AssertableJson): mixed  $assertions
     */
    private function assertDetails(array $details, \Closure $assertions): void
    {
        $assertions(AssertableJson::fromArray(['data' => ['retained_fund_details' => json_decode((string) json_encode($details), true)]]));
    }
}
