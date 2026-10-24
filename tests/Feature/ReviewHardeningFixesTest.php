<?php

namespace Tests\Feature;

use App\Domain\Accounts\PartnerDashboardHome;
use App\Domain\Orders\OrderService;
use App\Models\Product;
use App\Models\ReferralTreePath;
use App\Models\User;
use App\Support\DashboardManagement;
use App\Support\Money;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Tests\TestCase;

class ReviewHardeningFixesTest extends TestCase
{
    use RefreshDatabase;

    public function test_maintenance_mode_blocks_partner_order_creation(): void
    {
        $dealer = User::factory()->create(['role' => 'dealer', 'status' => 'active']);
        ReferralTreePath::attachUser($dealer->id, null);
        $product = Product::factory()->create(['mrp' => '200.00', 'dealer_price' => '100.00', 'active' => true, 'stock' => 100]);

        DashboardManagement::save(['dealer_maintenance_mode' => true]);

        $this->expectException(ValidationException::class);
        app(OrderService::class)->create($dealer, [
            'items' => [['product_id' => $product->id, 'quantity' => 1]],
            'idempotency_key' => (string) Str::uuid(),
        ]);
    }

    public function test_minimum_order_amount_is_enforced(): void
    {
        $dealer = User::factory()->create(['role' => 'dealer', 'status' => 'active']);
        ReferralTreePath::attachUser($dealer->id, null);
        $product = Product::factory()->create(['mrp' => '200.00', 'dealer_price' => '100.00', 'active' => true, 'stock' => 100]);

        DashboardManagement::save(['dealer_min_order' => '500.00']);

        try {
            app(OrderService::class)->create($dealer, [
                'items' => [['product_id' => $product->id, 'quantity' => 1]],
                'idempotency_key' => (string) Str::uuid(),
            ]);
            $this->fail('Expected minimum order validation.');
        } catch (ValidationException $exception) {
            $this->assertStringContainsString('Minimum order amount', $exception->errors()['items'][0] ?? '');
        }

        $order = app(OrderService::class)->create($dealer, [
            'items' => [['product_id' => $product->id, 'quantity' => 5]],
            'idempotency_key' => (string) Str::uuid(),
        ]);
        $this->assertSame('500.00', Money::decimal(Money::cents($order->total)));
    }

    public function test_partner_cannot_hit_staff_only_command_routes(): void
    {
        $dealer = User::factory()->create(['role' => 'dealer', 'status' => 'active']);
        $target = User::factory()->create(['role' => 'dealer', 'status' => 'active']);

        $this->actingAs($dealer)->onPortal('dealer')
            ->post('/commands/accounts/'.$target->id.'/benefit-controls', [
                'unconditional_salary' => 1,
                'reason' => 'Should be blocked for partners',
            ])
            ->assertForbidden();

        $this->actingAs($dealer)->onPortal('dealer')
            ->post('/commands/operations/payment-accounts', [
                'name' => 'Should fail',
                'channel' => 'Bank',
                'account_number' => '123',
                'reason' => 'Partner must not open staff finance ops',
            ])
            ->assertForbidden();
    }

    public function test_accounts_staff_cannot_grant_unconditional_benefits_without_finance_approve(): void
    {
        $accounts = User::factory()->create([
            'role' => 'employee',
            'status' => 'active',
            'profile' => ['permissions' => ['accounts']],
        ]);
        $dealer = User::factory()->create(['role' => 'dealer', 'status' => 'active']);

        $this->actingAs($accounts)->onPortal('manage')
            ->post('/commands/accounts/'.$dealer->id.'/benefit-controls', [
                'unconditional_salary' => 1,
                'reason' => 'Accounts-only must not waive benefits',
            ])
            ->assertForbidden();
    }

    public function test_admin_orders_today_counts_across_dhaka_midnight_in_utc(): void
    {
        CarbonImmutable::setTestNow(CarbonImmutable::parse('2026-10-24 20:15:00', 'UTC'));

        try {
            $admin = User::factory()->create(['role' => 'super_admin', 'status' => 'active']);
            $dealer = User::factory()->create(['role' => 'dealer', 'status' => 'active']);
            ReferralTreePath::attachUser($dealer->id, null);
            $product = Product::factory()->create(['mrp' => '200.00', 'dealer_price' => '100.00', 'active' => true, 'stock' => 50]);
            $order = app(OrderService::class)->create($dealer, [
                'items' => [['product_id' => $product->id, 'quantity' => 1]],
                'idempotency_key' => (string) Str::uuid(),
            ]);
            $order->update(['status' => 'submitted']);

            $this->actingAs($admin)->onPortal('sadmin')->get('/')
                ->assertOk()
                ->assertInertia(fn ($page) => $page
                    ->where('data.admin_home.snapshot.orders_today', 1));

            $home = app(PartnerDashboardHome::class)->for($dealer->fresh());
            $this->assertSame('0.00', $home['snapshot']['own_sales_today']);
        } finally {
            CarbonImmutable::setTestNow();
        }
    }

    public function test_user_mass_assignment_blocks_role_and_status(): void
    {
        $user = User::factory()->create(['role' => 'dealer', 'status' => 'active']);
        $user->update([
            'role' => 'super_admin',
            'status' => 'suspended',
            'sponsor_id' => 999999,
            'dealer_code' => 'HACKED',
            'name' => 'Still Allowed Name',
        ]);
        $user->refresh();

        $this->assertSame('dealer', $user->role);
        $this->assertSame('active', $user->status);
        $this->assertNotSame('HACKED', $user->dealer_code);
        $this->assertSame('Still Allowed Name', $user->name);
    }
}
