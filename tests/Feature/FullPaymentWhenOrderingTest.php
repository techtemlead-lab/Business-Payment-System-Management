<?php

namespace Tests\Feature;

use App\Domain\Finance\FinanceSettings;
use App\Domain\Finance\PaymentAccounts;
use App\Models\Order;
use App\Models\PlaceCode;
use App\Models\Product;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\TestCase;

/**
 * Owner decision: as a new company nobody orders without paying. While "Full payment
 * required when ordering" is on, an order is only accepted together with a payment for
 * its whole total and a proof screenshot; the payment then waits in the checking queue.
 */
class FullPaymentWhenOrderingTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->withoutVite();
        Storage::fake('local');
        // The live default (phpunit.xml turns it off for the older pay-later tests).
        config(['madina.full_payment_when_ordering' => true]);
    }

    public function test_full_payment_is_required_by_default(): void
    {
        $this->assertSame(['enabled' => true, 'version' => 0], app(FinanceSettings::class)->fullPaymentWhenOrdering());
    }

    public function test_an_order_without_the_payment_and_screenshot_is_refused(): void
    {
        $dealer = $this->dealer();
        $product = $this->product();

        $this->actingAs($dealer)->post('/commands/orders', [
            'items' => [['product_id' => $product->id, 'quantity' => 2]], 'idempotency_key' => (string) Str::uuid(),
        ])->assertSessionHasErrors(['reference', 'channel', 'amount', 'proof']);

        $this->actingAs($dealer)->post('/commands/orders', [
            'items' => [['product_id' => $product->id, 'quantity' => 2]], 'idempotency_key' => (string) Str::uuid(),
            'channel' => 'bKash', 'reference' => 'TRX-NOPROOF-1', 'amount' => '200.00',
        ])->assertSessionHasErrors(['proof']);

        $this->assertDatabaseCount('orders', 0);
        $this->assertDatabaseCount('payment_claims', 0);
    }

    public function test_part_payment_is_refused(): void
    {
        $dealer = $this->dealer();
        $product = $this->product();

        $this->actingAs($dealer)->post('/commands/orders', [
            'items' => [['product_id' => $product->id, 'quantity' => 2]], 'idempotency_key' => (string) Str::uuid(),
            'channel' => 'bKash', 'reference' => 'TRX-PART-1', 'amount' => '150.00',
            'proof' => UploadedFile::fake()->image('bkash.png'),
        ])->assertSessionHasErrors(['amount' => __('portal.err_order_full_payment_amount', ['amount' => '200.00'])]);

        $this->assertDatabaseCount('orders', 0);
        $this->assertDatabaseCount('payment_claims', 0);
    }

    public function test_an_order_paid_in_full_is_accepted_and_the_payment_waits_for_checking(): void
    {
        $dealer = $this->dealer();
        $product = $this->product();

        $this->actingAs($dealer)->post('/commands/orders', [
            'items' => [['product_id' => $product->id, 'quantity' => 2]], 'idempotency_key' => (string) Str::uuid(),
            'channel' => 'Nagad', 'reference' => 'trx-full-1', 'amount' => '200.00',
            'proof' => UploadedFile::fake()->image('nagad.png'),
        ])->assertSessionHasNoErrors()->assertRedirect();

        $order = Order::where('user_id', $dealer->id)->sole();
        $this->assertSame('200.00', (string) $order->total);
        $claim = DB::table('payment_claims')->where('order_id', $order->id)->sole();
        $this->assertSame('pending', $claim->status);
        $this->assertSame('200.00', (string) $claim->amount);
        $this->assertSame('TRX-FULL-1', $claim->reference);
        Storage::disk('local')->assertExists($claim->proof_path);

        $finance = User::factory()->staff('finance')->create();
        $this->actingAs($finance)->onPortal('manage')->get('/payments?status=pending')
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->where('data.claims.data.0.id', $claim->id)
                ->where('data.claims.data.0.number', $order->number)
                ->where('data.claims.data.0.has_proof', true));

        // A later payment for the same order must also be the whole amount still due.
        DB::table('payment_claims')->where('id', $claim->id)->update(['status' => 'rejected']);
        $this->actingAs($dealer)->post('/commands/orders/'.$order->id.'/payments', [
            'channel' => 'Nagad', 'reference' => 'TRX-FULL-2', 'amount' => '50.00',
            'proof' => UploadedFile::fake()->image('nagad-2.png'),
        ])->assertSessionHasErrors(['amount']);
        $this->assertDatabaseMissing('payment_claims', ['reference' => 'TRX-FULL-2']);
    }

    public function test_orders_placed_by_field_staff_follow_the_same_rule(): void
    {
        $admin = User::factory()->create(['role' => 'super_admin']);
        $sr = User::factory()->create(['role' => 'sr']);
        $rangpurDistrict = PlaceCode::factory()->district(PlaceCode::factory()->division()->create(['name' => 'Rangpur']))->create(['name' => 'Rangpur']);
        $partner = User::factory()->inPlace($rangpurDistrict)->create(['role' => 'dealer', 'status' => 'active']);
        $product = $this->product();
        $this->actingAs($admin)->postJson('/api/v1/operations/staff/territories', ['staff_id' => $sr->id, 'place_code' => $rangpurDistrict->code])->assertOk();
        $payload = ['user_id' => $partner->id, 'items' => [['product_id' => $product->id, 'quantity' => 1]], 'idempotency_key' => (string) Str::uuid()];

        $this->actingAs($sr)->postJson('/api/v1/operations/staff/orders', $payload)
            ->assertUnprocessable()->assertJsonValidationErrors(['reference', 'channel', 'amount', 'proof']);
        $this->assertDatabaseCount('orders', 0);

        $this->actingAs($sr)->postJson('/api/v1/operations/staff/orders', [...$payload, 'channel' => 'Bank', 'reference' => 'BANK-SR-1',
            'amount' => '100.00', 'proof' => UploadedFile::fake()->image('bank-slip.jpg')])->assertOk();
        $order = Order::where('user_id', $partner->id)->sole();
        $this->assertSame($sr->id, (int) $order->submitted_by);
        $this->assertDatabaseHas('payment_claims', ['order_id' => $order->id, 'amount' => '100.00', 'status' => 'pending']);
    }

    public function test_with_the_setting_off_partners_can_order_first_and_pay_later_in_parts(): void
    {
        $owner = User::factory()->staff()->create();
        $this->actingAs($owner)->onPortal('sadmin')->post('/commands/operations/settings/full-payment-when-ordering', [
            'enabled' => false, 'version' => 0, 'reason' => 'Trusted dealers may pay after ordering again',
        ])->assertSessionHasNoErrors();
        $this->assertFalse(app(FinanceSettings::class)->fullPaymentWhenOrdering()['enabled']);

        $dealer = $this->dealer();
        $product = $this->product();
        $this->actingAs($dealer)->post('/commands/orders', [
            'items' => [['product_id' => $product->id, 'quantity' => 2]], 'idempotency_key' => (string) Str::uuid(),
        ])->assertSessionHasNoErrors()->assertRedirect();
        $order = Order::where('user_id', $dealer->id)->sole();
        $this->assertDatabaseCount('payment_claims', 0);

        $this->actingAs($dealer)->post('/commands/orders/'.$order->id.'/payments', [
            'channel' => 'bKash', 'reference' => 'TRX-LATER-1', 'amount' => '50.00',
            'proof' => UploadedFile::fake()->image('bkash.png'),
        ])->assertSessionHasNoErrors();
        $this->assertDatabaseHas('payment_claims', ['order_id' => $order->id, 'amount' => '50.00', 'status' => 'pending']);
    }

    public function test_only_owners_can_change_the_setting_and_the_change_is_audited(): void
    {
        $finance = User::factory()->staff('finance')->create();
        $status = $this->actingAs($finance)->onPortal('manage')->post('/commands/operations/settings/full-payment-when-ordering', [
            'enabled' => false, 'version' => 0, 'reason' => 'Trying to change an owner setting',
        ])->getStatusCode();
        $this->assertContains($status, [403, 404]);
        $this->assertTrue(app(FinanceSettings::class)->fullPaymentWhenOrdering()['enabled']);

        $owner = User::factory()->staff()->create();
        $this->actingAs($owner)->onPortal('sadmin')->get('/operations/finance-settings')
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->where('fullPaymentWhenOrdering.enabled', true)
                ->where('fullPaymentWhenOrdering.can_edit', true));
        $this->actingAs($owner)->onPortal('sadmin')->post('/commands/operations/settings/full-payment-when-ordering', [
            'enabled' => false, 'version' => 0, 'reason' => 'Allow pay-later for the festival season',
        ])->assertSessionHasNoErrors();

        $audit = DB::table('audit_events')->where('action', 'finance.full_payment_when_ordering_changed')->sole();
        $this->assertSame($owner->id, (int) $audit->actor_id);
        $metadata = json_decode((string) $audit->metadata, true);
        $this->assertTrue($metadata['before']['enabled']);
        $this->assertFalse($metadata['after']['enabled']);
        $this->assertSame('Allow pay-later for the festival season', $metadata['reason']);
    }

    private function dealer(): User
    {
        return User::factory()->dealer()->create(['status' => 'active']);
    }

    public function test_a_sales_rep_chooses_the_company_account_when_the_company_has_one(): void
    {
        Storage::fake('local');
        $admin = User::factory()->create(['role' => 'super_admin']);
        $sr = User::factory()->create(['role' => 'sr']);
        $account = app(PaymentAccounts::class)->save($admin, ['label' => 'Company bank', 'channel' => 'Bank', 'holder' => 'Madina', 'account_number' => '123456789012',
            'details' => 'Pay here', 'collects_payments' => true, 'pays_out' => false, 'is_active' => true, 'reason' => 'Collection account for the sales rep order test.']);
        $district = PlaceCode::factory()->district(PlaceCode::factory()->division()->create(['name' => 'Rangpur']))->create(['name' => 'Rangpur']);
        $partner = User::factory()->inPlace($district)->create(['role' => 'dealer', 'status' => 'active']);
        $product = $this->product();
        $this->actingAs($admin)->postJson('/api/v1/operations/staff/territories', ['staff_id' => $sr->id, 'place_code' => $district->code])->assertOk();

        $this->actingAs($sr)->onPortal('manage')->get('/operations/field-sales')->assertOk()
            ->assertInertia(fn (Assert $page) => $page->has('collectionAccounts', 1)->where('collectionAccounts.0.id', $account));

        $payload = ['user_id' => $partner->id, 'items' => [['product_id' => $product->id, 'quantity' => 1]], 'idempotency_key' => (string) Str::uuid(),
            'channel' => 'Bank', 'reference' => 'BANK-SR-2', 'amount' => '100.00', 'proof' => UploadedFile::fake()->image('bank-slip.jpg')];
        $this->actingAs($sr)->postJson('/api/v1/operations/staff/orders', $payload)
            ->assertUnprocessable()->assertJsonValidationErrors(['payment_account_id']);
        $this->assertDatabaseCount('orders', 0);

        $this->actingAs($sr)->postJson('/api/v1/operations/staff/orders', [...$payload, 'payment_account_id' => $account])->assertOk();
        $order = Order::where('user_id', $partner->id)->sole();
        $this->assertDatabaseHas('payment_claims', ['order_id' => $order->id, 'amount' => '100.00', 'payment_account_id' => $account, 'status' => 'pending']);
    }

    private function product(): Product
    {
        return Product::factory()->create(['active' => true, 'mrp' => '200.00', 'dealer_price' => '100.00', 'sub_dealer_price' => '120.00', 'stock' => 50]);
    }
}
