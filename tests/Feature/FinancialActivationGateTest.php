<?php

namespace Tests\Feature;

use App\Domain\Commissions\CommissionWallet;
use App\Domain\Orders\OrderService;
use App\Models\Product;
use App\Models\User;
use Database\Seeders\CommissionRuleSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Tests\TestCase;

class FinancialActivationGateTest extends TestCase
{
    use RefreshDatabase;

    public function test_require_active_blocks_posting_without_verified_openings_even_outside_production(): void
    {
        config([
            'madina.financial_activation' => true,
            'madina.preview_mode' => false,
            'app.env' => 'staging',
        ]);

        try {
            CommissionWallet::requireActive();
            $this->fail('Expected ValidationException when openings are unverified');
        } catch (ValidationException $exception) {
            $this->assertSame(
                __('portal.err_wallet_posting_unverified'),
                $exception->errors()['policy'][0] ?? null,
            );
        }
    }

    public function test_require_active_allows_posting_after_verified_openings(): void
    {
        $this->enableFinancialPosting();
        CommissionWallet::requireActive();
        $this->assertTrue(true);
    }

    public function test_manual_commission_post_api_reports_posted_false_when_order_is_ineligible(): void
    {
        $this->enableFinancialPosting();
        $this->seed(CommissionRuleSeeder::class);
        $finance = User::factory()->staff('finance')->create();
        $buyer = User::factory()->dealer()->create();
        $product = Product::factory()->create(['mrp' => '200.00', 'dealer_price' => '100.00', 'stock' => 2]);
        $order = app(OrderService::class)->create($buyer, [
            'items' => [['product_id' => $product->id, 'quantity' => 1]],
            'idempotency_key' => (string) Str::uuid(),
        ]);

        $this->actingAs($finance)
            ->postJson('/api/v1/finance/orders/'.$order->id.'/commissions')
            ->assertStatus(409)
            ->assertJson([
                'posted' => false,
                'data' => [],
            ])
            ->assertJsonPath('reason', __('portal.err_commission_not_posted'));
    }
}
