<?php

namespace Tests\Feature;

use App\Domain\Commissions\CommissionService;
use App\Domain\Orders\OrderService;
use App\Models\CommissionAllocation;
use App\Models\CommissionRuleParameter;
use App\Models\DealerRank;
use App\Models\Order;
use App\Models\Product;
use App\Models\ReferralTreePath;
use App\Models\User;
use App\Support\Money;
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

    public function test_public_enquiry_acknowledges_phone_in_request_locale(): void
    {
        Config::set('madina.sms.driver', 'array');
        Config::set('madina.sms.sent', []);

        $this->onPortal('public')->from('/')->post('/locale', ['locale' => 'bn'])->assertRedirect('/');
        $this->onPortal('public')
            ->from('/contact')
            ->post('/enquiries', [
                'name' => 'Public SMS Visitor',
                'phone' => '01720000093',
                'topic' => 'partnership_enquiry',
                'message' => 'I want to join as an authorized dealer in Sylhet.',
            ])
            ->assertRedirect('/contact');

        $sent = config('madina.sms.sent');
        $this->assertSame('enquiry.public_received', $sent[0]['event'] ?? null);
        $this->assertSame('01720000093', $sent[0]['phone'] ?? null);
        $this->assertArrayHasKey('user_id', $sent[0]);
        $this->assertNull($sent[0]['user_id']);
        $this->assertSame(
            __('sms.public_enquiry_received', [
                'topic' => __('portal.enquiry_topic_partnership', [], 'bn'),
            ], 'bn'),
            $sent[0]['message'] ?? null,
        );
    }

    public function test_public_partnership_application_acknowledges_phone_in_request_locale(): void
    {
        Config::set('madina.sms.driver', 'array');
        Config::set('madina.sms.sent', []);

        $this->onPortal('public')->from('/')->post('/locale', ['locale' => 'bn'])->assertRedirect('/');
        $this->onPortal('public')
            ->from('/partnership')
            ->post('/partnership/apply', [
                'name' => 'Partnership SMS Applicant',
                'email' => 'partnership.sms@example.test',
                'phone' => '01720000094',
                'role' => 'dealer',
                'division' => 'Sylhet',
                'district' => 'Sylhet',
                'sponsor_code' => 'REF-SMOKE-01',
            ])
            ->assertRedirect('/partnership');

        $sent = config('madina.sms.sent');
        $this->assertSame('partnership.application_received', $sent[0]['event'] ?? null);
        $this->assertSame('01720000094', $sent[0]['phone'] ?? null);
        $this->assertSame(
            __('sms.partnership_application_received', [
                'role' => __('portal.acct_role_dealer', [], 'bn'),
            ], 'bn'),
            $sent[0]['message'] ?? null,
        );
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

    public function test_rank_promotion_sends_localized_partner_sms(): void
    {
        Config::set('madina.sms.driver', 'array');
        Config::set('madina.sms.sent', []);
        $this->enableFinancialPosting();
        $this->seed(CommissionRuleSeeder::class);

        $dealer = User::factory()->create([
            'role' => 'dealer',
            'status' => 'active',
            'locale' => 'bn',
            'phone' => '01720000095',
        ]);
        ReferralTreePath::attachUser($dealer->id, null);
        DealerRank::firstOrCreate(
            ['user_id' => $dealer->id],
            ['current_rank' => 'dealer', 'lifetime_purchase_total' => 230000.00],
        );

        $order = Order::create([
            'idempotency_key' => (string) Str::uuid(),
            'payload_hash' => hash('sha256', 'rank-sms'),
            'number' => 'ORD-RANK-SMS',
            'user_id' => $dealer->id,
            'status' => 'delivered',
            'total' => '20000.00',
            'paid' => '20000.00',
        ]);
        $product = Product::factory()->create(['mrp' => Money::decimal(Money::cents('20000.00') * 2)]);
        $order->lines()->create([
            'product_id' => $product->id,
            'name' => $product->name,
            'sku' => $product->sku,
            'quantity' => 1,
            'mrp' => $product->mrp,
            'unit_price' => '20000.00',
            'total' => '20000.00',
        ]);

        app(CommissionService::class)->processOrder($order);

        $this->assertSame('mo', DealerRank::where('user_id', $dealer->id)->value('current_rank'));
        $sent = collect(config('madina.sms.sent'))->firstWhere('event', 'rank.achieved');
        $this->assertNotNull($sent);
        $this->assertSame(
            __('sms.rank_achieved', ['rank' => __('portal.net_rank_mo', [], 'bn')], 'bn'),
            $sent['message'] ?? null,
        );
        $this->assertDatabaseHas('account_notices', [
            'user_id' => $dealer->id,
            'title' => __('portal.notice_rank_achieved_title', [], 'bn'),
        ]);
    }

    public function test_reward_milestone_achievement_sends_localized_partner_sms(): void
    {
        Config::set('madina.sms.driver', 'array');
        Config::set('madina.sms.sent', []);
        $this->enableFinancialPosting();
        $this->seed(CommissionRuleSeeder::class);

        $rule = CommissionRuleParameter::getRule('car_bike_incentive');
        $parameters = $rule?->parameters ?? [];
        $rule?->update([
            'is_active' => true,
            'parameters' => [
                ...$parameters,
                'bike_target' => '100.00',
                'bike_leg_cap' => '100.00',
                'car_target' => '250000000.00',
                'car_leg_cap' => '20000000.00',
            ],
        ]);

        $leader = User::factory()->create([
            'role' => 'dealer',
            'status' => 'active',
            'locale' => 'bn',
            'phone' => '01720000097',
        ]);
        ReferralTreePath::attachUser($leader->id, null);
        $buyer = User::factory()->create([
            'role' => 'dealer',
            'status' => 'active',
            'sponsor_id' => $leader->id,
        ]);
        ReferralTreePath::attachUser($buyer->id, $leader->id);

        $order = Order::create([
            'idempotency_key' => (string) Str::uuid(),
            'payload_hash' => hash('sha256', 'milestone-sms'),
            'number' => 'ORD-MILE-SMS',
            'user_id' => $buyer->id,
            'status' => 'delivered',
            'total' => '100.00',
            'paid' => '100.00',
        ]);
        $product = Product::factory()->create(['mrp' => '200.00', 'dealer_price' => '100.00']);
        $order->lines()->create([
            'product_id' => $product->id,
            'name' => $product->name,
            'sku' => $product->sku,
            'quantity' => 1,
            'mrp' => $product->mrp,
            'unit_price' => '100.00',
            'total' => '100.00',
        ]);

        app(CommissionService::class)->processOrder($order);

        $sent = collect(config('madina.sms.sent'))->firstWhere('event', 'milestone.achieved');
        $this->assertNotNull($sent);
        $this->assertSame(
            __('sms.milestone_achieved', [
                'milestone' => __('portal.milestone_label_bike', [], 'bn'),
            ], 'bn'),
            $sent['message'] ?? null,
        );
        $this->assertDatabaseHas('account_notices', [
            'user_id' => $leader->id,
            'title' => __('portal.notice_milestone_achieved_title', [], 'bn'),
        ]);
    }

    public function test_order_refund_sends_localized_partner_sms(): void
    {
        Config::set('madina.sms.driver', 'array');
        Config::set('madina.sms.sent', []);
        $this->enableFinancialPosting();
        $this->seed(CommissionRuleSeeder::class);

        $dealer = User::factory()->create([
            'role' => 'dealer',
            'status' => 'active',
            'locale' => 'bn',
            'phone' => '01720000096',
        ]);
        $product = Product::factory()->create(['mrp' => '200.00', 'dealer_price' => '100.00', 'stock' => 5]);
        $order = app(OrderService::class)->create($dealer, [
            'items' => [['product_id' => $product->id, 'quantity' => 1]],
            'idempotency_key' => (string) Str::uuid(),
            'channel' => 'Bank',
            'reference' => 'REFUND-SMS-RECEIPT',
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
            'tracking' => 'T-REFUND',
            'idempotency_key' => (string) Str::uuid(),
            'items' => [['line_id' => $line, 'quantity' => 1]],
        ])->assertOk();
        $shipment = DB::table('shipments')->where('order_id', $order->id)->value('id');
        $this->postJson('/api/v1/orders/'.$order->id.'/shipments/'.$shipment.'/delivered', [
            'reason' => 'Delivered',
        ])->assertOk();

        Config::set('madina.sms.sent', []);
        app(CommissionService::class)->reverseOrderPartial($order->fresh(), '50.00', 'Returned half', 'REFUND-SMS-1');

        $sent = collect(config('madina.sms.sent'))->firstWhere('event', 'order.refunded');
        $this->assertNotNull($sent);
        $this->assertSame(
            __('sms.order_refunded', [
                'number' => $order->number,
                'amount' => '50.00',
                'reason' => 'Returned half',
            ], 'bn'),
            $sent['message'] ?? null,
        );
    }
}
