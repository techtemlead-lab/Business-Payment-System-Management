<?php

namespace Tests\Feature;

use App\Models\Product;
use App\Models\ProductGroup;
use App\Models\User;
use App\Support\SiteCustomization;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Testing\TestResponse;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\TestCase;

/**
 * A product's pack size can be changed on the edit page after the product is made,
 * also when the product is one size of a group.
 */
class ProductPackSizeEditTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->withoutVite();
        SiteCustomization::forgetCache();
    }

    public function test_the_pack_size_typed_when_adding_a_product_can_be_changed_later(): void
    {
        $owner = User::factory()->staff()->create();
        $this->actingAs($owner)->post('/commands/products', [
            'name' => 'Honey Syrup', 'sku' => 'TEST-PACK-SIZE', 'category' => 'Test Category', 'unit' => 'bottle', 'strength' => '100 ml',
            'mrp' => '200.00', 'dealer_price' => '140.00', 'active' => true, 'reason' => 'Synthetic product for this test', 'video_source' => 'none',
        ])->assertRedirect()->assertSessionHasNoErrors();
        $product = Product::query()->where('sku', 'TEST-PACK-SIZE')->firstOrFail();

        $this->saveProduct($product, ['strength' => '200 ml'], $owner)->assertRedirect()->assertSessionHasNoErrors();

        $this->assertDatabaseHas('products', ['id' => $product->id, 'strength' => '200 ml', 'name' => 'Honey Syrup']);
        $this->actingAs($owner)->onPortal('sadmin')->get('/products/'.$product->id)->assertOk()
            ->assertInertia(fn (Assert $page) => $page->where('product.strength', '200 ml'));
        $this->assertDatabaseHas('audit_events', ['action' => 'product.updated', 'subject' => (string) $product->id]);
    }

    public function test_the_pack_size_can_be_cleared(): void
    {
        $product = Product::factory()->create(['strength' => '100 ml']);

        $this->saveProduct($product, ['strength' => ''])->assertSessionHasNoErrors();

        $this->assertNull($product->fresh()->strength);
    }

    public function test_the_website_shows_the_changed_pack_size_words(): void
    {
        $product = Product::factory()->create(['name' => 'Honey Syrup', 'pack_size_en' => '100 ml', 'pack_size_bn' => '১০০ মিলি']);
        $this->get('/products/'.$product->id)->assertOk()->assertSee('100 ml');

        $this->saveProduct($product, ['pack_size_en' => '120 ml', 'pack_size_bn' => '১২০ মিলি'])->assertSessionHasNoErrors();

        $this->get('/products/'.$product->id)->assertOk()->assertSee('120 ml')->assertDontSee('100 ml');
        $this->assertDatabaseHas('products', ['id' => $product->id, 'pack_size_en' => '120 ml', 'pack_size_bn' => '১২০ মিলি']);
    }

    public function test_one_size_of_a_group_can_be_given_a_new_pack_size(): void
    {
        [$small, $large] = $this->group(
            $this->sized('Syrup X', '100 ml', '100.00', ['featured' => true, 'featured_position' => 1]),
            $this->sized('Syrup X', '300 ml', '250.00'),
        );
        $owner = User::factory()->staff()->create();
        $this->assertSame('100 ml', SiteCustomization::getFeaturedProducts()[0]['sizes'][0]['labels']['en']);

        $this->saveProduct($small, ['pack_size_en' => '120 ml', 'strength' => '120 ml'], $owner)->assertRedirect()->assertSessionHasNoErrors();

        $this->assertDatabaseHas('products', ['id' => $small->id, 'pack_size_en' => '120 ml', 'strength' => '120 ml', 'product_group_id' => $small->product_group_id]);
        $this->actingAs($owner)->onPortal('sadmin')->get('/products/'.$large->id)->assertOk()->assertInertia(fn (Assert $page) => $page
            ->where('size_group.sizes.0.id', $small->id)
            ->where('size_group.sizes.0.label', '120 ml')
            ->where('size_group.sizes.1.label', '300 ml'));
        $this->assertSame('120 ml', SiteCustomization::getFeaturedProducts()[0]['sizes'][0]['labels']['en'], 'The home page must not keep the old size until its cache runs out.');
    }

    public function test_one_size_of_a_group_cannot_take_the_words_of_another_size(): void
    {
        [$small, $large] = $this->group($this->sized('Syrup X', '100 ml', '100.00'), $this->sized('Syrup X', '300 ml', '250.00'));

        // The refusal names the words that were typed, whichever size the database reads first.
        $this->saveProduct($small, ['pack_size_en' => ' 300ML ', 'name' => 'Renamed Syrup'])
            ->assertSessionHasErrors(['pack_size_en' => __('size_groups.err_size_repeated', ['size' => '300ML'])]);

        $this->assertDatabaseHas('products', ['id' => $small->id, 'pack_size_en' => '100 ml', 'name' => 'Syrup X']);
        $this->assertDatabaseHas('products', ['id' => $large->id, 'pack_size_en' => '300 ml']);
    }

    public function test_the_refusal_names_the_typed_words_whichever_size_was_made_first(): void
    {
        [$first, $second] = $this->group($this->sized('Syrup X', '100 ml', '100.00'), $this->sized('Syrup X', '300 ml', '250.00'));

        $this->saveProduct($second, ['pack_size_en' => '100 ML'])
            ->assertSessionHasErrors(['pack_size_en' => __('size_groups.err_size_repeated', ['size' => '100 ML'])]);

        $this->assertDatabaseHas('products', ['id' => $second->id, 'pack_size_en' => '300 ml']);
        $this->assertDatabaseHas('products', ['id' => $first->id, 'pack_size_en' => '100 ml']);
    }

    public function test_a_repeat_among_the_other_sizes_does_not_hold_up_a_size_that_is_itself_new(): void
    {
        [, , $third] = $this->group(
            $this->sized('Syrup X', '100 ml', '100.00'),
            $this->sized('Syrup X', '100 ml', '250.00'),
            $this->sized('Syrup X', '500 ml', '400.00'),
        );

        $this->saveProduct($third, ['pack_size_en' => '700 ml'])->assertSessionHasNoErrors();

        $this->assertDatabaseHas('products', ['id' => $third->id, 'pack_size_en' => '700 ml']);
    }

    public function test_an_archived_size_does_not_block_the_same_words(): void
    {
        [$small] = $this->group(
            $this->sized('Syrup X', '100 ml', '100.00'),
            $this->sized('Syrup X', '300 ml', '250.00', ['active' => false, 'archived_at' => now()]),
            $this->sized('Syrup X', '500 ml', '400.00'),
        );

        $this->saveProduct($small, ['pack_size_en' => '300 ml'])->assertSessionHasNoErrors();

        $this->assertDatabaseHas('products', ['id' => $small->id, 'pack_size_en' => '300 ml']);
    }

    public function test_a_grouped_product_saves_other_changes_without_a_size_check(): void
    {
        // Two sizes written the same way before this check existed must not lock the product.
        [$first] = $this->group($this->sized('Syrup X', '100 ml', '100.00'), $this->sized('Syrup X', '100 ml', '250.00'));

        $this->saveProduct($first, ['name' => 'Syrup X Plus'])->assertSessionHasNoErrors();

        $this->assertDatabaseHas('products', ['id' => $first->id, 'name' => 'Syrup X Plus']);
    }

    public function test_two_products_that_are_not_sizes_of_each_other_may_share_a_pack_size(): void
    {
        $this->sized('Syrup X', '100 ml', '100.00');
        $other = $this->sized('Tonic Y', '200 ml', '150.00');

        $this->saveProduct($other, ['pack_size_en' => '100 ml'])->assertSessionHasNoErrors();

        $this->assertDatabaseHas('products', ['id' => $other->id, 'pack_size_en' => '100 ml']);
    }

    public function test_staff_without_catalog_rights_cannot_change_the_pack_size(): void
    {
        $product = Product::factory()->create(['strength' => '100 ml', 'pack_size_en' => '100 ml']);
        $stockKeeper = User::factory()->staff('inventory')->create();

        $this->saveProduct($product, ['strength' => '200 ml'], $stockKeeper)->assertForbidden();
        $this->saveProduct($product, ['pack_size_en' => '200 ml'], $stockKeeper)->assertForbidden();

        $this->assertDatabaseHas('products', ['id' => $product->id, 'strength' => '100 ml', 'pack_size_en' => '100 ml']);
    }

    public function test_the_edit_page_and_the_add_form_use_the_same_name_for_the_pack_size(): void
    {
        $this->assertSame('প্যাক সাইজ', __('portal.pe_strength', [], 'bn'));
        $this->assertSame('Pack size', __('portal.pe_strength', [], 'en'));
        foreach (['bn', 'en'] as $language) {
            $this->assertSame(__('portal.pcm_strength', [], $language), __('portal.pe_strength', [], $language));
        }
    }

    /** @param  array<string, mixed>  $attributes */
    private function sized(string $name, string $label, string $mrp, array $attributes = []): Product
    {
        return Product::factory()->create(['name' => $name, 'pack_size_en' => $label, 'mrp' => $mrp, 'dealer_price' => bcmul($mrp, '0.7', 2), ...$attributes]);
    }

    /** @return list<Product> */
    private function group(Product ...$sizes): array
    {
        $group = ProductGroup::factory()->create();
        foreach ($sizes as $size) {
            $size->update(['product_group_id' => $group->id]);
        }

        return array_values($sizes);
    }

    /** @param  array<string, string>  $changes */
    private function saveProduct(Product $product, array $changes, ?User $actor = null): TestResponse
    {
        return $this->actingAs($actor ?? User::factory()->staff()->create())->patch('/commands/products/'.$product->id, [
            'version' => (string) $product->fresh()->registry_version,
            'reason' => 'Synthetic change for this test',
            ...$changes,
        ]);
    }
}
