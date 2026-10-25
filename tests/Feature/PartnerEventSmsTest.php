<?php

namespace Tests\Feature;

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
            ['order.submitted', 'payment.verified', 'order.approved', 'order.dispatched', 'shipment.delivered'],
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
}
