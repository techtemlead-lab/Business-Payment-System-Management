<?php

namespace Tests\Feature;

use App\Domain\Commissions\CommissionService;
use App\Domain\Orders\OrderService;
use App\Models\Order;
use App\Models\Product;
use App\Models\User;
use Database\Seeders\CommissionRuleSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Config;
use Illuminate\Support\Str;
use Tests\TestCase;

class SettlementCreditSmsTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->withoutVite();
        config(['madina.financial_activation' => true]);
        $this->seed(CommissionRuleSeeder::class);
        $this->travelTo(now()->setDate(2026, 9, 15)->startOfDay());
        Config::set('madina.sms.driver', 'array');
        Config::set('madina.sms.sent', []);
    }

    protected function tearDown(): void
    {
        $this->travelBack();
        parent::tearDown();
    }

    public function test_salary_settlement_sends_localized_credit_sms_once_per_run(): void
    {
        $dealer = User::factory()->create([
            'role' => 'dealer',
            'status' => 'active',
            'phone' => '01720000101',
            'locale' => 'bn',
        ]);
        $this->purchase($dealer, '10000.00', '20000.00');
        $this->travelTo(now()->setDate(2026, 10, 1));

        $key = (string) Str::uuid();
        $service = app(CommissionService::class);
        $first = $service->settleMonthlySalaryPool('2026-09', null, $key);
        $this->assertSame('settled', $first['status']);

        $sent = config('madina.sms.sent');
        $this->assertIsArray($sent);
        $this->assertCount(1, $sent);
        $this->assertSame('commission.settled', $sent[0]['event'] ?? null);
        $this->assertSame('01720000101', $sent[0]['phone'] ?? null);
        $this->assertStringContainsString('স্যালারি', $sent[0]['message'] ?? '');
        $this->assertStringContainsString('2026-09', $sent[0]['message'] ?? '');

        Config::set('madina.sms.sent', []);
        $replay = $service->settleMonthlySalaryPool('2026-09', null, $key);
        $this->assertSame('settled', $replay['status']);
        $this->assertSame([], config('madina.sms.sent'));
    }

    private function purchase(User $buyer, string $cash, string $mrp): Order
    {
        $product = Product::factory()->create([
            'mrp' => $mrp,
            'dealer_price' => $cash,
            'sub_dealer_price' => $buyer->role === 'sub_dealer' ? $cash : null,
        ]);
        $order = app(OrderService::class)->create($buyer, [
            'items' => [['product_id' => $product->id, 'quantity' => 1]],
            'idempotency_key' => (string) Str::uuid(),
        ]);
        $order->update(['paid' => $cash, 'status' => 'delivered']);
        app(CommissionService::class)->processOrder($order);

        return $order;
    }
}
