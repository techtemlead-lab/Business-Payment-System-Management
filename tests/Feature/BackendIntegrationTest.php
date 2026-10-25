<?php

namespace Tests\Feature;

use App\Domain\Commissions\CommissionService;
use App\Domain\Orders\OrderService;
use App\Models\DealerRank;
use App\Models\Product;
use App\Models\ReferralTreePath;
use App\Models\User;
use Database\Seeders\CommissionRuleSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Inertia\Testing\AssertableInertia;
use Tests\TestCase;

class BackendIntegrationTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->withoutVite();
        config(['madina.financial_activation' => true]);
        $this->seed(CommissionRuleSeeder::class);
    }

    public function test_verified_payment_and_delivery_post_once_through_the_real_http_workflow(): void
    {
        $buyer = User::factory()->create();
        $product = Product::factory()->create(['mrp' => '200.00', 'dealer_price' => '100.00', 'stock' => 5]);
        $id = $this->actingAs($buyer)->postJson('/api/v1/orders', [
            'items' => [['product_id' => $product->id, 'quantity' => 1]], 'idempotency_key' => (string) Str::uuid(),
            'channel' => 'Bank', 'reference' => 'INTEGRATION-RECEIPT', 'amount' => '100.00',
        ])->assertCreated()->json('data.id');
        $admin = User::factory()->create(['role' => 'super_admin']);
        $claim = DB::table('payment_claims')->where('order_id', $id)->value('id');
        $this->actingAs($admin)->postJson('/api/v1/claims/'.$claim.'/verify', ['approve' => true, 'reason' => 'Matched bank statement'])->assertOk();
        $this->assertDatabaseCount('commission_batches', 0);
        $this->postJson('/api/v1/orders/'.$id.'/decision', ['status' => 'approved', 'reason' => 'Payment confirmed'])->assertOk();
        $line = DB::table('order_lines')->where('order_id', $id)->value('id');
        $this->postJson('/api/v1/orders/'.$id.'/dispatch', ['courier' => 'Test courier', 'tracking' => 'TRACK-001',
            'idempotency_key' => (string) Str::uuid(), 'items' => [['line_id' => $line, 'quantity' => 1]]])->assertOk();
        $shipment = DB::table('shipments')->where('order_id', $id)->value('id');
        $this->postJson('/api/v1/orders/'.$id.'/shipments/'.$shipment.'/delivered', ['reason' => 'Proof of delivery checked'])->assertOk();
        $this->postJson('/api/v1/orders/'.$id.'/shipments/'.$shipment.'/delivered', ['reason' => 'Proof of delivery checked'])->assertOk();

        $this->assertDatabaseCount('commission_batches', 1);
        $this->assertDatabaseHas('commission_batches', ['order_id' => $id, 'cash_basis' => '100.00', 'company_share' => '60.00', 'total_allocated' => '40.00']);
        $this->assertDatabaseHas('wallets', ['user_id' => $buyer->id, 'available' => '5.00']);
        $this->assertDatabaseHas('products', ['id' => $product->id, 'stock' => 4]);
    }

    public function test_sub_dealer_uses_product_price_and_funds_flat_salary_and_tour_separately(): void
    {
        $buyer = User::factory()->create(['role' => 'sub_dealer']);
        $product = Product::factory()->create(['mrp' => '200.00', 'sub_dealer_price' => '120.00']);
        $order = app(OrderService::class)->create($buyer, ['items' => [['product_id' => $product->id, 'quantity' => 1]], 'idempotency_key' => (string) Str::uuid()]);
        $order->update(['status' => 'delivered', 'paid' => '120.00']);
        app(CommissionService::class)->processOrder($order);

        $this->assertDatabaseHas('commission_batches', ['order_id' => $order->id, 'account_type' => 'sub_dealer', 'total_allocated' => '60.00', 'company_share' => '60.00']);
        $this->assertDatabaseHas('commission_allocations', ['order_id' => $order->id, 'category' => 'sub_dealer_salary', 'amount' => '3.60', 'status' => 'pending']);
        $this->assertDatabaseHas('commission_allocations', ['order_id' => $order->id, 'category' => 'sub_dealer_tour', 'amount' => '3.60', 'status' => 'reserved']);
        $this->assertDatabaseHas('commission_allocations', ['order_id' => $order->id, 'category' => 'additional_rank_pool', 'amount' => '4.80']);
        $this->assertDatabaseHas('wallets', ['user_id' => $buyer->id, 'available' => '6.00']);
    }

    public function test_unpaid_orders_and_disabled_finance_never_create_earnings(): void
    {
        $buyer = User::factory()->create();
        $product = Product::factory()->create(['mrp' => '200.00', 'dealer_price' => '100.00']);
        $order = app(OrderService::class)->create($buyer, ['items' => [['product_id' => $product->id, 'quantity' => 1]], 'idempotency_key' => (string) Str::uuid()]);
        $order->update(['status' => 'delivered', 'paid' => '50.00']);
        $this->assertSame([], app(CommissionService::class)->processOrder($order));
        $order->update(['paid' => '100.00']);
        config(['madina.financial_activation' => false]);
        $this->assertSame([], app(CommissionService::class)->processOrder($order));
        $this->assertDatabaseCount('commission_allocations', 0);
        $this->assertDatabaseCount('journal_entries', 0);
    }

    public function test_partial_policy_updates_validate_the_whole_schedule_and_preserve_order_snapshots(): void
    {
        $buyer = User::factory()->create();
        $product = Product::factory()->create(['mrp' => '200.00', 'dealer_price' => '100.00']);
        $order = app(OrderService::class)->create($buyer, ['items' => [['product_id' => $product->id, 'quantity' => 1]], 'idempotency_key' => (string) Str::uuid()]);
        $admin = User::factory()->create(['role' => 'super_admin']);
        $this->actingAs($admin)->postJson('/commands/commissions/rules', ['rules' => ['direct_referral' => ['percentage' => '11.00']]])->assertUnprocessable();
        $this->postJson('/commands/commissions/rules', ['rules' => ['own_dealer_cashback' => ['percentage' => '4.00']]])->assertRedirect();
        $order->update(['status' => 'delivered', 'paid' => '100.00']);
        app(CommissionService::class)->processOrder($order);

        $this->assertDatabaseCount('commission_policy_revisions', 1);
        $this->assertDatabaseHas('commission_allocations', ['order_id' => $order->id, 'category' => 'own_dealer_cashback', 'amount' => '5.00']);
        $this->assertSame('5.00', $order->refresh()->commission_policy_snapshot['rules']['own_dealer_cashback']['percentage']);
    }

    public function test_partner_profile_cannot_grant_staff_permissions_and_only_super_admin_can_assign_employee_permissions(): void
    {
        $manager = User::factory()->create(['role' => 'account_manager']);
        $partner = User::factory()->create(['profile' => ['permissions' => ['finance', 'inventory']]]);
        $this->assertFalse($partner->allows('finance'));
        $this->actingAs($partner)->getJson('/api/v1/me')->assertJsonPath('data.capabilities', []);
        $this->actingAs($manager)->patchJson('/commands/accounts/'.$partner->id, ['reason' => 'Escalation attempt', 'profile' => ['permissions' => ['finance']]])->assertUnprocessable();
        $this->patchJson('/commands/accounts/'.$partner->id, ['reason' => 'Escalation attempt', 'permissions' => ['finance']])->assertUnprocessable();
        $employee = User::factory()->create(['role' => 'employee']);
        $this->patchJson('/commands/accounts/'.$employee->id, ['reason' => 'Escalation attempt', 'permissions' => ['finance']])->assertForbidden();
        $this->actingAs(User::factory()->create(['role' => 'super_admin']))->patchJson('/commands/accounts/'.$employee->id,
            ['reason' => 'Assigned finance responsibility', 'permissions' => ['finance']])->assertRedirect();
        $this->assertTrue($employee->fresh()->allows('finance'));
        $this->actingAs($employee->fresh())->getJson('/api/v1/me')->assertJsonPath('data.capabilities.0', 'finance');
    }

    public function test_withdrawals_reserve_funds_once_and_reject_changed_replays_and_insufficient_balance(): void
    {
        $buyer = User::factory()->create();
        DB::table('wallets')->insert(['user_id' => $buyer->id, 'available' => '500.00', 'held' => '0.00', 'created_at' => now(), 'updated_at' => now()]);
        $payload = ['amount' => '200.00', 'channel' => 'Bank', 'destination' => 'TEST-ACCOUNT-1234', 'idempotency_key' => (string) Str::uuid()];
        $id = $this->actingAs($buyer)->postJson('/api/v1/withdrawals', $payload)->assertCreated()->json('data.id');
        $this->postJson('/api/v1/withdrawals', $payload)->assertCreated()->assertJsonPath('data.id', $id);
        $this->postJson('/api/v1/withdrawals', [...$payload, 'amount' => '201.00'])->assertConflict();
        $this->postJson('/api/v1/withdrawals', [...$payload, 'amount' => '199.00', 'idempotency_key' => (string) Str::uuid()])->assertUnprocessable();
        $this->postJson('/api/v1/withdrawals', [...$payload, 'amount' => '400.00', 'idempotency_key' => (string) Str::uuid()])->assertUnprocessable();

        $this->assertDatabaseCount('withdrawals', 1);
        $this->assertDatabaseHas('wallets', ['user_id' => $buyer->id, 'available' => '300.00', 'held' => '200.00']);
        $stored = DB::table('withdrawals')->first();
        $this->assertNotSame($payload['destination'], $stored->destination);
        $this->assertSame($payload['destination'], decrypt($stored->destination));
    }

    public function test_partner_can_request_withdrawal_through_rocket_channel(): void
    {
        $buyer = User::factory()->create();
        DB::table('wallets')->insert(['user_id' => $buyer->id, 'available' => '300.00', 'held' => '0.00', 'created_at' => now(), 'updated_at' => now()]);
        $this->actingAs($buyer)->postJson('/api/v1/withdrawals', [
            'amount' => '200.00',
            'channel' => 'Rocket',
            'destination' => '01812345678',
            'idempotency_key' => (string) Str::uuid(),
        ])->assertCreated();
        $this->assertDatabaseHas('withdrawals', ['user_id' => $buyer->id, 'channel' => 'Rocket', 'amount' => '200.00']);
        $this->assertDatabaseHas('wallets', ['user_id' => $buyer->id, 'available' => '100.00', 'held' => '200.00']);
    }

    public function test_pricing_and_stock_inputs_cannot_bypass_permissions(): void
    {
        $product = Product::factory()->create();
        $this->actingAs(User::factory()->create(['role' => 'editor']))->patch('/commands/products/'.$product->id,
            ['version' => (string) $product->registry_version, 'dealer_discount_pct' => '50.00', 'reason' => 'Attempted discount change'])->assertForbidden();
        $this->patch('/commands/products/'.$product->id,
            ['version' => (string) $product->registry_version, 'initial_stock' => 999, 'reason' => 'Attempted stock injection'])->assertForbidden();
        $this->post('/commands/products/'.$product->id.'/toggle-stock')->assertForbidden();
    }

    public function test_paused_ordering_is_enforced_by_the_backend_while_stock_quantities_remain_intact(): void
    {
        $product = Product::factory()->create(['mrp' => '200.00', 'dealer_price' => '100.00', 'stock' => 20]);
        $this->actingAs(User::factory()->create(['role' => 'inventory']))->post('/commands/products/'.$product->id.'/toggle-stock')->assertRedirect();
        $this->actingAs(User::factory()->create())->postJson('/api/v1/orders', ['items' => [['product_id' => $product->id, 'quantity' => 1]], 'idempotency_key' => (string) Str::uuid()])->assertUnprocessable();
        $this->assertDatabaseCount('orders', 0);
        $this->assertDatabaseHas('products', ['id' => $product->id, 'stock' => 20, 'is_stock_paused' => true]);
    }

    public function test_custom_sub_dealer_price_is_used_by_catalog_and_checkout(): void
    {
        $product = Product::factory()->create(['mrp' => '200.00', 'sub_dealer_price' => '140.00']);
        $buyer = User::factory()->create(['role' => 'sub_dealer']);
        $this->actingAs($buyer)->getJson('/api/v1/catalog')->assertOk()->assertJsonPath('data.0.price', '140.00');
        $this->postJson('/api/v1/orders', ['items' => [['product_id' => $product->id, 'quantity' => 1]], 'idempotency_key' => (string) Str::uuid()])->assertCreated()->assertJsonPath('data.total', '140.00');
        $this->assertDatabaseHas('order_lines', ['product_id' => $product->id, 'unit_price' => '140.00']);
    }

    public function test_partner_rank_and_network_views_do_not_expose_other_accounts(): void
    {
        $dealer = User::factory()->create();
        $other = User::factory()->create();
        DealerRank::create(['user_id' => $other->id, 'current_rank' => 'mo']);
        $this->actingAs($dealer)->get('/workspace/ranks')->assertOk()->assertInertia(fn (AssertableInertia $page) => $page->has('data.ranks', 0));
        $this->getJson('/api/v1/network/'.$other->id.'/orders')->assertNotFound();
        $this->getJson('/api/v1/finance/policy')->assertForbidden();
        $this->postJson('/api/v1/finance/settlements', ['kind' => 'salary', 'period_month' => '2026-09'])->assertForbidden();
    }

    public function test_stock_history_products_are_archived_with_media_and_movements_preserved(): void
    {
        $product = Product::factory()->create(['stock' => 3]);
        $admin = User::factory()->create(['role' => 'super_admin']);
        $this->actingAs($admin)->delete('/commands/products/'.$product->id, ['reason' => 'Discontinued product with counted stock'])->assertRedirect();
        $this->assertDatabaseHas('products', ['id' => $product->id, 'stock' => 3, 'active' => false]);
    }

    public function test_pricing_permission_does_not_erase_or_change_restricted_product_costs(): void
    {
        $product = Product::factory()->create(['mrp' => '200.00', 'dealer_price' => '100.00', 'cost_price' => '57.13']);
        $employee = User::factory()->create(['role' => 'employee', 'profile' => ['permissions' => ['pricing']]]);
        $this->actingAs($employee)->patch('/commands/products/'.$product->id, [
            'version' => (string) $product->registry_version, 'dealer_price' => '120.00', 'cost_price' => null, 'reason' => 'Adjusted approved dealer price',
        ])->assertRedirect();
        $this->assertSame('57.13', $product->fresh()->cost_price);
        $this->patch('/commands/products/'.$product->id, [
            'version' => (string) $product->fresh()->registry_version, 'cost_price' => '0.01', 'reason' => 'Attempted hidden cost override',
        ])->assertSessionHasErrors('cost_price');
    }

    public function test_network_purchase_timeline_is_limited_to_the_authenticated_dealers_downline(): void
    {
        $dealer = User::factory()->create();
        $child = User::factory()->create(['sponsor_id' => $dealer->id]);
        $other = User::factory()->create();
        ReferralTreePath::attachUser($child->id, $dealer->id);
        $product = Product::factory()->create(['mrp' => '200.00', 'dealer_price' => '100.00']);
        $order = app(OrderService::class)->create($child, ['items' => [['product_id' => $product->id, 'quantity' => 1]], 'idempotency_key' => (string) Str::uuid()]);
        $this->actingAs($dealer)->get('/workspace/network?member_id='.$child->id)->assertOk()->assertInertia(fn (AssertableInertia $page) => $page->where('data.member_orders.data.0.number', $order->number));
        $this->get('/workspace/network?member_id='.$other->id)->assertNotFound();
    }

    public function test_simulator_applies_sub_dealer_extras_only_to_sub_dealer_purchases_and_never_posts(): void
    {
        $product = Product::factory()->create(['mrp' => '200.00', 'dealer_price' => '100.00', 'sub_dealer_price' => '120.00']);
        foreach (['dealer' => '100.00', 'sub_dealer' => '120.00'] as $role => $amount) {
            $buyer = User::factory()->create(['role' => $role]);
            $order = app(OrderService::class)->create($buyer, ['items' => [['product_id' => $product->id, 'quantity' => 1]], 'idempotency_key' => (string) Str::uuid()]);
            $order->update(['paid' => $amount, 'status' => 'delivered']);
        }
        $this->actingAs(User::factory()->create(['role' => 'finance']))->postJson('/commands/commissions/simulate', ['overrides' => []])
            ->assertOk()->assertJsonPath('baseline.total_commissions', 100)->assertJsonPath('baseline.company_retention', 120)
            ->assertJsonPath('account_mix.sub_dealer_purchase_value', '120.00')->assertJsonPath('posts_earnings', false);
        $this->assertDatabaseCount('commission_allocations', 0);
        $this->assertDatabaseCount('wallets', 0);
    }

    public function test_a_stale_policy_version_cannot_overwrite_another_administrators_update(): void
    {
        $admin = User::factory()->create(['role' => 'super_admin']);
        $this->actingAs($admin)->patchJson('/api/v1/finance/policy', ['rules' => ['own_dealer_cashback' => ['percentage' => '4.00', 'version' => 1]]])->assertOk();
        $this->patchJson('/api/v1/finance/policy', ['rules' => ['own_dealer_cashback' => ['percentage' => '3.00', 'version' => 1]]])->assertUnprocessable();
        $this->assertDatabaseHas('commission_rule_parameters', ['rule_key' => 'own_dealer_cashback', 'percentage' => '4.00', 'version' => 2]);
        $this->assertDatabaseCount('commission_policy_revisions', 1);
    }
}
