<?php

namespace Tests\Feature;

use App\Models\Product;
use App\Models\User;
use App\Support\SiteCustomization;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Schema;
use Illuminate\Testing\TestResponse;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\TestCase;

class FeaturedProductsOrderTest extends TestCase
{
    use RefreshDatabase;

    private const Migration = '2026_11_09_051603_add_featured_position_to_products_table.php';

    protected function setUp(): void
    {
        parent::setUp();
        $this->withoutVite();
        SiteCustomization::forgetCache();
    }

    public function test_unticked_product_leaves_the_home_page_at_once(): void
    {
        $unticked = $this->featured('Unticked Tonic', 1);
        $this->featured('Kept Tonic', 2);
        $this->get('/')->assertOk()->assertSee('Unticked Tonic');

        $this->saveProduct($unticked, ['featured' => '0'])->assertRedirect()->assertSessionHasNoErrors();

        $this->get('/')->assertOk()->assertDontSee('Unticked Tonic')->assertSee('Kept Tonic');
        $this->assertDatabaseHas('products', ['id' => $unticked->id, 'featured' => false, 'featured_position' => null]);
    }

    public function test_products_not_featured_do_not_fill_the_home_page(): void
    {
        $this->featured('Chosen Tonic', 1);
        Product::factory()->create(['name' => 'Ordinary Tonic']);

        $this->get('/')->assertOk()->assertSee('Chosen Tonic')->assertDontSee('Ordinary Tonic');
    }

    public function test_home_page_section_disappears_when_nothing_is_featured(): void
    {
        Product::factory()->create(['name' => 'Ordinary Tonic']);

        $this->get('/')->assertOk()
            ->assertDontSee('id="featured-products"', false)
            ->assertDontSee('Ordinary Tonic');
    }

    public function test_home_page_shows_more_than_twenty_featured_products(): void
    {
        foreach (range(1, 21) as $position) {
            $this->featured('Tonic '.$position, $position);
        }

        $this->assertCount(21, SiteCustomization::getFeaturedProducts());
    }

    public function test_home_page_puts_products_without_a_number_last_then_by_name(): void
    {
        Product::factory()->create(['name' => 'Alpha Tonic', 'featured' => true]);
        Product::factory()->create(['name' => 'Beta Tonic', 'featured' => true]);
        $this->featured('Zulu Tonic', 1);

        $this->assertSame(['Zulu Tonic', 'Alpha Tonic', 'Beta Tonic'], array_column(SiteCustomization::getFeaturedProducts(), 'name'));
    }

    public function test_saved_order_is_the_home_page_order(): void
    {
        $first = $this->featured('Alpha Tonic', 1);
        $second = $this->featured('Beta Tonic', 2);
        $added = Product::factory()->create(['name' => 'Gamma Tonic']);
        $removed = $this->featured('Delta Tonic', 3);
        $this->get('/')->assertOk()->assertSee('Delta Tonic');

        $this->saveOrder(User::factory()->staff()->create(), [$added->id, $second->id, $first->id])
            ->assertRedirect()
            ->assertSessionHasNoErrors()
            ->assertSessionHas('success', __('portal.flash_featured_order_saved'));

        $this->get('/')->assertOk()->assertSeeInOrder(['Gamma Tonic', 'Beta Tonic', 'Alpha Tonic'])->assertDontSee('Delta Tonic');
        $this->assertDatabaseHas('products', ['id' => $added->id, 'featured' => true, 'featured_position' => 1]);
        $this->assertDatabaseHas('products', ['id' => $first->id, 'featured' => true, 'featured_position' => 3]);
        $this->assertDatabaseHas('products', ['id' => $removed->id, 'featured' => false, 'featured_position' => null]);
        $this->assertDatabaseHas('audit_events', ['action' => 'product.featured_order_saved', 'subject' => 'home_page']);
    }

    public function test_saving_an_empty_list_clears_the_home_page(): void
    {
        $product = $this->featured('Alpha Tonic', 1);

        $this->saveOrder(User::factory()->staff()->create(), [])->assertSessionHasNoErrors();

        $this->assertDatabaseHas('products', ['id' => $product->id, 'featured' => false, 'featured_position' => null]);
        $this->get('/')->assertOk()->assertDontSee('id="featured-products"', false);
    }

    public function test_only_a_change_of_tick_makes_an_open_product_editor_reload(): void
    {
        $moved = $this->featured('Alpha Tonic', 2);
        $removed = $this->featured('Beta Tonic', 1);

        $this->saveOrder(User::factory()->staff()->create(), [$moved->id])->assertSessionHasNoErrors();

        $this->assertSame((int) $moved->registry_version, $moved->fresh()->registry_version);
        $this->assertSame((int) $removed->registry_version + 1, $removed->fresh()->registry_version);
    }

    public function test_staff_with_catalog_rights_can_save_the_list(): void
    {
        $product = Product::factory()->create(['name' => 'Alpha Tonic']);

        $this->saveOrder(User::factory()->staff('editor')->create(), [$product->id])->assertRedirect()->assertSessionHasNoErrors();

        $this->assertDatabaseHas('products', ['id' => $product->id, 'featured' => true, 'featured_position' => 1]);
    }

    public function test_staff_without_catalog_rights_are_refused(): void
    {
        $product = $this->featured('Alpha Tonic', 1);

        $this->saveOrder(User::factory()->staff('inventory')->create(), [])->assertForbidden();

        $this->assertDatabaseHas('products', ['id' => $product->id, 'featured' => true, 'featured_position' => 1]);
    }

    public function test_staff_who_can_only_view_the_catalog_are_refused(): void
    {
        $product = $this->featured('Alpha Tonic', 1);
        $viewer = User::factory()->staff('employee')->create(['profile' => ['permissions' => ['catalog'], 'action_permissions' => ['catalog' => ['view' => true]]]]);

        $this->saveOrder($viewer, [])->assertForbidden();

        $this->assertDatabaseHas('products', ['id' => $product->id, 'featured' => true, 'featured_position' => 1]);
    }

    public function test_dealer_is_refused(): void
    {
        $product = $this->featured('Alpha Tonic', 1);

        $this->saveOrder(User::factory()->create(['role' => 'dealer']), [])->assertForbidden();

        $this->assertDatabaseHas('products', ['id' => $product->id, 'featured' => true]);
    }

    public function test_archived_product_cannot_be_put_on_the_list(): void
    {
        $listed = $this->featured('Alpha Tonic', 1);
        $archived = Product::factory()->create(['name' => 'Archived Tonic', 'active' => false]);

        $this->saveOrder(User::factory()->staff()->create(), [$archived->id])
            ->assertSessionHasErrors(['product_ids' => __('portal.err_featured_order_unavailable')]);

        $this->assertDatabaseHas('products', ['id' => $listed->id, 'featured' => true, 'featured_position' => 1]);
        $this->assertDatabaseHas('products', ['id' => $archived->id, 'featured' => false]);
    }

    public function test_the_same_product_cannot_be_listed_twice(): void
    {
        $product = $this->featured('Alpha Tonic', 1);

        $this->saveOrder(User::factory()->staff()->create(), [$product->id, $product->id])
            ->assertSessionHasErrors('product_ids.0');
    }

    public function test_ticking_featured_in_the_editor_puts_the_product_last(): void
    {
        $this->featured('Alpha Tonic', 1);
        $this->featured('Beta Tonic', 4);
        $ticked = Product::factory()->create(['name' => 'Gamma Tonic']);

        $this->saveProduct($ticked, ['featured' => '1'])->assertRedirect()->assertSessionHasNoErrors();

        $this->assertDatabaseHas('products', ['id' => $ticked->id, 'featured' => true, 'featured_position' => 5]);
    }

    public function test_ticking_featured_when_adding_a_product_puts_it_last(): void
    {
        $this->featured('Alpha Tonic', 3);

        $this->actingAs(User::factory()->staff()->create())->post('/commands/products', [
            'name' => 'New Tonic', 'sku' => 'TEST-FEATURED', 'category' => 'Test Category', 'unit' => 'pack', 'mrp' => '200.00', 'dealer_price' => '140.00',
            'active' => true, 'featured' => '1', 'reason' => 'Synthetic product for this test', 'video_source' => 'none',
        ])->assertRedirect()->assertSessionHasNoErrors();

        $this->assertDatabaseHas('products', ['sku' => 'TEST-FEATURED', 'featured' => true, 'featured_position' => 4]);
    }

    public function test_saving_a_featured_product_again_keeps_its_place(): void
    {
        $product = $this->featured('Alpha Tonic', 1);
        $this->featured('Beta Tonic', 2);

        $this->saveProduct($product, ['featured' => '1'])->assertRedirect()->assertSessionHasNoErrors();

        $this->assertDatabaseHas('products', ['id' => $product->id, 'featured_position' => 1]);
    }

    public function test_archiving_takes_the_product_off_the_home_page_at_once(): void
    {
        $product = $this->featured('Alpha Tonic', 1);
        $this->get('/')->assertOk()->assertSee('Alpha Tonic');

        $this->actingAs(User::factory()->staff()->create())
            ->delete('/commands/products/'.$product->id, ['reason' => 'No longer part of the range'])
            ->assertRedirect('/catalog?product_state=archived');

        $this->assertDatabaseHas('products', ['id' => $product->id, 'active' => false, 'featured' => false, 'featured_position' => null]);
        $this->get('/')->assertOk()->assertDontSee('Alpha Tonic');
    }

    public function test_featured_tab_receives_the_list_in_order(): void
    {
        $this->featured('Alpha Tonic', 2);
        $this->featured('Beta Tonic', 1);
        Product::factory()->create(['name' => 'Ordinary Tonic']);

        $this->actingAs(User::factory()->staff()->create())
            ->onPortal('sadmin')
            ->get('/catalog?tab=featured')
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->has('data.featured_order_products', 2)
                ->where('data.featured_order_products.0.name', 'Beta Tonic')
                ->where('data.featured_order_products.1.name', 'Alpha Tonic'));
    }

    public function test_migration_marks_the_first_twenty_when_nothing_is_featured(): void
    {
        foreach (range(1, 22) as $number) {
            Product::factory()->create(['name' => sprintf('Tonic %02d', $number)]);
        }
        $inactive = Product::factory()->create(['name' => 'A Hidden Tonic', 'active' => false]);

        $this->runMigrationAgain();

        $this->assertDatabaseHas('products', ['name' => 'Tonic 01', 'featured' => true, 'featured_position' => 1]);
        $this->assertDatabaseHas('products', ['name' => 'Tonic 20', 'featured' => true, 'featured_position' => 20]);
        $this->assertDatabaseHas('products', ['name' => 'Tonic 21', 'featured' => false, 'featured_position' => null]);
        $this->assertDatabaseHas('products', ['id' => $inactive->id, 'featured' => false, 'featured_position' => null]);
    }

    public function test_migration_numbers_products_already_featured_by_name_and_marks_no_others(): void
    {
        Product::factory()->create(['name' => 'Beta Tonic', 'featured' => true]);
        Product::factory()->create(['name' => 'Alpha Tonic', 'featured' => true]);
        Product::factory()->create(['name' => 'Aardvark Tonic']);

        $this->runMigrationAgain();

        $this->assertDatabaseHas('products', ['name' => 'Alpha Tonic', 'featured_position' => 1]);
        $this->assertDatabaseHas('products', ['name' => 'Beta Tonic', 'featured_position' => 2]);
        $this->assertDatabaseHas('products', ['name' => 'Aardvark Tonic', 'featured' => false, 'featured_position' => null]);
    }

    public function test_migration_can_be_reversed(): void
    {
        (require database_path('migrations/'.self::Migration))->down();

        $this->assertFalse(Schema::hasColumn('products', 'featured_position'));
    }

    private function featured(string $name, int $position): Product
    {
        return Product::factory()->create(['name' => $name, 'featured' => true, 'featured_position' => $position]);
    }

    /** @param  list<int>  $productIds */
    private function saveOrder(User $user, array $productIds): TestResponse
    {
        return $this->actingAs($user)->from('/catalog')->post('/commands/products/featured-order', ['product_ids' => $productIds]);
    }

    /** @param  array<string, string>  $changes */
    private function saveProduct(Product $product, array $changes): TestResponse
    {
        return $this->actingAs(User::factory()->staff()->create())->patch('/commands/products/'.$product->id, [
            'version' => (string) $product->fresh()->registry_version,
            'reason' => 'Synthetic change for this test',
            ...$changes,
        ]);
    }

    private function runMigrationAgain(): void
    {
        $migration = require database_path('migrations/'.self::Migration);
        $migration->down();
        $migration->up();
    }
}
