<?php

namespace Tests\Feature;

use App\Domain\Commissions\CommissionPolicy;
use App\Domain\Commissions\CommissionPosting;
use App\Domain\Orders\OrderService;
use App\Models\Product;
use App\Models\User;
use Database\Seeders\CommissionRuleSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\TestCase;

class ScheduledPolicyTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->travelTo(now()->setDate(2026, 10, 17)->startOfDay());
        $this->seed(CommissionRuleSeeder::class);
    }

    public function test_scheduled_global_rules_require_independent_approval_and_respect_effective_and_expiry_dates(): void
    {
        $admin = User::factory()->create(['role' => 'finance']);
        $reviewer = User::factory()->create(['role' => 'finance']);
        $data = ['scope' => 'global', 'rules' => ['own_dealer_cashback' => ['percentage' => '4.00']],
            'effective_at' => now()->addHour()->toIso8601String(), 'expires_at' => now()->addHours(2)->toIso8601String(), 'reason' => 'Approved future commission campaign'];
        $id = $this->actingAs($admin)->postJson('/api/v1/operations/policy-schedules', $data)->assertOk()->json('data.id');
        $url = '/api/v1/operations/policy-schedules/'.$id.'/review';
        $this->postJson($url, ['decision' => 'approved', 'reason' => 'Reviewed campaign rules'])->assertUnprocessable();
        $this->actingAs($reviewer)->postJson($url, ['decision' => 'approved', 'reason' => 'Reviewed campaign rules'])->assertOk();
        $this->assertSame('5.00', app(CommissionPolicy::class)->snapshot()['rules']['own_dealer_cashback']['percentage']);
        $this->travel(61)->minutes();
        $this->assertSame('4.00', app(CommissionPolicy::class)->snapshot()['rules']['own_dealer_cashback']['percentage']);
        $this->travel(61)->minutes();
        $this->assertSame('5.00', app(CommissionPolicy::class)->snapshot()['rules']['own_dealer_cashback']['percentage']);
        $this->assertDatabaseCount('journal_entries', 0);
        $this->assertDatabaseCount('commission_allocations', 0);
    }

    public function test_buyer_override_is_snapshotted_and_later_cancellation_does_not_reprice_existing_orders(): void
    {
        config(['madina.financial_activation' => true]);
        $admin = User::factory()->create(['role' => 'finance']);
        $reviewer = User::factory()->create(['role' => 'finance']);
        $buyer = User::factory()->create();
        $other = User::factory()->create();
        $product = Product::factory()->create(['dealer_price' => '100.00']);
        $data = ['scope' => 'buyer', 'user_id' => $buyer->id, 'rules' => ['own_dealer_cashback' => ['percentage' => '4.00']], 'effective_at' => now()->toIso8601String(), 'reason' => 'Agreed individual purchase policy'];
        $id = $this->actingAs($admin)->postJson('/api/v1/operations/policy-schedules', $data)->assertOk()->json('data.id');
        $url = '/api/v1/operations/policy-schedules/'.$id.'/review';
        $this->actingAs($reviewer)->postJson($url, ['decision' => 'approved', 'reason' => 'Reviewed individual purchase policy'])->assertOk();
        $order = app(OrderService::class)->create($buyer, ['items' => [['product_id' => $product->id, 'quantity' => 1]], 'idempotency_key' => (string) Str::uuid()]);
        $this->assertSame($id, $order->commission_policy_snapshot['buyer_schedule_id']);
        $this->assertSame('5.00', app(CommissionPolicy::class)->snapshot(true, $other)['rules']['own_dealer_cashback']['percentage']);
        $this->postJson($url, ['decision' => 'cancelled', 'reason' => 'Campaign ended before delivery'])->assertOk();
        $order->update(['status' => 'delivered', 'paid' => '100.00']);
        app(CommissionPosting::class)->process($order);
        $this->assertDatabaseHas('commission_allocations', ['order_id' => $order->id, 'category' => 'own_dealer_cashback', 'amount' => '4.00']);
        $this->assertSame('5.00', app(CommissionPolicy::class)->snapshot(true, $buyer)['rules']['own_dealer_cashback']['percentage']);
    }

    public function test_individual_recipient_caps_preserve_the_original_pool_budget_without_redistribution(): void
    {
        config(['madina.financial_activation' => true]);
        $sponsor = User::factory()->create();
        $buyer = User::factory()->create(['sponsor_id' => $sponsor->id]);
        $admin = User::factory()->create(['role' => 'finance']);
        $id = $this->actingAs($admin)->postJson('/api/v1/operations/policy-schedules', ['scope' => 'recipient', 'user_id' => $sponsor->id,
            'rules' => ['direct_referral' => ['percentage' => '3.00']], 'effective_at' => now()->toIso8601String(), 'reason' => 'Recipient earning cap approved'])->assertOk()->json('data.id');
        $this->actingAs(User::factory()->create(['role' => 'finance']))->postJson('/api/v1/operations/policy-schedules/'.$id.'/review', ['decision' => 'approved', 'reason' => 'Independently reviewed rate cap'])->assertOk();
        $product = Product::factory()->create(['dealer_price' => '100.00']);
        $order = app(OrderService::class)->create($buyer, ['items' => [['product_id' => $product->id, 'quantity' => 1]], 'idempotency_key' => (string) Str::uuid()]);
        $order->update(['status' => 'delivered', 'paid' => '100.00']);
        app(CommissionPosting::class)->process($order);
        $this->assertDatabaseHas('commission_allocations', ['order_id' => $order->id, 'category' => 'direct_referral', 'user_id' => $sponsor->id, 'amount' => '3.00']);
        $this->assertDatabaseHas('commission_allocations', ['order_id' => $order->id, 'category' => 'direct_referral_override_reserve', 'user_id' => null, 'amount' => '7.00']);
        $this->assertSame('40.00', DB::table('commission_batches')->value('total_allocated'));
    }

    public function test_invalid_or_unfunded_schedules_and_unauthorized_proposals_are_rejected(): void
    {
        $data = ['scope' => 'global', 'rules' => ['direct_referral' => ['percentage' => '99.00']], 'effective_at' => now()->toIso8601String(), 'reason' => 'Invalid unfunded commission policy'];
        $this->actingAs(User::factory()->create())->postJson('/api/v1/operations/policy-schedules', $data)->assertForbidden();
        $this->actingAs(User::factory()->create(['role' => 'finance']))->postJson('/api/v1/operations/policy-schedules', $data)->assertUnprocessable();
        $this->postJson('/api/v1/operations/policy-schedules', [...$data, 'effective_at' => now()->subDay()->toIso8601String()])->assertUnprocessable();
        $this->assertDatabaseCount('commission_policy_schedules', 0);
    }
}
