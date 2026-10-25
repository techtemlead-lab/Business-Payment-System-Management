<?php

namespace Tests\Feature;

use App\Models\Product;
use App\Models\User;
use Database\Seeders\CommissionRuleSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\TestCase;

class CommerceWorkflowTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->withoutVite();
    }

    public function test_public_product_page_requires_active_catalog_item(): void
    {
        $product = Product::factory()->create(['active' => true, 'name' => 'Commerce Tonic', 'sku' => 'COM-1']);
        $this->get('/products/'.$product->id)->assertOk()->assertSee('Commerce Tonic');

        $product->update(['active' => false]);
        $this->get('/products/'.$product->id)->assertNotFound();
    }

    public function test_dealer_can_open_catalog_workspace(): void
    {
        $dealer = User::factory()->create(['role' => 'dealer', 'status' => 'active', 'dealer_code' => 'D-'.Str::upper(Str::random(6))]);
        $this->actingAs($dealer)->onPortal('dealer')->get('/catalog')->assertOk();
    }

    public function test_portal_hosts_expose_login_and_public_catalog_surfaces(): void
    {
        $this->onPortal('public')->get('/')->assertOk();
        $this->onPortal('public')->get('/products')->assertOk();
        $this->onPortal('public')->get('/contact')->assertOk();
        $this->onPortal('public')->get('/dealers')->assertOk();

        foreach (['dealer', 'manage', 'sadmin'] as $portal) {
            $this->onPortal($portal)->get('/login')->assertOk();
        }
    }

    public function test_authenticated_dealer_and_staff_module_surfaces_load(): void
    {
        $dealer = User::factory()->create(['role' => 'dealer', 'status' => 'active', 'dealer_code' => 'D-SMOKE']);
        $finance = User::factory()->create(['role' => 'finance', 'status' => 'active']);

        foreach (['/', '/catalog', '/orders', '/withdrawals', '/earnings', '/network', '/circulars', '/profile'] as $path) {
            $this->actingAs($dealer)->onPortal('dealer')->get($path)->assertOk();
        }

        foreach (['/', '/orders', '/operations/financial-reports', '/operations/payment-accounts', '/treasury'] as $path) {
            $this->actingAs($finance)->onPortal('manage')->get($path)->assertOk();
        }

        $editor = User::factory()->create(['role' => 'editor', 'status' => 'active']);
        $this->actingAs($editor)->onPortal('manage')->get('/enquiries')->assertOk();
    }

    public function test_portal_hosts_carry_order_through_delivery_to_commission_posting(): void
    {
        $this->enableFinancialPosting();
        $this->seed(CommissionRuleSeeder::class);

        $dealer = User::factory()->create(['role' => 'dealer', 'status' => 'active']);
        $staff = User::factory()->create(['role' => 'super_admin', 'status' => 'active']);
        $product = Product::factory()->create(['mrp' => '200.00', 'dealer_price' => '100.00', 'stock' => 5]);

        $id = $this->actingAs($dealer)->onPortal('dealer')->postJson('/api/v1/orders', [
            'items' => [['product_id' => $product->id, 'quantity' => 1]],
            'idempotency_key' => (string) Str::uuid(),
            'channel' => 'Bank',
            'reference' => 'PORTAL-HOST-RECEIPT',
            'amount' => '100.00',
        ])->assertCreated()->json('data.id');

        $claim = DB::table('payment_claims')->where('order_id', $id)->value('id');
        $this->actingAs($staff)->onPortal('manage')->postJson('/api/v1/claims/'.$claim.'/verify', [
            'approve' => true,
            'reason' => 'Matched bank statement',
        ])->assertOk();
        $this->assertDatabaseCount('commission_batches', 0);

        $this->onPortal('manage')->postJson('/api/v1/orders/'.$id.'/decision', [
            'status' => 'approved',
            'reason' => 'Payment confirmed',
        ])->assertOk();
        $line = DB::table('order_lines')->where('order_id', $id)->value('id');
        $this->onPortal('manage')->postJson('/api/v1/orders/'.$id.'/dispatch', [
            'courier' => 'Portal courier',
            'tracking' => 'PORTAL-TRACK-1',
            'idempotency_key' => (string) Str::uuid(),
            'items' => [['line_id' => $line, 'quantity' => 1]],
        ])->assertOk();
        $shipment = DB::table('shipments')->where('order_id', $id)->value('id');
        $this->onPortal('manage')->postJson('/api/v1/orders/'.$id.'/shipments/'.$shipment.'/delivered', [
            'reason' => 'Proof of delivery checked',
        ])->assertOk();

        $this->assertDatabaseCount('commission_batches', 1);
        $this->assertDatabaseHas('commission_batches', [
            'order_id' => $id,
            'cash_basis' => '100.00',
            'company_share' => '60.00',
            'total_allocated' => '40.00',
        ]);
        $this->assertDatabaseHas('wallets', ['user_id' => $dealer->id, 'available' => '5.00']);
        $this->assertDatabaseHas('products', ['id' => $product->id, 'stock' => 4]);
    }

    public function test_staff_money_mutations_are_rejected_on_dealer_portal_host(): void
    {
        $this->enableFinancialPosting();
        $finance = User::factory()->create(['role' => 'finance', 'status' => 'active']);
        $this->actingAs($finance)->onPortal('dealer')
            ->postJson('/api/v1/operations/payroll/prepare', ['period_month' => '2026-09'])
            ->assertNotFound();
        $this->actingAs($finance)->onPortal('manage')
            ->postJson('/api/v1/operations/payroll/prepare', ['period_month' => '2026-09'])
            ->assertOk();
    }

    public function test_portal_hosts_carry_withdrawal_request_through_staff_review(): void
    {
        $this->enableFinancialPosting();
        $dealer = User::factory()->create(['role' => 'dealer', 'status' => 'active']);
        $reviewer = User::factory()->create(['role' => 'finance', 'status' => 'active']);
        DB::table('wallets')->insert([
            'user_id' => $dealer->id,
            'available' => '500.00',
            'held' => '0.00',
            'commission_held' => '0.00',
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $id = $this->actingAs($dealer)->onPortal('dealer')->postJson('/api/v1/withdrawals', [
            'amount' => '200.00',
            'channel' => 'Bank',
            'destination' => 'Verified portal host account',
            'idempotency_key' => (string) Str::uuid(),
        ])->assertCreated()->json('data.id');

        $this->actingAs($reviewer)->onPortal('manage')
            ->post('/commands/withdrawals/'.$id.'/review', [
                'decision' => 'approved',
                'reason' => 'Portal-host dual-control review',
            ])
            ->assertRedirect();

        $this->assertDatabaseHas('withdrawals', ['id' => $id, 'status' => 'approved', 'reviewed_by' => $reviewer->id]);
        $this->assertDatabaseHas('wallets', ['user_id' => $dealer->id, 'available' => '300.00', 'held' => '200.00']);
    }
}
