<?php

namespace Tests\Feature;

use App\Domain\Orders\OrderService;
use App\Domain\Pricing\ProductPricing;
use App\Models\Product;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\TestCase;

class BackendApiTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->withoutVite();
    }

    public function test_api_guests_receive_json_401_and_suspended_accounts_receive_403(): void
    {
        $this->get('/api/v1/orders')->assertUnauthorized()->assertJsonStructure(['message']);
        $user = User::factory()->create(['status' => 'suspended']);

        $this->actingAs($user)->getJson('/api/v1/me')->assertForbidden();
    }

    public function test_api_order_uses_server_prices_and_replays_without_duplicate_records(): void
    {
        $user = User::factory()->create(['role' => 'sub_dealer']);
        $product = Product::factory()->create();
        $payload = ['items' => [['product_id' => $product->id, 'quantity' => 2]], 'idempotency_key' => (string) Str::uuid(), 'total' => '0.01', 'user_id' => 9999];

        $response = $this->actingAs($user)->postJson('/api/v1/orders', $payload)
            ->assertCreated()->assertJsonPath('data.total', '240.00')->assertJsonPath('data.user_id', $user->id)
            ->assertJsonMissingPath('data.idempotency_key')->assertJsonMissingPath('data.payload_hash');
        $id = $response->json('data.id');
        $this->postJson('/api/v1/orders', $payload)->assertOk()->assertJsonPath('data.id', $id);
        $payload['items'][0]['quantity'] = 3;
        $this->postJson('/api/v1/orders', $payload)->assertConflict();

        $this->assertDatabaseCount('orders', 1);
        $this->assertDatabaseCount('order_lines', 1);
        $this->assertDatabaseHas('order_lines', ['order_id' => $id, 'unit_price' => '120.00', 'quantity' => 2]);
        $this->assertDatabaseHas('audit_events', ['action' => 'order.submitted', 'subject' => $id]);
    }

    public function test_order_creation_rechecks_a_suspension_after_authentication(): void
    {
        $user = User::factory()->create(['status' => 'active']);
        $product = Product::factory()->create();
        User::whereKey($user->id)->update(['status' => 'suspended']);

        $this->actingAs($user)->postJson('/api/v1/orders', [
            'items' => [['product_id' => $product->id, 'quantity' => 1]],
            'idempotency_key' => (string) Str::uuid(),
        ])->assertForbidden();

        $this->assertDatabaseCount('orders', 0);
        $this->assertDatabaseCount('audit_events', 0);
    }

    public function test_api_order_list_and_details_are_scoped_to_the_owner(): void
    {
        $owner = User::factory()->create();
        $other = User::factory()->create();
        $order = app(OrderService::class)->create($other, ['items' => [['product_id' => Product::factory()->create()->id, 'quantity' => 1]], 'idempotency_key' => (string) Str::uuid()]);

        $this->actingAs($owner)->getJson('/api/v1/orders')->assertOk()->assertJsonCount(0, 'data');
        $this->getJson('/api/v1/orders/'.$order->id)->assertNotFound();
        $this->postJson('/api/v1/orders/'.$order->id.'/payments', ['channel' => 'Bank', 'reference' => 'OTHER', 'amount' => '10.00'])->assertNotFound();
        $this->actingAs(User::factory()->create(['role' => 'editor']))->getJson('/api/v1/orders')->assertForbidden();
    }

    public function test_shared_validation_blocks_unknown_line_attributes_and_duplicate_products(): void
    {
        $user = User::factory()->create();
        $product = Product::factory()->create();
        $payload = ['items' => [['product_id' => $product->id, 'quantity' => 1, 'unit_price' => '0.01']], 'idempotency_key' => (string) Str::uuid()];

        $this->actingAs($user)->postJson('/api/v1/orders', $payload)->assertUnprocessable()->assertJsonValidationErrors('items.0');
        $payload['items'] = [['product_id' => $product->id, 'quantity' => 1], ['product_id' => $product->id, 'quantity' => 2]];
        $this->postJson('/api/v1/orders', $payload)->assertUnprocessable()->assertJsonValidationErrors('items.0.product_id');

        $this->assertDatabaseCount('orders', 0);
    }

    public function test_json_order_workflow_records_payment_dispatch_and_delivery_atomically(): void
    {
        $owner = User::factory()->create();
        $staff = User::factory()->create(['role' => 'super_admin']);
        $product = Product::factory()->create(['stock' => 2]);
        $id = $this->actingAs($owner)->postJson('/api/v1/orders', [
            'items' => [['product_id' => $product->id, 'quantity' => 2]], 'idempotency_key' => (string) Str::uuid(),
            'channel' => 'Bank', 'reference' => 'API-PAY-1', 'amount' => '280.00',
        ])->assertCreated()->json('data.id');
        $claim = DB::table('payment_claims')->where('order_id', $id)->value('id');

        $this->postJson('/api/v1/claims/'.$claim.'/verify', ['approve' => true, 'reason' => 'Not staff'])->assertForbidden();
        $this->actingAs($staff)->postJson('/api/v1/claims/'.$claim.'/verify', ['approve' => true, 'reason' => 'Verified Bank payment'])
            ->assertOk()
            ->assertJsonPath('data.message', __('portal.flash_payment_review'));
        $this->postJson('/api/v1/orders/'.$id.'/decision', ['status' => 'approved', 'reason' => 'Paid in full'])->assertOk();
        $line = DB::table('order_lines')->where('order_id', $id)->value('id');
        $dispatch = ['courier' => 'Test Courier', 'tracking' => 'API-TRACK-1', 'idempotency_key' => (string) Str::uuid(), 'items' => [['line_id' => $line, 'quantity' => 2]]];
        $this->postJson('/api/v1/orders/'.$id.'/dispatch', $dispatch)->assertOk()->assertJsonPath('data.status', 'dispatched');
        $this->postJson('/api/v1/orders/'.$id.'/dispatch', $dispatch)->assertOk();
        $shipment = DB::table('shipments')->where('order_id', $id)->value('id');
        $this->postJson('/api/v1/orders/'.$id.'/shipments/'.$shipment.'/delivered', ['reason' => 'Delivered to recipient'])->assertOk()->assertJsonPath('data.status', 'delivered');

        $this->assertDatabaseHas('products', ['id' => $product->id, 'stock' => 0]);
        $this->assertDatabaseHas('orders', ['id' => $id, 'paid' => '280.00', 'status' => 'delivered']);
        $this->assertDatabaseCount('receipts', 1);
        $this->assertDatabaseCount('shipments', 1);
        $this->assertDatabaseCount('stock_movements', 1);
    }

    public function test_stock_adjustment_rolls_back_on_underflow_or_duplicate_reference(): void
    {
        $product = Product::factory()->create(['stock' => 2]);
        $data = ['product_id' => $product->id, 'quantity' => -3, 'reason' => 'Correction', 'reference' => (string) Str::uuid()];
        $this->actingAs(User::factory()->create(['role' => 'inventory']))->postJson('/api/v1/inventory/adjustments', $data)->assertUnprocessable();

        $this->assertDatabaseHas('products', ['id' => $product->id, 'stock' => 2]);
        $this->assertDatabaseCount('stock_movements', 0);
        $data['quantity'] = 5;
        $this->postJson('/api/v1/inventory/adjustments', $data)->assertOk()->assertJsonPath('data.stock', 7);
        $this->postJson('/api/v1/inventory/adjustments', $data)->assertUnprocessable()->assertJsonValidationErrors('reference');
        $this->assertDatabaseHas('products', ['id' => $product->id, 'stock' => 7]);
        $this->assertDatabaseCount('stock_movements', 1);
    }

    public function test_financial_previews_report_funding_and_never_post_earnings(): void
    {
        $finance = User::factory()->create(['role' => 'finance']);
        $payload = ['account_type' => 'sub_dealer', 'mrp' => '200.00', 'discount_percent' => '50.00'];

        $this->actingAs($finance)->postJson('/api/v1/finance/distribution-preview', $payload)->assertOk()
            ->assertJsonPath('data.shortfall', '10.00')->assertJsonPath('data.is_funded', false)->assertJsonPath('data.posts_earnings', false);
        $this->postJson('/api/v1/finance/salary-preview', ['monthly_purchases' => '50000.00'])->assertOk()
            ->assertJsonPath('data.salary', '1500.00')->assertJsonPath('data.tour', '1500.00');
        $this->postJson('/api/v1/finance/distribution-preview', [...$payload, 'discount_percent' => '60.00'])->assertUnprocessable()->assertJsonValidationErrors('calculation');
        $this->postJson('/api/v1/finance/distribution-preview', [...$payload, 'mrp' => 200.01])->assertUnprocessable()->assertJsonValidationErrors('mrp');
        $this->actingAs(User::factory()->create(['role' => 'dealer']))->postJson('/api/v1/finance/distribution-preview', $payload)->assertForbidden();

        $this->assertDatabaseCount('wallets', 0);
        $this->assertDatabaseCount('journal_entries', 0);
        $this->assertDatabaseCount('policy_drafts', 0);
    }

    public function test_policy_drafts_use_purchase_value_and_reject_excessive_totals(): void
    {
        $payload = ['name' => 'Reviewed draft', 'account_type' => 'dealer', 'components' => [['name' => 'Referral', 'rate' => '10.00']], 'notes' => 'Draft only'];
        $this->actingAs(User::factory()->create(['role' => 'finance']))->post('/commands/policies', $payload)->assertRedirect();

        $this->assertDatabaseHas('policy_drafts', ['name' => 'Reviewed draft', 'basis' => 'purchase_value', 'status' => 'draft']);
        $payload['components'] = [['name' => 'A', 'rate' => '60.00'], ['name' => 'B', 'rate' => '50.00']];
        $this->post('/commands/policies', $payload)->assertSessionHasErrors('components');
        $this->assertDatabaseCount('policy_drafts', 1);
    }

    public function test_catalog_and_order_submission_share_the_replaceable_pricing_adapter(): void
    {
        $buyer = User::factory()->create();
        $product = Product::factory()->create(['cost_price' => '60.00']);
        $this->app->instance(ProductPricing::class, new class implements ProductPricing
        {
            public function unitPrice(Product $product, User $buyer): int
            {
                return 11000;
            }
        });

        $this->actingAs($buyer)->getJson('/api/v1/catalog')->assertOk()->assertJsonPath('data.0.price', '110.00')->assertJsonMissingPath('data.0.cost_price');
        $this->postJson('/api/v1/orders', ['items' => [['product_id' => $product->id, 'quantity' => 1]], 'idempotency_key' => (string) Str::uuid()])
            ->assertCreated()->assertJsonPath('data.total', '110.00');
        $this->get('/workspace/catalog')->assertOk()->assertSee('110.00');

        $this->assertDatabaseHas('order_lines', ['product_id' => $product->id, 'unit_price' => '110.00']);
    }
}
