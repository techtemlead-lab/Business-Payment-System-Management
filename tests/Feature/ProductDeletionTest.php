<?php

namespace Tests\Feature;

use App\Domain\Inventory\StockCountService;
use App\Domain\Orders\DatedSchemes;
use App\Models\CompanyBranch;
use App\Models\DatedScheme;
use App\Models\PrescriptionTemplate;
use App\Models\Product;
use App\Models\SiteFeedback;
use App\Models\StockCountLine;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Schema;
use Illuminate\Testing\TestResponse;
use Tests\TestCase;

class ProductDeletionTest extends TestCase
{
    use RefreshDatabase;

    public function test_product_found_only_in_a_stock_count_is_archived_and_the_count_is_kept(): void
    {
        $owner = User::factory()->staff()->create();
        $product = Product::factory()->create(['stock' => 0, 'featured' => true]);
        $session = app(StockCountService::class)->start($owner, CompanyBranch::factory()->create()->id);
        $line = StockCountLine::query()->where('stock_count_session_id', $session->id)->where('product_id', $product->id)->firstOrFail();

        $response = $this->deleteProduct($owner, $product);

        $this->assertArchived($response, $product);
        $this->assertModelExists($line);
    }

    public function test_product_with_no_references_is_deleted_for_good(): void
    {
        $owner = User::factory()->staff()->create();
        $countedProduct = Product::factory()->create(['stock' => 0]);
        app(StockCountService::class)->start($owner, CompanyBranch::factory()->create()->id);
        $unusedProduct = Product::factory()->create(['stock' => 0]);

        $response = $this->deleteProduct($owner, $unusedProduct);

        $response->assertRedirect('/catalog')->assertSessionHas('success', __('portal.flash_product_deleted'));
        $this->assertModelMissing($unusedProduct);
        $this->assertModelExists($countedProduct);
        $this->assertDatabaseHas('audit_events', ['action' => 'product.deleted', 'subject' => (string) $unusedProduct->id]);
    }

    public function test_product_given_free_by_a_scheme_is_archived_and_the_scheme_keeps_it(): void
    {
        $owner = User::factory()->staff()->create();
        $product = Product::factory()->create(['stock' => 0]);
        $scheme = $this->addScheme($owner, [Product::factory()->create()->id], ['benefit_type' => 'free_goods', 'free_product_id' => $product->id, 'free_quantity' => 1]);

        $response = $this->deleteProduct($owner, $product);

        $this->assertArchived($response, $product);
        $this->assertDatabaseHas('dated_schemes', ['id' => $scheme->id, 'free_product_id' => $product->id]);
    }

    public function test_product_named_in_a_scheme_is_archived(): void
    {
        $owner = User::factory()->staff()->create();
        $product = Product::factory()->create(['stock' => 0]);
        $this->addScheme($owner, [Product::factory()->create()->id, $product->id]);

        $response = $this->deleteProduct($owner, $product);

        $this->assertArchived($response, $product);
    }

    public function test_product_saved_in_a_prescription_template_is_archived(): void
    {
        $owner = User::factory()->staff()->create();
        $product = Product::factory()->create(['stock' => 0]);
        PrescriptionTemplate::factory()->create(['lines' => [
            ['product_id' => null, 'medicine_name' => 'Typed medicine', 'quantity' => 1],
            ['product_id' => $product->id, 'medicine_name' => 'Synthetic product', 'quantity' => 2],
        ]]);

        $response = $this->deleteProduct($owner, $product);

        $this->assertArchived($response, $product);
    }

    public function test_product_with_a_customer_review_is_archived_and_the_review_is_kept(): void
    {
        $owner = User::factory()->staff()->create();
        $product = Product::factory()->create(['stock' => 0]);
        $review = SiteFeedback::factory()->create(['product_id' => $product->id]);

        $response = $this->deleteProduct($owner, $product);

        $this->assertArchived($response, $product);
        $this->assertModelExists($review);
    }

    public function test_every_foreign_key_to_products_counts_as_a_reference(): void
    {
        $foreignKeys = collect(Schema::getTables())
            ->flatMap(fn (array $table): array => collect(Schema::getForeignKeys($table['name']))
                ->where('foreign_table', 'products')
                ->map(fn (array $foreignKey): string => $table['name'].'.'.implode(',', $foreignKey['columns']))
                ->all())
            ->sort()
            ->values()
            ->all();

        $references = collect(Product::ReferencingColumns)
            ->map(fn (string $column, string $table): string => $table.'.'.$column)
            ->sort()
            ->values()
            ->all();

        $this->assertSame($foreignKeys, $references, 'A foreign key to products is missing from Product::ReferencingColumns, so deleting such a product would crash or lose that row.');
    }

    private function deleteProduct(User $user, Product $product): TestResponse
    {
        return $this->actingAs($user)->delete('/commands/products/'.$product->id, ['reason' => 'No longer part of the range']);
    }

    private function assertArchived(TestResponse $response, Product $product): void
    {
        $response->assertRedirect('/catalog')->assertSessionHas('success', __('portal.flash_product_archived'));
        $this->assertDatabaseHas('products', ['id' => $product->id, 'active' => false, 'featured' => false, 'registry_version' => 1]);
        $this->assertDatabaseHas('audit_events', ['action' => 'product.archived', 'subject' => (string) $product->id]);
    }

    /**
     * @param  list<int>  $productIds
     * @param  array<string, int|string>  $benefit
     */
    private function addScheme(User $owner, array $productIds, array $benefit = ['benefit_type' => 'discount']): DatedScheme
    {
        return app(DatedSchemes::class)->add($owner, [
            'title' => 'Winter offer',
            'starts_on' => '2026-11-01',
            'ends_on' => '2026-11-30',
            'product_ids' => $productIds,
            'min_quantity' => 1,
            'benefit_text' => 'Extra value on this order',
            ...$benefit,
        ]);
    }
}
