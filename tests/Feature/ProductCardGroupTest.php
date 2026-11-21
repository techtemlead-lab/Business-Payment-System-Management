<?php

namespace Tests\Feature;

use App\Models\Product;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\TestCase;

/** The product group (for example "Paracetamol group") sits under the product name on every card and order row. */
class ProductCardGroupTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->withoutVite();
    }

    public function test_the_website_product_list_shows_the_group_under_the_name_in_the_visitors_language(): void
    {
        Product::factory()->create(['name' => 'Napa Test', 'active' => true, 'group_bn' => 'প্যারাসিটামল গ্রুপ', 'group_en' => 'Paracetamol group']);

        $this->withCookie('locale', 'en')->get('/products')->assertOk()
            ->assertSee('<p class="ref-card-group">Paracetamol group</p>', false)
            ->assertDontSee('প্যারাসিটামল গ্রুপ');
        $this->withCookie('locale', 'bn')->get('/products')->assertOk()
            ->assertSee('<p class="ref-card-group">প্যারাসিটামল গ্রুপ</p>', false)
            ->assertDontSee('Paracetamol group');
    }

    public function test_a_group_written_in_one_language_only_is_shown_in_the_other_language_too(): void
    {
        Product::factory()->create(['name' => 'Napa Test', 'active' => true, 'group_bn' => null, 'group_en' => 'Paracetamol group']);

        $this->withCookie('locale', 'bn')->get('/products')->assertOk()
            ->assertSee('<p class="ref-card-group">Paracetamol group</p>', false);
    }

    public function test_a_product_without_a_group_shows_no_group_line_on_the_website(): void
    {
        Product::factory()->create(['name' => 'Napa Test', 'active' => true, 'group_bn' => '  ', 'group_en' => null]);

        $this->get('/products')->assertOk()->assertSee('Napa Test')->assertDontSee('ref-card-group');
    }

    public function test_the_home_page_cards_show_the_group(): void
    {
        Product::factory()->create(['name' => 'Napa Test', 'active' => true, 'featured' => true, 'group_en' => 'Paracetamol group']);

        $this->withCookie('locale', 'en')->get('/')->assertOk()
            ->assertSee('<p class="ref-card-group">Paracetamol group</p>', false);
    }

    public function test_partner_products_and_order_pages_are_given_the_group_in_the_partners_language(): void
    {
        $withGroup = Product::factory()->create(['name' => 'Napa Test', 'active' => true, 'group_bn' => 'প্যারাসিটামল গ্রুপ', 'group_en' => 'Paracetamol group']);
        $without = Product::factory()->create(['name' => 'Zinc Plain', 'active' => true, 'group_bn' => null, 'group_en' => null]);
        $dealer = User::factory()->dealer()->create(['status' => 'active']);

        foreach (['/catalog', '/purchase'] as $path) {
            foreach (['en' => 'Paracetamol group', 'bn' => 'প্যারাসিটামল গ্রুপ'] as $locale => $expected) {
                $this->actingAs($dealer)->onPortal('dealer')->withCookie('locale', $locale)->get($path)->assertOk()
                    ->assertInertia(fn (Assert $page) => $page->where('data.products', function ($products) use ($withGroup, $without, $expected): bool {
                        $rows = collect($products)->keyBy('id');

                        return $rows[$withGroup->id]['group'] === $expected && $rows[$without->id]['group'] === null;
                    }));
            }
        }
    }
}
