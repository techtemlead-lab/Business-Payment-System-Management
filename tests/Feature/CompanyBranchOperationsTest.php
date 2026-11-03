<?php

namespace Tests\Feature;

use App\Domain\Inventory\BranchOperations;
use App\Domain\Inventory\StockService;
use App\Models\BranchProductStock;
use App\Models\BranchStaffAssignment;
use App\Models\CompanyBranch;
use App\Models\Product;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\TestCase;

class CompanyBranchOperationsTest extends TestCase
{
    use RefreshDatabase;

    public function test_head_office_seed_exists_and_stock_adjustments_write_branch_rows(): void
    {
        $this->assertDatabaseHas('company_branches', ['code' => 'HO-PIRGANJ']);
        $hub = CompanyBranch::query()->where('code', 'HO-PIRGANJ')->firstOrFail();

        $product = Product::factory()->create(['stock' => 0]);
        $actor = User::factory()->staff('inventory')->create(['active_branch_id' => $hub->id]);
        app(StockService::class)->adjust($actor, [
            'product_id' => $product->id,
            'quantity' => 7,
            'reason' => 'Opening branch stock',
            'reference' => (string) Str::uuid(),
            'branch_id' => $hub->id,
        ]);

        $this->assertSame(7, $product->fresh()->stock);
        $this->assertDatabaseHas('branch_product_stocks', [
            'branch_id' => $hub->id,
            'product_id' => $product->id,
            'quantity' => 7,
        ]);
        $this->assertDatabaseHas('stock_movements', [
            'product_id' => $product->id,
            'branch_id' => $hub->id,
            'quantity' => 7,
        ]);
    }

    public function test_staff_can_be_assigned_and_select_active_branch(): void
    {
        $owner = User::factory()->staff('super_admin')->create();
        $staff = User::factory()->staff('inventory')->create();
        $hub = CompanyBranch::query()->where('code', 'HO-PIRGANJ')->firstOrFail();
        $branch = CompanyBranch::factory()->create([
            'code' => 'DHK-01',
            'name' => 'Dhaka Delivery Hub',
            'division' => 'Dhaka',
            'is_delivery_point' => true,
            'is_stock_hub' => true,
        ]);

        $this->actingAs($owner)
            ->postJson('/api/v1/branches/'.$branch->id.'/staff', [
                'user_id' => $staff->id,
                'duty' => 'storekeeper',
                'is_primary' => true,
            ])
            ->assertOk()
            ->assertJsonPath('data.user_id', $staff->id);

        $this->assertDatabaseHas('branch_staff_assignments', [
            'branch_id' => $branch->id,
            'user_id' => $staff->id,
            'duty' => 'storekeeper',
            'is_primary' => true,
        ]);
        $this->assertSame($branch->id, $staff->fresh()->active_branch_id);

        $this->actingAs($staff->fresh())
            ->postJson('/api/v1/branches/'.$hub->id.'/select')
            ->assertOk()
            ->assertJsonPath('data.active_branch_id', $hub->id);

        $limited = User::factory()->create([
            'role' => 'employee',
            'status' => 'active',
            'profile' => ['permissions' => ['content']],
        ]);
        $this->actingAs($limited)
            ->postJson('/api/v1/branches/'.$hub->id.'/select')
            ->assertForbidden();

        app(BranchOperations::class)->assignStaff($owner, $hub, [
            'user_id' => $limited->id,
            'duty' => 'dispatcher',
            'is_primary' => true,
        ]);

        $this->assertSame($hub->id, $limited->fresh()->active_branch_id);
        $this->assertDatabaseHas('branch_staff_assignments', [
            'branch_id' => $hub->id,
            'user_id' => $limited->id,
            'duty' => 'dispatcher',
        ]);

        $this->actingAs($limited->fresh())
            ->postJson('/api/v1/branches/'.$branch->id.'/select')
            ->assertForbidden();

        app(BranchOperations::class)->assignStaff($owner, $branch, [
            'user_id' => $limited->id,
            'duty' => 'dispatcher',
            'is_primary' => false,
        ]);

        $this->actingAs($limited->fresh())
            ->postJson('/api/v1/branches/'.$branch->id.'/select')
            ->assertOk()
            ->assertJsonPath('data.active_branch_id', $branch->id);
    }

    public function test_stock_adjustments_are_branch_scoped_and_rollup_company_stock(): void
    {
        $actor = User::factory()->staff('inventory')->create();
        $hub = CompanyBranch::query()->where('code', 'HO-PIRGANJ')->firstOrFail();
        $secondary = CompanyBranch::factory()->create(['code' => 'CTG-01', 'name' => 'Chattogram Hub']);
        $product = Product::factory()->create(['stock' => 0]);

        $this->actingAs($actor)->postJson('/api/v1/inventory/adjustments', [
            'product_id' => $product->id,
            'quantity' => 10,
            'reason' => 'Receive at HO',
            'reference' => (string) Str::uuid(),
            'branch_id' => $hub->id,
        ])->assertOk()->assertJsonPath('data.stock', 10);

        $this->actingAs($actor)->postJson('/api/v1/inventory/adjustments', [
            'product_id' => $product->id,
            'quantity' => 5,
            'reason' => 'Receive at CTG',
            'reference' => (string) Str::uuid(),
            'branch_id' => $secondary->id,
        ])->assertOk()->assertJsonPath('data.stock', 15);

        $this->assertSame(10, (int) BranchProductStock::query()->where('branch_id', $hub->id)->where('product_id', $product->id)->value('quantity'));
        $this->assertSame(5, (int) BranchProductStock::query()->where('branch_id', $secondary->id)->where('product_id', $product->id)->value('quantity'));
        $this->assertSame(15, $product->fresh()->stock);
    }

    public function test_delivery_points_endpoint_lists_active_delivery_branches(): void
    {
        $actor = User::factory()->staff('inventory')->create();
        CompanyBranch::factory()->create([
            'code' => 'DP-01',
            'name' => 'Active Delivery',
            'is_delivery_point' => true,
            'is_active' => true,
        ]);
        CompanyBranch::factory()->create([
            'code' => 'DP-OFF',
            'name' => 'Inactive Delivery',
            'is_delivery_point' => true,
            'is_active' => false,
        ]);
        CompanyBranch::factory()->create([
            'code' => 'STOCK-ONLY',
            'name' => 'Stock Only',
            'is_delivery_point' => false,
            'is_stock_hub' => true,
        ]);

        $response = $this->actingAs($actor)->getJson('/api/v1/delivery-points')->assertOk();
        $codes = collect($response->json('data'))->pluck('code');
        $this->assertTrue($codes->contains('HO-PIRGANJ'));
        $this->assertTrue($codes->contains('DP-01'));
        $this->assertFalse($codes->contains('DP-OFF'));
        $this->assertFalse($codes->contains('STOCK-ONLY'));
    }

    public function test_branches_portal_section_and_command_create(): void
    {
        $owner = User::factory()->staff('super_admin')->create();

        $this->actingAs($owner)
            ->onPortal('sadmin')
            ->get('/branches')
            ->assertOk();

        $this->actingAs($owner)
            ->onPortal('sadmin')
            ->from('/branches')
            ->post('/commands/branches', [
                'code' => 'SYL-01',
                'name' => 'Sylhet Hub',
                'division' => 'Sylhet',
                'district' => 'Sylhet',
                'is_active' => true,
                'is_delivery_point' => true,
                'is_stock_hub' => true,
            ])
            ->assertRedirect('/branches');

        $this->assertDatabaseHas('company_branches', ['code' => 'SYL-01', 'name' => 'Sylhet Hub']);
        $this->assertSame(0, BranchStaffAssignment::query()->whereHas('branch', fn ($q) => $q->where('code', 'SYL-01'))->count());
    }

    public function test_empty_branch_can_be_deleted_and_head_office_cannot(): void
    {
        $owner = User::factory()->staff('super_admin')->create();
        $headOffice = CompanyBranch::query()->where('code', 'HO-PIRGANJ')->firstOrFail();
        $empty = CompanyBranch::factory()->create(['code' => 'TMP-01', 'name' => 'Temporary Hub']);

        $this->actingAs($owner)->onPortal('sadmin')->from('/branches')
            ->delete('/commands/branches/'.$headOffice->id)
            ->assertRedirect('/branches')
            ->assertSessionHasErrors('branch');
        $this->assertModelExists($headOffice);

        $this->from('/branches')
            ->delete('/commands/branches/'.$empty->id)
            ->assertRedirect('/branches')
            ->assertSessionHas('success');
        $this->assertModelMissing($empty);
    }

    public function test_branch_with_stock_history_cannot_be_deleted(): void
    {
        $owner = User::factory()->staff('super_admin')->create();
        $branch = CompanyBranch::factory()->create(['code' => 'CTG-01', 'name' => 'Chattogram Hub']);
        $product = Product::factory()->create(['stock' => 0]);
        app(StockService::class)->adjust($owner, [
            'product_id' => $product->id,
            'quantity' => 3,
            'reason' => 'Opening branch stock',
            'reference' => (string) Str::uuid(),
            'branch_id' => $branch->id,
            'kind' => BranchOperations::KindProductIn,
        ]);

        $this->actingAs($owner)->onPortal('sadmin')->from('/branches')
            ->delete('/commands/branches/'.$branch->id)
            ->assertRedirect('/branches')
            ->assertSessionHasErrors('branch');
        $this->assertModelExists($branch);
    }

    public function test_all_branches_view_lists_stock_with_a_branch_name(): void
    {
        $owner = User::factory()->staff('super_admin')->create();
        $headOffice = CompanyBranch::query()->where('code', 'HO-PIRGANJ')->firstOrFail();
        $other = CompanyBranch::factory()->create(['code' => 'RAJ-01', 'name' => 'Rajshahi Hub']);
        $product = Product::factory()->create([
            'name' => 'Napa',
            'sku' => 'NAPA-ALL',
            'strength' => '100',
            'stock' => 10,
        ]);
        BranchProductStock::query()->create([
            'branch_id' => $headOffice->id,
            'product_id' => $product->id,
            'quantity' => 4,
        ]);
        BranchProductStock::query()->create([
            'branch_id' => $other->id,
            'product_id' => $product->id,
            'quantity' => 6,
        ]);

        $this->actingAs($owner)->onPortal('sadmin')->from('/inventory')
            ->post('/commands/branches/view-all')
            ->assertRedirect('/inventory')
            ->assertSessionHas('success');

        $this->actingAs($owner)->onPortal('sadmin')->get('/inventory')
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->where('workspaceBranches.view', 'all')
                ->where('data.branch_view', 'all')
                ->where('data.active_branch.name', 'All branches')
                ->where('data.products', function (mixed $products): bool {
                    $rows = collect($products)->where('sku', 'NAPA-ALL');

                    return $rows->count() === 2
                        && $rows->pluck('branch_name')->sort()->values()->all() === ['Head Office · Pirganj', 'Rajshahi Hub'];
                }));
    }
}
