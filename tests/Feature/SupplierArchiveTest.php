<?php

namespace Tests\Feature;

use App\Models\CompanyBranch;
use App\Models\Product;
use App\Models\PurchaseOrder;
use App\Models\Supplier;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\TestCase;

/**
 * An archived supplier leaves the pick lists, stays on its old orders, and can come back.
 */
class SupplierArchiveTest extends TestCase
{
    use RefreshDatabase;

    private CompanyBranch $branch;

    private User $storekeeper;

    protected function setUp(): void
    {
        parent::setUp();
        $this->withoutVite();
        $this->branch = CompanyBranch::factory()->create();
        $this->storekeeper = User::factory()->staff('inventory')->create();
    }

    private function supplier(string $name = 'Karim Traders'): Supplier
    {
        return Supplier::factory()->create(['name' => $name, 'created_by' => $this->storekeeper->id]);
    }

    public function test_archiving_takes_the_supplier_off_the_pick_list_and_keeps_old_orders(): void
    {
        $supplier = $this->supplier();
        $order = PurchaseOrder::query()->create([
            'supplier_id' => $supplier->id,
            'branch_id' => $this->branch->id,
            'status' => PurchaseOrder::StatusDraft,
            'created_by' => $this->storekeeper->id,
        ]);

        $this->actingAs($this->storekeeper)->onPortal('manage')
            ->post("/commands/purchasing/suppliers/{$supplier->id}/archive")
            ->assertSessionHasNoErrors();

        $this->assertSame(__('purchasing.flash_supplier_archived', ['name' => 'Karim Traders']), session('success'));
        $this->assertNotNull($supplier->fresh()->archived_at);
        $this->assertDatabaseHas('purchase_orders', ['id' => $order->id, 'supplier_id' => $supplier->id]);
        $this->assertDatabaseHas('audit_events', ['action' => 'supplier.archived', 'subject' => (string) $supplier->id, 'actor_id' => $this->storekeeper->id]);

        $this->onPortal('manage')->get('/purchasing')->assertInertia(fn (Assert $page) => $page
            ->where('data.purchasing.suppliers', [])
            ->where('data.purchasing.archived_suppliers.0.name', 'Karim Traders')
            ->where('data.purchasing.orders.data.0.supplier_name', 'Karim Traders'));
    }

    public function test_restoring_returns_the_supplier_to_the_pick_list(): void
    {
        $supplier = Supplier::factory()->archived()->create(['name' => 'Rahim Store', 'created_by' => $this->storekeeper->id]);

        $this->actingAs($this->storekeeper)->onPortal('manage')
            ->post("/commands/purchasing/suppliers/{$supplier->id}/restore")
            ->assertSessionHasNoErrors();

        $this->assertSame(__('purchasing.flash_supplier_restored', ['name' => 'Rahim Store']), session('success'));
        $this->assertNull($supplier->fresh()->archived_at);
        $this->assertDatabaseHas('audit_events', ['action' => 'supplier.restored', 'subject' => (string) $supplier->id]);

        $this->onPortal('manage')->get('/purchasing')->assertInertia(fn (Assert $page) => $page
            ->where('data.purchasing.suppliers.0.name', 'Rahim Store')
            ->where('data.purchasing.archived_suppliers', []));
    }

    public function test_a_new_order_cannot_use_an_archived_supplier(): void
    {
        $supplier = Supplier::factory()->archived()->create(['created_by' => $this->storekeeper->id]);
        $product = Product::factory()->create(['active' => true]);

        $this->actingAs($this->storekeeper)->onPortal('manage')->post('/commands/purchasing/orders', [
            'supplier_id' => $supplier->id,
            'branch_id' => $this->branch->id,
            'lines' => [['product_id' => $product->id, 'quantity' => 2]],
        ])->assertSessionHasErrors(['supplier_id' => __('purchasing.err_supplier_archived')]);

        $this->assertDatabaseCount('purchase_orders', 0);
    }

    public function test_archiving_twice_or_restoring_an_active_supplier_says_why_it_cannot(): void
    {
        $archived = Supplier::factory()->archived()->create(['created_by' => $this->storekeeper->id]);
        $active = $this->supplier();

        $this->actingAs($this->storekeeper)->onPortal('manage')
            ->post("/commands/purchasing/suppliers/{$archived->id}/archive")
            ->assertSessionHasErrors(['supplier' => __('purchasing.err_supplier_already_archived')]);
        $this->onPortal('manage')
            ->post("/commands/purchasing/suppliers/{$active->id}/restore")
            ->assertSessionHasErrors(['supplier' => __('purchasing.err_supplier_not_archived')]);

        $this->assertDatabaseMissing('audit_events', ['action' => 'supplier.archived']);
        $this->assertDatabaseMissing('audit_events', ['action' => 'supplier.restored']);
    }

    public function test_staff_who_may_only_add_stock_records_cannot_archive_or_restore(): void
    {
        $canOnlyCreate = User::factory()->staff('employee')->create([
            'profile' => ['permissions' => ['inventory'], 'action_permissions' => ['inventory' => ['view' => true, 'create' => true]]],
        ]);
        $active = $this->supplier();
        $archived = Supplier::factory()->archived()->create(['created_by' => $this->storekeeper->id]);

        $this->actingAs($canOnlyCreate)->onPortal('manage')
            ->post("/commands/purchasing/suppliers/{$active->id}/archive")
            ->assertForbidden();
        $this->onPortal('manage')
            ->post("/commands/purchasing/suppliers/{$archived->id}/restore")
            ->assertForbidden();

        $this->assertNull($active->fresh()->archived_at);
        $this->assertNotNull($archived->fresh()->archived_at);
    }
}
