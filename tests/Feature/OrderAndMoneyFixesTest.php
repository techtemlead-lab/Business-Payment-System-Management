<?php

namespace Tests\Feature;

use App\Domain\Orders\OrderService;
use App\Models\Order;
use App\Models\Product;
use App\Models\ReferralTreePath;
use App\Models\User;
use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Tests\TestCase;

class OrderAndMoneyFixesTest extends TestCase
{
    use RefreshDatabase;

    private function order(User $dealer, string $price = '1000.00'): Order
    {
        $product = Product::factory()->create(['mrp' => '2000.00', 'dealer_price' => $price, 'active' => true, 'stock' => 100]);

        return app(OrderService::class)->create($dealer, [
            'items' => [['product_id' => $product->id, 'quantity' => 1]],
            'idempotency_key' => (string) Str::uuid(),
        ]);
    }

    private function claim(User $dealer, Order $order, string $amount): void
    {
        app(OrderService::class)->claim($dealer, $order->fresh(), [
            'channel' => 'bKash',
            'reference' => 'TXN'.Str::upper(Str::random(10)),
            'amount' => $amount,
        ]);
    }

    public function test_payments_waiting_for_a_check_count_toward_what_an_order_still_needs(): void
    {
        $dealer = User::factory()->create(['role' => 'dealer', 'status' => 'active']);
        ReferralTreePath::attachUser($dealer->id, null);
        $order = $this->order($dealer);
        $total = (string) $order->total;
        $half = number_format(((float) $total) / 2, 2, '.', '');

        $this->claim($dealer, $order, $half);
        try {
            $this->claim($dealer, $order, $total);
            $this->fail('A second payment for the full amount must be refused while the first is waiting.');
        } catch (ValidationException $exception) {
            $this->assertArrayHasKey('amount', $exception->errors());
        }
        $this->claim($dealer, $order, number_format((float) $total - (float) $half, 2, '.', ''));

        $this->assertSame(2, DB::table('payment_claims')->where('order_id', $order->id)->where('status', 'pending')->count());
    }

    public function test_daily_audit_and_money_checks_are_scheduled(): void
    {
        $commands = collect(app(Schedule::class)->events())->map(fn ($event): string => (string) $event->command)->implode("\n");

        $this->assertStringContainsString('audit:verify-integrity', $commands);
        $this->assertStringContainsString('finance:readiness', $commands);
        $this->assertStringNotContainsString('finance:readiness --backup', $commands);
    }
}
