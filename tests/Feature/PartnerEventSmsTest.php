<?php

namespace Tests\Feature;

use App\Domain\Commissions\CommissionService;
use App\Domain\Orders\OrderService;
use App\Models\CommissionAllocation;
use App\Models\CommissionRuleParameter;
use App\Models\Product;
use App\Models\User;
use Database\Seeders\CommissionRuleSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\TestCase;

class PartnerEventSmsTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->withoutVite();
    }

    public function test_partner_support_logs_event_sms_when_phone_present(): void
    {
        Config::set('madina.sms.driver', 'array');
        Config::set('madina.sms.sent', []);

        $dealer = User::factory()->create([
            'role' => 'dealer',
            'status' => 'active',
            'phone' => '01720000088',
            'dealer_code' => 'MNHC-SMS',
        ]);

        $this->actingAs($dealer)->onPortal('dealer')
            ->post('/commands/partner-support', [
                'topic' => 'support',
                'message' => 'Please help update my shop address on file.',
            ])
            ->assertRedirect();

        $sent = config('madina.sms.sent');
        $this->assertIsArray($sent);
        $this->assertNotEmpty($sent);
        $this->assertSame('support.requested', $sent[0]['event'] ?? null);
        $this->assertSame('01720000088', $sent[0]['phone'] ?? null);
    }

    public function test_partner_support_sms_uses_localized_topic_label(): void
    {
        Config::set('madina.sms.driver', 'array');
        Config::set('madina.sms.sent', []);

        $dealer = User::factory()->create([
            'role' => 'dealer',
            'status' => 'active',
            'locale' => 'bn',
            'phone' => '01720000089',
            'dealer_code' => 'MNHC-SMS-BN',
        ]);

        $this->actingAs($dealer)->onPortal('dealer')
            ->post('/commands/partner-support', [
                'topic' => 'Order dispute',
                'message' => 'Delivery still shows pending after courier pickup.',
            ])
            ->assertRedirect();

        $this->assertDatabaseHas('enquiries', [
            'phone' => '01720000089',
            'topic' => 'order_dispute',
        ]);
        $sent = config('madina.sms.sent');
        $this->assertSame(
            __('sms.support_requested', ['topic' => __('portal.support_topic_order', [], 'bn')], 'bn'),
            $sent[0]['message'] ?? null,
        );
    }

    public function test_order_lifecycle_sms_uses_partner_locale_across_portal_hosts(): void
    {
        Config::set('madina.sms.driver', 'array');
        Config::set('madina.sms.sent', []);
        $this->enableFinancialPosting();
        $this->seed(CommissionRuleSeeder::class);

        $dealer = User::factory()->create([
            'role' => 'dealer',
            'status' => 'active',
            'locale' => 'bn',
            'phone' => '01720000090',
        ]);
        $staff = User::factory()->create(['role' => 'super_admin', 'status' => 'active']);
        $product = Product::factory()->create(['mrp' => '200.00', 'dealer_price' => '100.00', 'stock' => 5]);

        $id = $this->actingAs($dealer)->onPortal('dealer')->postJson('/api/v1/orders', [
            'items' => [['product_id' => $product->id, 'quantity' => 1]],
            'idempotency_key' => (string) Str::uuid(),
            'channel' => 'Bank',
            'reference' => 'SMS-LIFECYCLE-RECEIPT',
            'amount' => '100.00',
        ])->assertCreated()->json('data.id');

        $claim = DB::table('payment_claims')->where('order_id', $id)->value('id');
        $this->actingAs($staff)->onPortal('manage')->postJson('/api/v1/claims/'.$claim.'/verify', [
            'approve' => true,
            'reason' => 'Matched bank statement',
        ])->assertOk();
        $this->onPortal('manage')->postJson('/api/v1/orders/'.$id.'/decision', [
            'status' => 'approved',
            'reason' => 'Payment confirmed',
        ])->assertOk();
        $line = DB::table('order_lines')->where('order_id', $id)->value('id');
        $this->onPortal('manage')->postJson('/api/v1/orders/'.$id.'/dispatch', [
            'courier' => 'Portal courier',
            'tracking' => 'SMS-TRACK-1',
            'idempotency_key' => (string) Str::uuid(),
            'items' => [['line_id' => $line, 'quantity' => 1]],
        ])->assertOk();
        $shipment = DB::table('shipments')->where('order_id', $id)->value('id');
        $this->onPortal('manage')->postJson('/api/v1/orders/'.$id.'/shipments/'.$shipment.'/delivered', [
            'reason' => 'Proof of delivery checked',
        ])->assertOk();

        $events = collect(config('madina.sms.sent'))->pluck('event')->all();
        $this->assertSame(
            ['order.submitted', 'payment.claimed', 'payment.verified', 'order.approved', 'order.dispatched', 'shipment.delivered'],
            $events,
        );

        $orderNumber = DB::table('orders')->where('id', $id)->value('number');
        $messages = collect(config('madina.sms.sent'))->pluck('message');
        $this->assertTrue($messages->contains(
            __('sms.delivery_confirmed', ['number' => $orderNumber], 'bn'),
        ));
        $this->assertTrue($messages->contains(
            __('sms.shipment_dispatched', [
                'number' => $orderNumber,
                'courier' => 'Portal courier',
                'tracking' => 'SMS-TRACK-1',
            ], 'bn'),
        ));
    }

    public function test_due_adjustment_review_sends_localized_partner_sms(): void
    {
        Config::set('madina.sms.driver', 'array');
        Config::set('madina.sms.sent', []);
        $this->enableFinancialPosting();
        $this->seed(CommissionRuleSeeder::class);

        $buyer = User::factory()->create([
            'role' => 'dealer',
            'status' => 'active',
            'locale' => 'bn',
            'phone' => '01720000091',
        ]);
        $product = Product::factory()->create(['mrp' => '200.00', 'dealer_price' => '100.00', 'stock' => 5]);
        $order = app(OrderService::class)->create($buyer, [
            'items' => [['product_id' => $product->id, 'quantity' => 1]],
            'idempotency_key' => (string) Str::uuid(),
        ]);
        $order->update(['status' => 'approved', 'paid' => '0.00']);

        Config::set('madina.sms.sent', []);
        $preparer = User::factory()->create(['role' => 'finance', 'status' => 'active']);
        $reviewer = User::factory()->create(['role' => 'finance', 'status' => 'active']);
        $payload = [
            'kind' => 'credit',
            'amount' => '20.00',
            'reference' => 'NOTE-SMS-'.Str::uuid(),
            'request_key' => (string) Str::uuid(),
            'reason' => 'Approved commercial allowance',
        ];
        $id = $this->actingAs($preparer)->postJson('/api/v1/operations/orders/'.$order->id.'/due-adjustments', $payload)
            ->assertOk()->json('data.id');
        $this->actingAs($reviewer)->postJson('/api/v1/operations/due-adjustments/'.$id.'/review', [
            'decision' => 'approved',
            'reason' => 'Independent allowance approval',
        ])->assertOk();

        $reference = DB::table('due_adjustments')->where('id', $id)->value('reference');
        $sent = config('madina.sms.sent');
        $this->assertSame('dues.adjustment_approved', $sent[0]['event'] ?? null);
        $this->assertSame(
            __('sms.due_adjustment', [
                'number' => $order->number,
                'decision' => __('portal.due_decision_approved', [], 'bn'),
                'reference' => $reference,
                'amount' => '80.00',
            ], 'bn'),
            $sent[0]['message'] ?? null,
        );
    }

    public function test_matured_commission_release_sends_localized_partner_sms(): void
    {
        Config::set('madina.sms.driver', 'array');
        Config::set('madina.sms.sent', []);
        $this->enableFinancialPosting();
        $this->seed(CommissionRuleSeeder::class);

        $cooling = CommissionRuleParameter::getRule('commission_cooling_off');
        $cooling?->update(['is_active' => true, 'parameters' => ['cooling_off_days' => 7]]);

        $dealer = User::factory()->create([
            'role' => 'dealer',
            'status' => 'active',
            'locale' => 'bn',
            'phone' => '01720000092',
        ]);
        $product = Product::factory()->create(['mrp' => '200.00', 'dealer_price' => '100.00', 'stock' => 5]);
        $order = app(OrderService::class)->create($dealer, [
            'items' => [['product_id' => $product->id, 'quantity' => 1]],
            'idempotency_key' => (string) Str::uuid(),
            'channel' => 'Bank',
            'reference' => 'MATURE-SMS-RECEIPT',
            'amount' => '100.00',
        ]);
        $staff = User::factory()->create(['role' => 'super_admin', 'status' => 'active']);
        $claim = DB::table('payment_claims')->where('order_id', $order->id)->value('id');
        $this->actingAs($staff)->postJson('/api/v1/claims/'.$claim.'/verify', [
            'approve' => true,
            'reason' => 'Matched',
        ])->assertOk();
        $this->postJson('/api/v1/orders/'.$order->id.'/decision', [
            'status' => 'approved',
            'reason' => 'OK',
        ])->assertOk();
        $line = DB::table('order_lines')->where('order_id', $order->id)->value('id');
        $this->postJson('/api/v1/orders/'.$order->id.'/dispatch', [
            'courier' => 'C',
            'tracking' => 'T-MATURE',
            'idempotency_key' => (string) Str::uuid(),
            'items' => [['line_id' => $line, 'quantity' => 1]],
        ])->assertOk();
        $shipment = DB::table('shipments')->where('order_id', $order->id)->value('id');
        $this->postJson('/api/v1/orders/'.$order->id.'/shipments/'.$shipment.'/delivered', [
            'reason' => 'Delivered',
        ])->assertOk();

        foreach (CommissionAllocation::where('user_id', $dealer->id)->where('status', 'maturing')->get() as $allocation) {
            $meta = $allocation->metadata ?? [];
            $meta['maturation_due_at'] = now()->subMinute()->toIso8601String();
            $allocation->update(['metadata' => $meta]);
        }

        Config::set('madina.sms.sent', []);
        $result = app(CommissionService::class)->releaseMaturedCommissions();
        $this->assertSame('success', $result['status']);
        $this->assertGreaterThan(0, $result['released_count']);

        $events = collect(config('madina.sms.sent'))->pluck('event')->all();
        $this->assertContains('commission.matured', $events);
        $this->assertTrue(
            collect(config('madina.sms.sent'))->contains(fn ($row) => str_contains((string) ($row['message'] ?? ''), 'কুলিং-অফ')),
        );
    }
}
