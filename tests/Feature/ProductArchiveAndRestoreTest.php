<?php

namespace Tests\Feature;

use App\Domain\Catalog\ProductRemoval;
use App\Domain\Orders\OrderService;
use App\Models\PrescriptionTemplate;
use App\Models\Product;
use App\Models\ProductConcern;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Tests\TestCase;

class ProductArchiveAndRestoreTest extends TestCase
{
    use RefreshDatabase;

    public function test_removing_a_product_with_stock_says_it_was_archived_and_why_it_cannot_be_deleted(): void
    {
        $owner = User::factory()->staff()->create();
        $product = Product::factory()->create(['name' => 'Ajmer Lobon', 'stock' => 12]);

        $response = $this->actingAs($owner)->delete('/commands/products/'.$product->id, ['reason' => 'No longer part of the range']);

        $response->assertRedirect('/catalog?product_state=archived');
        $response->assertSessionHas('success', __('office_stock.flash_archived', [
            'name' => 'Ajmer Lobon',
            'reasons' => __('office_stock.reason_stock_units', ['count' => 12]),
        ]));
        $this->assertDatabaseHas('products', ['id' => $product->id, 'active' => false]);
    }

    public function test_removing_an_unused_product_says_it_is_gone_for_good(): void
    {
        $owner = User::factory()->staff()->create();
        $product = Product::factory()->create(['name' => 'Spare Item', 'stock' => 0]);

        $this->actingAs($owner)->delete('/commands/products/'.$product->id, ['reason' => 'Added by mistake'])
            ->assertRedirect('/catalog')
            ->assertSessionHas('success', __('office_stock.flash_deleted', ['name' => 'Spare Item']));

        $this->assertModelMissing($product);
    }

    public function test_someone_without_stock_rights_is_not_told_how_many_units_there_are(): void
    {
        $product = Product::factory()->create(['stock' => 12]);

        $words = app(ProductRemoval::class)->forProduct($product, showUnits: false);

        $this->assertFalse($words['can_delete']);
        $this->assertSame([__('office_stock.reason_stock')], $words['reasons']);
    }

    public function test_every_kind_of_record_gives_a_reason_and_only_that_product_is_blocked(): void
    {
        $blocked = Product::factory()->create(['stock' => 5, 'mrp' => '100.00', 'dealer_price' => '80.00']);
        $free = Product::factory()->create(['stock' => 0]);
        $dealer = User::factory()->create(['role' => 'dealer']);
        app(OrderService::class)->create($dealer, ['items' => [['product_id' => $blocked->id, 'quantity' => 1]], 'idempotency_key' => (string) Str::uuid()]);
        $blocked->concerns()->attach(ProductConcern::factory()->create()->id);
        PrescriptionTemplate::factory()->create(['lines' => [['product_id' => $blocked->id, 'medicine_name' => 'Synthetic', 'quantity' => 1]]]);

        $facts = app(ProductRemoval::class)->forProducts(collect([$blocked, $free]), showUnits: true);

        $this->assertFalse($facts[$blocked->id]['can_delete']);
        $this->assertSame([
            __('office_stock.reason_stock_units', ['count' => 5]),
            __('office_stock.reason_orders', ['count' => 1]),
            __('office_stock.reason_prescriptions'),
            __('office_stock.reason_health_tags'),
        ], $facts[$blocked->id]['reasons']);
        $this->assertTrue($facts[$free->id]['can_delete']);
        $this->assertSame([], $facts[$free->id]['reasons']);
    }

    public function test_the_catalog_keeps_archived_products_apart_and_says_why_each_one_stays(): void
    {
        $owner = User::factory()->create(['role' => 'super_admin', 'status' => 'active']);
        Product::factory()->create(['name' => 'On Sale', 'stock' => 3]);
        Product::factory()->create(['name' => 'Put Away', 'active' => false, 'archived_at' => now(), 'stock' => 7]);

        $main = $this->catalog($owner, '/catalog');
        $this->assertSame(['On Sale'], array_column($main['products'], 'name'));
        $this->assertSame(1, $main['archived_count']);

        $archived = $this->catalog($owner, '/catalog?product_state=archived');
        $this->assertSame(['Put Away'], array_column($archived['products'], 'name'));
        $this->assertFalse($archived['products'][0]['removal']['can_delete']);
        $this->assertSame([__('office_stock.reason_stock_units', ['count' => 7])], $archived['products'][0]['removal']['reasons']);
    }

    public function test_search_counts_the_archived_products_it_would_find(): void
    {
        $owner = User::factory()->create(['role' => 'super_admin', 'status' => 'active']);
        Product::factory()->create(['name' => 'Honey Tonic', 'active' => false, 'archived_at' => now()]);
        Product::factory()->create(['name' => 'Other Hidden', 'active' => false, 'archived_at' => now()]);

        $main = $this->catalog($owner, '/catalog?q=Honey');

        $this->assertSame([], $main['products']);
        $this->assertSame(1, $main['archived_count']);
    }

    public function test_an_archived_product_can_be_restored(): void
    {
        $owner = User::factory()->staff()->create();
        $product = Product::factory()->create(['name' => 'Ajmer Lobon', 'active' => false, 'registry_version' => 4]);

        $this->actingAs($owner)->post('/commands/products/'.$product->id.'/restore')
            ->assertRedirect('/catalog')
            ->assertSessionHas('success', __('office_stock.flash_restored', ['name' => 'Ajmer Lobon']));

        $this->assertDatabaseHas('products', ['id' => $product->id, 'active' => true, 'registry_version' => 5]);
        $this->assertDatabaseHas('audit_events', ['action' => 'product.restored', 'subject' => (string) $product->id]);
    }

    public function test_restoring_a_product_already_on_sale_changes_nothing(): void
    {
        $owner = User::factory()->staff()->create();
        $product = Product::factory()->create(['name' => 'On Sale', 'active' => true, 'registry_version' => 4]);

        $this->actingAs($owner)->post('/commands/products/'.$product->id.'/restore')
            ->assertSessionHas('success', __('office_stock.flash_already_on_sale', ['name' => 'On Sale']));

        $this->assertDatabaseHas('products', ['id' => $product->id, 'registry_version' => 4]);
        $this->assertDatabaseMissing('audit_events', ['action' => 'product.restored', 'subject' => (string) $product->id]);
    }

    public function test_someone_who_cannot_edit_the_catalog_cannot_restore(): void
    {
        $finance = User::factory()->staff()->create(['role' => 'finance_manager']);
        $product = Product::factory()->create(['active' => false]);

        $this->actingAs($finance)->post('/commands/products/'.$product->id.'/restore')->assertForbidden();

        $this->assertDatabaseHas('products', ['id' => $product->id, 'active' => false]);
    }

    public function test_the_catalog_sorts_by_column_and_keeps_the_old_sort_links(): void
    {
        $owner = User::factory()->create(['role' => 'super_admin', 'status' => 'active']);
        Product::factory()->create(['name' => 'Cheap', 'mrp' => '100.00']);
        Product::factory()->create(['name' => 'Dear', 'mrp' => '900.00']);

        $this->assertSame(['Dear', 'Cheap'], array_column($this->catalog($owner, '/catalog?sort=mrp&dir=desc')['products'], 'name'));
        $this->assertSame(['Dear', 'Cheap'], array_column($this->catalog($owner, '/catalog?sort=price_desc')['products'], 'name'));
    }

    /**
     * @return array<string, mixed>
     */
    private function catalog(User $viewer, string $address): array
    {
        return $this->actingAs($viewer)->onPortal('sadmin')->get($address)->assertOk()->original->getData()['page']['props']['data'];
    }
}
