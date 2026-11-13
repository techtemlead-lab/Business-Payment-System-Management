<?php

namespace Tests\Feature;

use App\Domain\Commissions\CommissionService;
use App\Domain\Orders\OrderService;
use App\Models\Order;
use App\Models\Product;
use App\Models\User;
use Database\Seeders\CommissionRuleSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\TestCase;
use ZipArchive;

class OrderRefundTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->withoutVite();
        $this->enableFinancialPosting();
        $this->seed(CommissionRuleSeeder::class);
    }

    public function test_dealers_and_staff_without_finance_rights_cannot_refund(): void
    {
        $order = $this->paidOrder();
        $buyer = User::find($order->user_id);

        $this->actingAs($buyer)->onPortal('dealer')->post('/commands/orders/'.$order->id.'/refund', $this->payload())->assertForbidden();
        $this->actingAs(User::factory()->dealer()->create())->onPortal('dealer')->post('/commands/orders/'.$order->id.'/refund', $this->payload())->assertForbidden();
        foreach (['inventory', 'support', 'account_manager'] as $role) {
            $this->actingAs(User::factory()->staff($role)->create())->onPortal('manage')->post('/commands/orders/'.$order->id.'/refund', $this->payload())->assertForbidden();
        }

        $this->assertDatabaseCount('order_refunds', 0);
        $this->assertSame('approved', $order->fresh()->status);
    }

    public function test_owner_can_refund_a_paid_order_that_was_not_sent_and_it_is_recorded_and_audited(): void
    {
        $order = $this->paidOrder();
        $owner = User::factory()->staff('md')->create(['name' => 'Managing Director']);

        $this->actingAs($owner)->onPortal('sadmin')->post('/commands/orders/'.$order->id.'/refund', $this->payload('100.00', 'Stock ran out'))
            ->assertRedirect()->assertSessionHasNoErrors();

        $this->assertDatabaseHas('order_refunds', ['order_id' => $order->id, 'user_id' => $order->user_id, 'amount' => '100.00',
            'paid_before' => '100.00', 'reason' => 'Stock ran out', 'refunded_by' => $owner->id]);
        $this->assertSame('cancelled', $order->fresh()->status);
        $this->assertDatabaseHas('audit_events', ['actor_id' => $owner->id, 'action' => 'order.refunded', 'subject' => $order->id]);
        $this->assertDatabaseHas('order_events', ['order_id' => $order->id, 'action' => 'order.refunded', 'actor_id' => $owner->id]);
        $this->assertDatabaseHas('account_notices', ['user_id' => $order->user_id]);

        $this->actingAs($owner)->onPortal('sadmin')->get('/orders/'.$order->id)->assertOk()->assertInertia(fn (Assert $page) => $page
            ->component('OrderDetail')
            ->where('canRefund', false)
            ->where('refund.amount', '100.00')
            ->where('refund.reason', 'Stock ran out')
            ->where('refund.refunded_by', 'Managing Director'));
    }

    public function test_finance_staff_can_refund_part_of_the_payment_and_sees_the_refund_action(): void
    {
        $order = $this->paidOrder();
        $finance = User::factory()->staff('finance')->create();

        $this->actingAs($finance)->onPortal('manage')->get('/orders/'.$order->id)->assertOk()
            ->assertInertia(fn (Assert $page) => $page->where('canRefund', true)->where('refund', null));
        $this->actingAs($finance)->onPortal('manage')->post('/commands/orders/'.$order->id.'/refund', $this->payload('40.00', 'Dealer asked to change the order'))
            ->assertRedirect()->assertSessionHasNoErrors();

        $this->assertDatabaseHas('order_refunds', ['order_id' => $order->id, 'amount' => '40.00', 'refunded_by' => $finance->id]);
    }

    public function test_only_paid_orders_that_were_not_sent_or_delivered_can_be_refunded(): void
    {
        $finance = User::factory()->staff('finance')->create();
        $unpaid = $this->paidOrder();
        $unpaid->update(['paid' => '0.00', 'status' => 'submitted']);
        $dispatched = $this->paidOrder();
        $dispatched->update(['status' => 'dispatched']);
        $delivered = $this->paidOrder();
        $delivered->update(['status' => 'delivered']);
        $rejected = $this->paidOrder();
        $rejected->update(['status' => 'rejected']);
        $paid = $this->paidOrder();

        $this->actingAs($finance)->onPortal('manage');
        $this->post('/commands/orders/'.$unpaid->id.'/refund', $this->payload('100.00'))->assertSessionHasErrors('refund');
        foreach ([$dispatched, $delivered, $rejected] as $order) {
            $this->post('/commands/orders/'.$order->id.'/refund', $this->payload())->assertSessionHasErrors('refund');
        }
        $this->post('/commands/orders/'.$paid->id.'/refund', $this->payload('100.01'))->assertSessionHasErrors('amount');
        $this->post('/commands/orders/'.$paid->id.'/refund', $this->payload('0'))->assertSessionHasErrors('amount');
        $this->reasonFields(['order.refund' => 'required']);
        $this->post('/commands/orders/'.$paid->id.'/refund', ['amount' => '50.00', 'reason' => ''])->assertSessionHasErrors('reason');

        $this->assertDatabaseCount('order_refunds', 0);
        $this->assertSame('delivered', $delivered->fresh()->status);
    }

    public function test_an_order_cannot_be_refunded_twice(): void
    {
        $order = $this->paidOrder();
        $finance = User::factory()->staff('finance')->create();

        $this->actingAs($finance)->onPortal('manage')->post('/commands/orders/'.$order->id.'/refund', $this->payload())->assertSessionHasNoErrors();
        $this->actingAs($finance)->onPortal('manage')->post('/commands/orders/'.$order->id.'/refund', $this->payload())->assertSessionHasErrors('refund');

        $this->assertDatabaseCount('order_refunds', 1);
        $this->assertSame(1, DB::table('audit_events')->where('action', 'order.refunded')->count());
    }

    public function test_a_refund_with_no_reason_is_saved_blank_and_the_dealer_is_told_none_was_written(): void
    {
        $order = $this->paidOrder();
        $finance = User::factory()->staff('finance')->create();
        $this->reasonFields(['order.refund' => 'optional']);

        $this->actingAs($finance)->onPortal('manage')->post('/commands/orders/'.$order->id.'/refund', ['amount' => '100.00'])->assertSessionHasNoErrors();

        $this->assertDatabaseHas('order_refunds', ['order_id' => $order->id, 'reason' => '']);
        $this->assertSame('cancelled', $order->fresh()->status);
        $locale = User::find($order->user_id)->preferredLocale();
        $body = DB::table('account_notices')->where('user_id', $order->user_id)->where('title', __('portal.notice_order_refunded_title', [], $locale))->value('body');
        $this->assertStringContainsString(__('reason_fields.not_written', [], $locale), (string) $body);
    }

    public function test_every_commission_from_the_order_is_reversed_on_refund(): void
    {
        $order = $this->paidOrder();
        // An older order whose commission was posted before the goods actually left.
        $order->update(['status' => 'delivered']);
        app(CommissionService::class)->processOrder($order->fresh());
        $order->update(['status' => 'approved']);
        $this->assertDatabaseHas('wallets', ['user_id' => $order->user_id, 'available' => '5.00']);
        $owner = User::factory()->staff('chairman')->create();

        $this->actingAs($owner)->onPortal('sadmin')->post('/commands/orders/'.$order->id.'/refund', $this->payload('100.00', 'Goods damaged in store'))
            ->assertSessionHasNoErrors();

        $refundId = DB::table('order_refunds')->where('order_id', $order->id)->value('id');
        $this->assertDatabaseHas('commission_refunds', ['order_id' => $order->id, 'reference' => 'order-refund:'.$refundId, 'amount' => '100.00']);
        $this->assertDatabaseHas('commission_batches', ['order_id' => $order->id, 'refunded_amount' => '100.00']);
        $this->assertSame(0, DB::table('commission_allocations')->where('order_id', $order->id)->whereNotNull('user_id')->where('amount', '>', 0)->count());
        $this->assertDatabaseHas('wallets', ['user_id' => $order->user_id, 'available' => '0.00']);
        $this->assertDatabaseHas('audit_events', ['action' => 'commissions.reversed', 'subject' => $order->id]);
    }

    public function test_refunds_show_in_the_finance_report(): void
    {
        $order = $this->paidOrder();
        $finance = User::factory()->staff('finance')->create(['name' => 'Finance Officer']);
        $this->actingAs($finance)->onPortal('manage')->post('/commands/orders/'.$order->id.'/refund', $this->payload('75.50', 'Duplicate order'))->assertSessionHasNoErrors();
        $today = now('Asia/Dhaka')->toDateString();

        $response = $this->actingAs($finance)->onPortal('manage')->get('/commands/operations/reports?type=order-refunds&format=xlsx&from='.$today.'&to='.$today)->assertOk();

        $file = $response->baseResponse->getFile()->getPathname();
        $zip = new ZipArchive;
        $zip->open($file);
        $sheet = $zip->getFromName('xl/worksheets/sheet1.xml');
        $this->assertStringContainsString($order->number, $sheet);
        $this->assertStringContainsString('Duplicate order', $sheet);
        $this->assertStringContainsString('Finance Officer', $sheet);
        $this->assertStringContainsString('75.5', $sheet);
        $zip->close();
        unlink($file);
    }

    public function test_commission_from_an_order_cannot_be_withdrawn_until_it_is_delivered(): void
    {
        $buyer = User::factory()->create();
        $product = Product::factory()->create(['mrp' => '200.00', 'dealer_price' => '100.00', 'stock' => 5]);
        $id = $this->actingAs($buyer)->postJson('/api/v1/orders', [
            'items' => [['product_id' => $product->id, 'quantity' => 1]], 'idempotency_key' => (string) Str::uuid(),
            'channel' => 'Bank', 'reference' => 'REFUND-HOLD-RECEIPT', 'amount' => '100.00',
            'proof' => UploadedFile::fake()->image('payment-proof.jpg'),
        ])->assertCreated()->json('data.id');
        $admin = User::factory()->create(['role' => 'super_admin']);
        $claim = DB::table('payment_claims')->where('order_id', $id)->value('id');
        $this->actingAs($admin)->postJson('/api/v1/claims/'.$claim.'/verify', ['approve' => true, 'reason' => 'Matched bank statement'])->assertOk();
        $this->postJson('/api/v1/orders/'.$id.'/decision', ['status' => 'approved', 'reason' => 'Payment confirmed'])->assertOk();
        $line = DB::table('order_lines')->where('order_id', $id)->value('id');
        $this->postJson('/api/v1/orders/'.$id.'/dispatch', ['courier' => 'Test courier', 'tracking' => 'TRACK-REFUND',
            'idempotency_key' => (string) Str::uuid(), 'items' => [['line_id' => $line, 'quantity' => 1]]])->assertOk();

        // Paid, approved and on the way: no commission yet, so nothing to withdraw.
        $this->assertDatabaseCount('commission_allocations', 0);
        $this->assertSame('0.00', (string) (DB::table('wallets')->where('user_id', $buyer->id)->value('available') ?? '0.00'));
        $this->actingAs($buyer)->postJson('/api/v1/withdrawals', ['amount' => '5.00', 'channel' => 'Bank', 'destination' => 'TEST-ACCOUNT-1234',
            'idempotency_key' => (string) Str::uuid()])->assertUnprocessable();
        $this->assertDatabaseCount('withdrawals', 0);

        $shipment = DB::table('shipments')->where('order_id', $id)->value('id');
        $this->actingAs($admin)->postJson('/api/v1/orders/'.$id.'/shipments/'.$shipment.'/delivered', ['reason' => 'Proof of delivery checked'])->assertOk();

        $this->assertDatabaseHas('commission_allocations', ['order_id' => $id, 'user_id' => $buyer->id, 'amount' => '5.00']);
        $this->assertDatabaseHas('wallets', ['user_id' => $buyer->id, 'available' => '5.00']);
    }

    /** A dealer order for ৳100 that is fully paid and approved, but not yet sent out. */
    private function paidOrder(): Order
    {
        $buyer = User::factory()->dealer()->create();
        $product = Product::factory()->create(['mrp' => '200.00', 'dealer_price' => '100.00', 'stock' => 5]);
        $order = app(OrderService::class)->create($buyer, ['items' => [['product_id' => $product->id, 'quantity' => 1]], 'idempotency_key' => (string) Str::uuid()]);
        $order->update(['status' => 'approved', 'paid' => '100.00']);

        return $order->fresh();
    }

    /** @return array{amount: string, reason: string} */
    private function payload(string $amount = '100.00', string $reason = 'Goods are not available'): array
    {
        return ['amount' => $amount, 'reason' => $reason];
    }

    public function test_the_refund_form_warns_about_a_part_refund_in_both_languages(): void
    {
        foreach (['en', 'bn'] as $locale) {
            $this->assertNotSame('portal.order_refund_part_warning', __('portal.order_refund_part_warning', [], $locale));
        }
    }
}
