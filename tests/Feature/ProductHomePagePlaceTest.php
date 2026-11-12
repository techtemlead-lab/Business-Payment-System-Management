<?php

namespace Tests\Feature;

use App\Models\Product;
use App\Models\User;
use App\Support\SiteCustomization;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Testing\TestResponse;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\TestCase;

/**
 * The plain "Show on home page?" question, and the place a product takes among the home page products.
 */
class ProductHomePagePlaceTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->withoutVite();
        SiteCustomization::forgetCache();
    }

    public function test_the_question_reads_show_on_home_page_with_yes_and_no_in_both_languages(): void
    {
        $this->assertSame('হোম পেজে দেখাবেন?', __('portal.pe_homepage', [], 'bn'));
        $this->assertSame('হ্যাঁ', __('portal.pe_featured', [], 'bn'));
        $this->assertSame('না', __('portal.pe_standard', [], 'bn'));
        $this->assertSame('Show on home page?', __('portal.pe_homepage', [], 'en'));
        $this->assertSame('Yes', __('portal.pe_featured', [], 'en'));
        $this->assertSame('No', __('portal.pe_standard', [], 'en'));

        foreach (['bn', 'en'] as $language) {
            $this->assertSame(__('portal.pe_homepage', [], $language), __('portal.pcm_placement', [], $language), 'The add-product form asks the same question.');
            $this->assertSame(__('portal.pe_featured', [], $language), __('portal.pcm_featured', [], $language));
            $this->assertSame(__('portal.pe_standard', [], $language), __('portal.pcm_standard', [], $language));
        }
    }

    public function test_office_words_about_products_no_longer_say_featured(): void
    {
        $officeKeys = [
            'portal.pe_homepage', 'portal.pe_standard', 'portal.pe_featured', 'portal.pcm_placement', 'portal.pcm_standard', 'portal.pcm_featured',
            'portal.featured_showcase', 'portal.featured_badge', 'portal.pm_save_featured', 'portal.pm_featured_save_hint',
            'portal.sce_featured_products_section', 'portal.sce_featured_products_section_hint', 'portal.sce_featured_mode_auto',
            'portal.sce_featured_mode_curated', 'portal.sce_catalog_featured_heading', 'portal.sce_catalog_featured_empty',
            'stock_lots.filter_featured', 'manual.catalog_orders_t2', 'manual.inventory_branches_s9', 'manual.website_customization_s2',
        ];

        foreach ($officeKeys as $key) {
            $this->assertStringNotContainsString('ফিচার', __($key, [], 'bn'), $key);
            $this->assertStringNotContainsString('বাছাই করা পণ্য', __($key, [], 'bn'), $key);
            $this->assertStringNotContainsStringIgnoringCase('featured', __($key, [], 'en'), $key);
            $this->assertNotSame(__($key, [], 'en'), __($key, [], 'bn'), $key.' must be translated.');
        }
    }

    public function test_the_product_list_filter_asks_about_the_home_page(): void
    {
        $owner = User::factory()->staff()->create();

        $fields = $this->actingAs($owner)->onPortal('sadmin')->get('/catalog')->assertOk()->original->getData()['page']['props']['data']['catalog_filters']['fields'];

        $filter = collect($fields)->firstWhere('key', 'featured');
        $this->assertSame('Shown on home page?', $filter['label']);
        $this->assertSame(['Yes', 'No'], array_column($filter['options'], 'label'));
    }

    public function test_the_manual_tells_where_the_order_and_the_pack_size_are_in_both_languages(): void
    {
        foreach (['bn', 'en'] as $language) {
            $manual = require lang_path($language.'/manual.php');

            $this->assertArrayHasKey('website_editor_s6', $manual);
            $this->assertArrayHasKey('product_facts_s5', $manual);
            $this->assertStringContainsString(__('portal.sce_home_order_save', [], $language), $manual['website_editor_s6']);
            $this->assertStringContainsString(__('portal.pe_strength', [], $language), $manual['product_facts_s5']);
        }
    }

    public function test_typing_a_place_moves_the_product_there_and_the_home_page_follows(): void
    {
        $first = $this->shown('Alpha Tonic', 1);
        $second = $this->shown('Beta Tonic', 2);
        $third = $this->shown('Gamma Tonic', 3);
        $this->get('/')->assertOk()->assertSeeInOrder(['Alpha Tonic', 'Beta Tonic', 'Gamma Tonic']);

        $this->saveProduct($third, ['featured' => '1', 'home_page_place' => '1'])->assertRedirect()->assertSessionHasNoErrors();

        $this->assertSame([1 => $third->id, 2 => $first->id, 3 => $second->id], $this->placesById());
        $this->get('/')->assertOk()->assertSeeInOrder(['Gamma Tonic', 'Alpha Tonic', 'Beta Tonic']);
    }

    public function test_a_place_past_the_end_means_last(): void
    {
        $first = $this->shown('Alpha Tonic', 1);
        $second = $this->shown('Beta Tonic', 2);
        $third = $this->shown('Gamma Tonic', 3);

        $this->saveProduct($first, ['featured' => '1', 'home_page_place' => '40'])->assertSessionHasNoErrors();

        $this->assertSame([1 => $second->id, 2 => $third->id, 3 => $first->id], $this->placesById());
    }

    public function test_a_product_newly_marked_yes_takes_the_place_typed(): void
    {
        $first = $this->shown('Alpha Tonic', 1);
        $second = $this->shown('Beta Tonic', 2);
        $new = Product::factory()->create(['name' => 'Gamma Tonic']);

        $this->saveProduct($new, ['featured' => '1', 'home_page_place' => '2'])->assertSessionHasNoErrors();

        $this->assertSame([1 => $first->id, 2 => $new->id, 3 => $second->id], $this->placesById());
    }

    public function test_a_product_can_be_given_a_place_while_it_is_added(): void
    {
        $first = $this->shown('Alpha Tonic', 1);
        $second = $this->shown('Beta Tonic', 2);

        $this->actingAs(User::factory()->staff()->create())->post('/commands/products', [
            'name' => 'New Tonic', 'sku' => 'TEST-HOME-PLACE', 'category' => 'Test Category', 'unit' => 'pack', 'mrp' => '200.00', 'dealer_price' => '140.00',
            'active' => true, 'featured' => '1', 'home_page_place' => '1', 'reason' => 'Synthetic product for this test', 'video_source' => 'none',
        ])->assertRedirect()->assertSessionHasNoErrors();

        $added = Product::query()->where('sku', 'TEST-HOME-PLACE')->firstOrFail();
        $this->assertSame([1 => $added->id, 2 => $first->id, 3 => $second->id], $this->placesById());
    }

    public function test_marking_yes_without_a_place_puts_the_product_last(): void
    {
        $first = $this->shown('Alpha Tonic', 1);
        $new = Product::factory()->create(['name' => 'Beta Tonic']);

        $this->saveProduct($new, ['featured' => '1'])->assertSessionHasNoErrors();

        $this->assertSame([1 => $first->id, 2 => $new->id], $this->placesById());
    }

    public function test_a_place_is_ignored_when_the_answer_is_no(): void
    {
        $first = $this->shown('Alpha Tonic', 1);
        $hidden = Product::factory()->create(['name' => 'Beta Tonic']);

        $this->saveProduct($hidden, ['featured' => '0', 'home_page_place' => '1'])->assertSessionHasNoErrors();

        $this->assertSame([1 => $first->id], $this->placesById());
        $this->assertDatabaseHas('products', ['id' => $hidden->id, 'featured' => false, 'featured_position' => null]);
    }

    public function test_gaps_left_by_removed_products_close_when_a_place_is_typed(): void
    {
        $first = $this->shown('Alpha Tonic', 2);
        $second = $this->shown('Beta Tonic', 5);
        $third = $this->shown('Gamma Tonic', 9);

        $this->saveProduct($second, ['featured' => '1', 'home_page_place' => '3'])->assertSessionHasNoErrors();

        $this->assertSame([1 => $first->id, 2 => $third->id, 3 => $second->id], $this->placesById());
    }

    public function test_moving_one_product_does_not_make_other_open_editors_stale(): void
    {
        $first = $this->shown('Alpha Tonic', 1);
        $second = $this->shown('Beta Tonic', 2);
        $versionBefore = (int) $first->registry_version;

        $this->saveProduct($second, ['featured' => '1', 'home_page_place' => '1'])->assertSessionHasNoErrors();

        $this->assertSame($versionBefore, (int) $first->fresh()->registry_version);
        $this->assertSame(2, $first->fresh()->featured_position);
    }

    public function test_a_place_must_be_a_whole_number_from_one(): void
    {
        $product = $this->shown('Alpha Tonic', 1);

        $this->saveProduct($product, ['featured' => '1', 'home_page_place' => '0'])->assertSessionHasErrors('home_page_place');
        $this->saveProduct($product, ['featured' => '1', 'home_page_place' => 'first'])->assertSessionHasErrors('home_page_place');
    }

    public function test_staff_without_catalog_rights_cannot_set_a_place(): void
    {
        $first = $this->shown('Alpha Tonic', 1);
        $second = $this->shown('Beta Tonic', 2);

        $this->saveProduct($second, ['home_page_place' => '1'], User::factory()->staff('inventory')->create())->assertForbidden();

        $this->assertSame([1 => $first->id, 2 => $second->id], $this->placesById());
    }

    public function test_staff_who_can_only_view_the_catalog_cannot_set_a_place(): void
    {
        $first = $this->shown('Alpha Tonic', 1);
        $second = $this->shown('Beta Tonic', 2);
        $viewer = User::factory()->staff('employee')->create(['profile' => ['permissions' => ['catalog'], 'action_permissions' => ['catalog' => ['view' => true]]]]);

        $this->saveProduct($second, ['home_page_place' => '1'], $viewer)->assertForbidden();

        $this->assertSame([1 => $first->id, 2 => $second->id], $this->placesById());
    }

    public function test_the_product_page_gets_the_place_and_the_number_of_products_shown(): void
    {
        $this->shown('Alpha Tonic', 1);
        $second = $this->shown('Beta Tonic', 2);
        $hidden = Product::factory()->create(['name' => 'Gamma Tonic']);
        $owner = User::factory()->staff()->create();

        $this->actingAs($owner)->onPortal('sadmin')->get('/products/'.$second->id)->assertOk()
            ->assertInertia(fn (Assert $page) => $page->where('home_page.place', 2)->where('home_page.count', 2));
        $this->actingAs($owner)->onPortal('sadmin')->get('/products/'.$hidden->id)->assertOk()
            ->assertInertia(fn (Assert $page) => $page->where('home_page.place', null)->where('home_page.count', 2));
    }

    public function test_the_website_screen_gets_the_numbered_list_in_order(): void
    {
        $this->shown('Alpha Tonic', 2);
        $this->shown('Beta Tonic', 1);
        Product::factory()->create(['name' => 'Ordinary Tonic']);

        $this->actingAs(User::factory()->staff()->create())->onPortal('sadmin')->get('/website')->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->has('data.featured_order_products', 2)
                ->where('data.featured_order_products.0.name', 'Beta Tonic')
                ->where('data.featured_order_products.1.name', 'Alpha Tonic'));
    }

    private function shown(string $name, int $place): Product
    {
        return Product::factory()->create(['name' => $name, 'featured' => true, 'featured_position' => $place]);
    }

    /** @return array<int, int> place => product id, for the products shown on the home page */
    private function placesById(): array
    {
        return Product::query()->where('featured', true)->orderBy('featured_position')->pluck('id', 'featured_position')->map(fn (mixed $id): int => (int) $id)->all();
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
