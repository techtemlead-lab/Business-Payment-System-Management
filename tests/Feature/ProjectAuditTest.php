<?php

namespace Tests\Feature;

use App\Domain\Commissions\CommissionService;
use App\Domain\Orders\OrderService;
use App\Models\AreaDealerAssignment;
use App\Models\DealerRank;
use App\Models\Product;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Inertia\Testing\AssertableInertia;
use Tests\TestCase;

class ProjectAuditTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->withoutVite();
    }

    public function test_restock_notifies_active_partners_once_and_failed_or_replayed_movements_add_no_notices(): void
    {
        $dealer = User::factory()->create();
        $subDealer = User::factory()->create(['role' => 'sub_dealer']);
        User::factory()->create(['status' => 'suspended']);
        $admin = User::factory()->create(['role' => 'super_admin']);
        $product = Product::factory()->create(['stock' => 0]);
        $data = ['product_id' => $product->id, 'quantity' => 5, 'reference' => (string) Str::uuid(), 'reason' => 'Supplier delivery'];

        $this->actingAs($admin)->postJson('/api/v1/inventory/adjustments', $data)->assertOk();
        $this->assertDatabaseHas('account_notices', ['user_id' => $dealer->id, 'title' => 'Product available']);
        $this->assertDatabaseHas('account_notices', ['user_id' => $subDealer->id, 'title' => 'Product available']);
        $this->assertDatabaseCount('account_notices', 2);

        $this->postJson('/api/v1/inventory/adjustments', $data)->assertUnprocessable()->assertJsonValidationErrors('reference');
        $this->postJson('/api/v1/inventory/adjustments', [...$data, 'quantity' => -6, 'reference' => (string) Str::uuid()])
            ->assertUnprocessable()->assertJsonValidationErrors('quantity');
        $this->postJson('/api/v1/inventory/adjustments', [...$data, 'reference' => (string) Str::uuid()])->assertOk();
        $this->assertDatabaseHas('products', ['id' => $product->id, 'stock' => 10]);
        $this->assertDatabaseCount('account_notices', 2);
        $this->actingAs($dealer)->get('/workspace/notifications')->assertOk()->assertInertia(fn (AssertableInertia $page) => $page
            ->where('data.notices.total', 1)->where('data.notices.data.0.title', 'Product available'));
    }

    public function test_paused_or_archived_stock_is_not_advertised_and_resuming_available_stock_notifies_partners(): void
    {
        $dealer = User::factory()->create();
        $admin = User::factory()->create(['role' => 'super_admin']);
        $paused = Product::factory()->create(['stock' => 0, 'is_stock_paused' => true]);
        $archived = Product::factory()->create(['stock' => 0, 'active' => false]);
        foreach ([$paused, $archived] as $product) {
            $this->actingAs($admin)->postJson('/api/v1/inventory/adjustments', ['product_id' => $product->id, 'quantity' => 5,
                'reference' => (string) Str::uuid(), 'reason' => 'Received counted stock'])->assertOk();
        }
        $this->assertDatabaseCount('account_notices', 0);
        $this->post('/commands/products/'.$paused->id.'/toggle-stock')->assertRedirect();
        $this->assertDatabaseHas('account_notices', ['user_id' => $dealer->id, 'title' => 'Product available']);
        $this->post('/commands/products/'.$paused->id.'/toggle-stock')->assertRedirect();
        $this->assertDatabaseCount('account_notices', 1);
    }

    public function test_new_product_with_opening_stock_appears_in_partner_notifications(): void
    {
        $dealer = User::factory()->create();
        $admin = User::factory()->create(['role' => 'super_admin']);
        $this->actingAs($admin)->post('/commands/products', ['name' => 'New stocked product', 'sku' => 'AUDIT-STOCK',
            'category' => 'Care', 'unit' => 'pack', 'mrp' => '200.00', 'dealer_price' => '100.00',
            'initial_stock' => 4, 'reason' => 'Opening inventory approved'])->assertRedirect();
        $this->assertDatabaseHas('account_notices', ['user_id' => $dealer->id, 'title' => 'Product available',
            'body' => 'New stocked product (AUDIT-STOCK) is now available in the product showcase.']);
    }

    public function test_dispatch_warns_inventory_staff_when_stock_crosses_low_and_zero_thresholds(): void
    {
        $buyer = User::factory()->create();
        $admin = User::factory()->create(['role' => 'super_admin']);
        $inventory = User::factory()->create(['role' => 'employee', 'profile' => ['permissions' => ['inventory']]]);
        $editor = User::factory()->create(['role' => 'editor']);
        $product = Product::factory()->create(['stock' => 3, 'low_stock_at' => 2]);
        $service = app(OrderService::class);
        $order = $service->create($buyer, ['items' => [['product_id' => $product->id, 'quantity' => 3]], 'idempotency_key' => (string) Str::uuid()]);
        $service->decision($admin, $order, 'approved', 'Approved credit sale', true);
        $line = $order->lines()->first();

        foreach ([2, 1] as $quantity) {
            $this->actingAs($admin)->postJson('/api/v1/orders/'.$order->id.'/dispatch', ['courier' => 'Test courier', 'tracking' => 'AUDIT-TRACK',
                'idempotency_key' => (string) Str::uuid(), 'items' => [['line_id' => $line->id, 'quantity' => $quantity]]])->assertOk();
        }
        $this->assertDatabaseHas('account_notices', ['user_id' => $inventory->id, 'title' => 'Low stock alert']);
        $this->assertDatabaseHas('account_notices', ['user_id' => $inventory->id, 'title' => 'Product out of stock']);
        $this->assertDatabaseMissing('account_notices', ['user_id' => $editor->id]);
        $this->assertDatabaseHas('products', ['id' => $product->id, 'stock' => 0]);
    }

    public function test_employee_without_product_permissions_cannot_read_product_editor_or_private_overview_prices(): void
    {
        Storage::fake('local');
        $image = UploadedFile::fake()->createWithContent('private.png', file_get_contents(public_path('madina-header-logo.png')));
        $path = $image->store('product-media/images', 'local');
        $product = Product::factory()->create(['active' => false, 'image_path' => $path]);
        $employee = User::factory()->create(['role' => 'employee', 'profile' => ['permissions' => []]]);
        $this->actingAs($employee)->get('/company/products/'.$product->id)->assertForbidden();
        $this->get('/products/'.$product->id.'/image')->assertNotFound();
        $this->get('/company')->assertOk()->assertInertia(fn (AssertableInertia $page) => $page->missing('data.products'));

        $employee->update(['profile' => ['permissions' => ['pricing']]]);
        $this->get('/company/products/'.$product->id)->assertOk()->assertInertia(fn (AssertableInertia $page) => $page
            ->where('product.id', $product->id)->missing('product.cost_price'));
        $this->get('/products/'.$product->id.'/image')->assertOk();
    }

    public function test_sub_dealer_salary_view_reflects_configured_rates(): void
    {
        $admin = User::factory()->create(['role' => 'super_admin']);
        $subDealer = User::factory()->create(['role' => 'sub_dealer']);
        $this->actingAs($admin)->patchJson('/api/v1/finance/policy', ['rules' => [
            'sub_dealer_salary' => ['percentage' => '2.00'], 'sub_dealer_tour' => ['percentage' => '1.00'],
        ]])->assertOk();
        $this->actingAs($subDealer)->get('/workspace/salary')->assertOk()->assertInertia(fn (AssertableInertia $page) => $page
            ->where('data.salary.salary_rate', '2.00')->where('data.salary.tour_rate', '1.00'));
    }

    public function test_salary_qualification_preview_shows_the_selected_month_volume_instead_of_a_stale_cached_counter(): void
    {
        config(['madina.financial_activation' => true]);
        $this->travelTo(now()->setDate(2026, 9, 15)->setTime(12, 0));
        $buyer = User::factory()->create();
        $product = Product::factory()->create(['mrp' => '200.00', 'dealer_price' => '100.00']);
        $order = app(OrderService::class)->create($buyer, ['items' => [['product_id' => $product->id, 'quantity' => 100]], 'idempotency_key' => (string) Str::uuid()]);
        $order->update(['paid' => '10000.00', 'status' => 'delivered']);
        app(CommissionService::class)->processOrder($order);
        DealerRank::where('user_id', $buyer->id)->update(['monthly_purchase_total' => '999.00']);
        $this->travelTo(now()->setDate(2026, 10, 16));

        $this->actingAs(User::factory()->create(['role' => 'finance']))->get('/company/settlements?period_month=2026-09')
            ->assertOk()->assertInertia(fn (AssertableInertia $page) => $page
            ->where('data.settlement_state.qualified_salary_dealers_count', 1)
            ->where('data.settlement_state.qualified_salary_dealers.0.monthly_volume', 10000));
    }

    public function test_salary_screen_separates_partner_reserves_and_reports_only_the_amount_actually_distributed(): void
    {
        config(['madina.financial_activation' => true]);
        $this->travelTo(now()->setDate(2026, 9, 15)->setTime(12, 0));
        $buyer = User::factory()->create(['role' => 'sub_dealer']);
        $product = Product::factory()->create(['mrp' => '200.00', 'sub_dealer_price' => '120.00']);
        $order = app(OrderService::class)->create($buyer, ['items' => [['product_id' => $product->id, 'quantity' => 10]], 'idempotency_key' => (string) Str::uuid()]);
        $order->update(['paid' => '1200.00', 'status' => 'delivered']);
        app(CommissionService::class)->processOrder($order);
        $this->travelTo(now()->setDate(2026, 10, 16));

        $this->actingAs(User::factory()->create(['role' => 'finance']))->get('/company/settlements?period_month=2026-09')
            ->assertOk()->assertInertia(fn (AssertableInertia $page) => $page
            ->where('data.settlement_state.dealer_salary_pool_pending', 24)
            ->where('data.settlement_state.sub_dealer_salary_pending', 36));
        $this->post('/commands/commissions/settle-salary', ['period_month' => '2026-09', 'request_key' => (string) Str::uuid()])->assertRedirect()
            ->assertSessionHas('success', __('portal.flash_salary_pool_distributed', [
                'period' => '2026-09',
                'amount' => '36.00',
                'count' => 1,
            ]).__('portal.flash_salary_pool_reserve_extra', ['amount' => '24.00']));
        $this->assertDatabaseHas('commission_settlement_payouts', ['user_id' => $buyer->id, 'kind' => 'salary', 'amount' => '36.00']);
        $this->assertDatabaseHas('commission_allocations', [
            'order_id' => $order->id,
            'category' => 'dealer_salary_settlement_reserve',
            'status' => 'reserved',
            'amount' => '24.00',
        ]);
    }

    public function test_royalty_preview_counts_a_leader_who_is_eligible_during_the_grace_period(): void
    {
        $this->travelTo(now()->setDate(2026, 10, 16)->setTime(12, 0));
        $leader = User::factory()->create();
        DealerRank::create(['user_id' => $leader->id, 'current_rank' => 'mo', 'achieved_at' => now()]);

        $this->actingAs(User::factory()->create(['role' => 'finance']))->get('/company/settlements?period_month=2026-10')
            ->assertOk()->assertInertia(fn (AssertableInertia $page) => $page
            ->where('data.settlement_state.qualified_rank_leaders_count', 1)
            ->where('data.settlement_state.qualified_rank_leaders.0.in_grace_period', true)
            ->where('data.settlement_state.qualified_rank_leaders.0.is_qualified', false));
    }

    public function test_area_commissions_match_the_full_territory_path_ignoring_case_and_surrounding_spaces(): void
    {
        config(['madina.financial_activation' => true]);
        $recipient = User::factory()->create();
        $other = User::factory()->create();
        $admin = User::factory()->create(['role' => 'super_admin']);
        foreach ([$recipient->id => 'Dhaka', $other->id => 'Other division'] as $id => $division) {
            $this->actingAs($admin)->postJson('/commands/area-dealers', ['user_id' => $id, 'level' => 'district', 'zone_name' => 'Test district',
                'division' => $division, 'district' => 'Gazipur', 'monthly_quota' => '0.00'])->assertRedirect();
        }
        $buyer = User::factory()->create(['division' => ' DHAKA ', 'district' => ' gazipur ']);
        $product = Product::factory()->create(['mrp' => '200.00', 'dealer_price' => '100.00']);
        $order = app(OrderService::class)->create($buyer, ['items' => [['product_id' => $product->id, 'quantity' => 1]], 'idempotency_key' => (string) Str::uuid()]);
        $order->update(['paid' => '100.00', 'status' => 'delivered']);
        app(CommissionService::class)->processOrder($order);

        $this->assertDatabaseHas('commission_allocations', ['order_id' => $order->id, 'category' => 'area_district',
            'user_id' => $recipient->id, 'amount' => '2.00', 'status' => 'available']);
        $this->assertDatabaseMissing('commission_allocations', ['order_id' => $order->id, 'category' => 'area_district', 'user_id' => $other->id]);
        $this->assertDatabaseHas('wallets', ['user_id' => $recipient->id, 'available' => '2.00']);
    }

    public function test_ambiguous_case_variant_legacy_area_assignments_do_not_receive_wallet_credits(): void
    {
        config(['madina.financial_activation' => true]);
        foreach (['Dhaka', ' DHAKA '] as $division) {
            $recipient = User::factory()->create();
            AreaDealerAssignment::create(['user_id' => $recipient->id, 'level' => 'division', 'zone_name' => 'Legacy zone',
                'division' => $division, 'monthly_quota' => '0.00', 'is_active' => true]);
        }
        $buyer = User::factory()->create(['division' => 'Dhaka']);
        $product = Product::factory()->create(['mrp' => '200.00', 'dealer_price' => '100.00']);
        $order = app(OrderService::class)->create($buyer, ['items' => [['product_id' => $product->id, 'quantity' => 1]], 'idempotency_key' => (string) Str::uuid()]);
        $order->update(['paid' => '100.00', 'status' => 'delivered']);
        app(CommissionService::class)->processOrder($order);

        $this->assertDatabaseHas('commission_allocations', ['order_id' => $order->id, 'category' => 'area_division',
            'user_id' => null, 'amount' => '2.00', 'status' => 'unassigned_reserve']);
        $this->assertDatabaseCount('wallets', 1);
        $this->assertDatabaseHas('wallets', ['user_id' => $buyer->id, 'available' => '5.00']);
    }
}
