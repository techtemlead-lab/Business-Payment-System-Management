<?php

namespace Tests\Feature;

use App\Models\Order;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Testing\TestResponse;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\TestCase;

/**
 * The order page stops asking a partner to pay when the money is already sent and waiting
 * for a check, when the order is paid, and when it has been delivered.
 */
class OrderPaymentPromptTest extends TestCase
{
    use RefreshDatabase;

    private User $dealer;

    protected function setUp(): void
    {
        parent::setUp();
        $this->withoutVite();
        $this->dealer = User::factory()->dealer()->create();
    }

    public function test_a_full_payment_waiting_for_a_check_stops_the_pay_again_prompts(): void
    {
        $order = $this->order(['total' => '400.00']);
        $this->claim($order, '400.00', 'pending');

        $this->assertPrompt($this->openOrder($order), ask: false, waiting: true, waitingAmount: '400.00');
    }

    public function test_the_pay_prompts_come_back_after_the_payment_is_refused(): void
    {
        $order = $this->order(['total' => '400.00']);
        $this->claim($order, '400.00', 'rejected');

        $this->assertPrompt($this->openOrder($order), ask: true, waiting: false, waitingAmount: '0.00');
    }

    public function test_a_part_payment_waiting_still_leaves_the_rest_to_pay(): void
    {
        $order = $this->order(['total' => '400.00']);
        $this->claim($order, '100.00', 'pending');

        $this->assertPrompt($this->openOrder($order), ask: true, waiting: false, waitingAmount: '100.00');
    }

    public function test_the_last_part_waiting_counts_as_the_full_payment_waiting(): void
    {
        $order = $this->order(['total' => '400.00', 'paid' => '100.00']);
        $this->claim($order, '300.00', 'pending');

        $this->assertPrompt($this->openOrder($order), ask: false, waiting: true, waitingAmount: '300.00');
    }

    public function test_an_unpaid_order_with_nothing_sent_asks_for_payment(): void
    {
        $this->assertPrompt($this->openOrder($this->order(['total' => '400.00'])), ask: true, waiting: false, waitingAmount: '0.00');
    }

    public function test_a_paid_order_does_not_ask_for_payment(): void
    {
        $order = $this->order(['total' => '400.00', 'paid' => '400.00', 'status' => 'approved']);

        $this->assertPrompt($this->openOrder($order), ask: false, waiting: false, waitingAmount: '0.00');
    }

    public function test_a_delivered_order_does_not_ask_for_payment_even_with_money_left_on_it(): void
    {
        $order = $this->order(['total' => '400.00', 'paid' => '300.00', 'status' => 'delivered']);

        $this->assertPrompt($this->openOrder($order), ask: false, waiting: false, waitingAmount: '0.00');
    }

    public function test_a_closed_order_does_not_ask_for_payment(): void
    {
        foreach (['rejected', 'cancelled'] as $status) {
            $this->assertPrompt($this->openOrder($this->order(['total' => '400.00', 'status' => $status])), ask: false, waiting: false, waitingAmount: '0.00');
        }
    }

    public function test_the_order_page_shows_the_wait_message_and_follows_the_server(): void
    {
        $source = (string) file_get_contents(resource_path('js/pages/OrderDetail.tsx'));

        $this->assertStringContainsString('{owner && paymentPrompt.ask_for_payment && <section', $source);
        $this->assertStringContainsString('{owner && paymentPrompt.waiting_for_check && <div className="notice" role="status">', $source);
        $this->assertStringContainsString("only: ['order', 'claims', 'shipments', 'outstanding', 'paymentPrompt']", $source);
        $this->assertStringNotContainsString("outstanding !== '0.00'", $source);
    }

    public function test_the_wait_message_says_the_same_thing_in_both_languages(): void
    {
        foreach (['waiting_title', 'waiting_body', 'waiting_metric', 'waiting_metric_hint', 'delivered_on'] as $key) {
            $this->assertNotSame('order_payment.'.$key, __('order_payment.'.$key, [], 'en'));
            $this->assertMatchesRegularExpression('/\p{Bengali}/u', __('order_payment.'.$key, [], 'bn'));
        }
        $this->assertStringContainsString(':amount', __('order_payment.waiting_body', [], 'en'));
        $this->assertStringContainsString(':amount', __('order_payment.waiting_body', [], 'bn'));
    }

    private function openOrder(Order $order): TestResponse
    {
        return $this->actingAs($this->dealer)->onPortal('dealer')->get('/orders/'.$order->id)->assertOk();
    }

    private function assertPrompt(TestResponse $response, bool $ask, bool $waiting, string $waitingAmount): void
    {
        $response->assertInertia(fn (Assert $page) => $page
            ->component('OrderDetail')
            ->where('paymentPrompt.ask_for_payment', $ask)
            ->where('paymentPrompt.waiting_for_check', $waiting)
            ->where('paymentPrompt.waiting_amount', $waitingAmount));
    }

    /**
     * @param  array<string, string>  $attributes
     */
    private function order(array $attributes): Order
    {
        return Order::create([
            'number' => 'ORD-'.Str::upper(Str::random(8)),
            'user_id' => $this->dealer->id,
            'status' => 'submitted',
            'paid' => '0.00',
            'idempotency_key' => (string) Str::uuid(),
            'payload_hash' => hash('sha256', Str::random()),
            ...$attributes,
        ]);
    }

    private function claim(Order $order, string $amount, string $status): void
    {
        DB::table('payment_claims')->insert([
            'order_id' => $order->id,
            'channel' => 'bKash',
            'reference' => 'TRX-'.Str::upper(Str::random(8)),
            'amount' => $amount,
            'status' => $status,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }
}
