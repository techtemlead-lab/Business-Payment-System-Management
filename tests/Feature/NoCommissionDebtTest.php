<?php

namespace Tests\Feature;

use App\Domain\Finance\CompanyFunds;
use App\Domain\Orders\OrderService;
use App\Models\CommissionAllocation;
use App\Models\Product;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Tests\TestCase;

/**
 * MA-26 (audit m5): a "no commission" row counts the whole commission as company profit, including
 * what partners could not pay back. That part shows in the balance but cannot be spent until recovered.
 */
class NoCommissionDebtTest extends TestCase
{
    use RefreshDatabase;

    public function test_money_partners_still_owe_is_in_the_balance_but_not_spendable(): void
    {
        $product = Product::factory()->create(['mrp' => '2000.00', 'dealer_price' => '1000.00']);
        $order = app(OrderService::class)->create(User::factory()->dealer()->create(), ['items' => [['product_id' => $product->id, 'quantity' => 1]], 'idempotency_key' => (string) Str::uuid()]);
        $source = CommissionAllocation::create(['order_id' => $order->id, 'user_id' => null, 'category' => 'company_marketing_fund', 'rate_percentage' => '2.00',
            'cash_basis' => '1000.00', 'amount' => '0.00', 'original_amount' => '20.00', 'status' => 'reversed', 'metadata' => []]);
        $before = app(CompanyFunds::class)->balances()['company_profit'];

        CommissionAllocation::create(['order_id' => $order->id, 'user_id' => null, 'category' => CompanyFunds::NoCommissionCategory, 'rate_percentage' => '40.00',
            'cash_basis' => '1000.00', 'amount' => '400.00', 'original_amount' => '400.00', 'status' => 'reserved',
            'metadata' => ['reason' => 'Dealer refused the rest', 'retained_by' => 'company_profit_fund', 'source_allocation_id' => $source->id,
                'taken_back' => '370.00', 'still_owed' => '30.00']]);

        $after = app(CompanyFunds::class)->balances()['company_profit'];
        $this->assertSame($before['balance'] + 40000, $after['balance']);
        $this->assertSame($before['available'] + 37000, $after['available']);
    }
}
