<?php

namespace Tests\Feature;

use App\Models\CompanyBranch;
use App\Models\Product;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * CR-08 (client, 2026-11-18): manufacturer, product group, expiry date, efficacy and dosage are
 * all optional and stay blank when not given; on the public product page a section with nothing
 * in it is left out completely, heading included.
 */
class OptionalProductFieldsTest extends TestCase
{
    use RefreshDatabase;

    private const OptionalColumns = ['manufacturer_bn', 'manufacturer_en', 'group_bn', 'group_en', 'efficacy_bn', 'efficacy_en', 'dose_bn', 'dose_en', 'expires_on'];

    protected function setUp(): void
    {
        parent::setUp();
        $this->withoutVite();
    }

    public function test_a_product_is_added_with_all_five_fields_left_blank_and_they_stay_blank(): void
    {
        $hub = CompanyBranch::query()->where('code', 'HO-PIRGANJ')->firstOrFail();
        $owner = User::factory()->staff('super_admin')->create(['active_branch_id' => $hub->id]);

        $this->actingAs($owner)->post('/commands/products', [
            'name' => 'Plain Tonic', 'sku' => 'TEST-CR08-PLAIN', 'category' => 'Test Category', 'unit' => 'pack', 'mrp' => '200.00', 'dealer_price' => '140.00',
            'active' => true, 'reason' => 'Synthetic product for this test', 'video_source' => 'none',
            ...array_fill_keys(self::OptionalColumns, ''),
        ])->assertRedirect()->assertSessionHasNoErrors();

        $product = Product::query()->where('sku', 'TEST-CR08-PLAIN')->firstOrFail();
        foreach (self::OptionalColumns as $column) {
            $this->assertNull($product->getAttribute($column), $column.' is saved blank, not as an empty or made-up value.');
        }
    }

    public function test_empty_efficacy_and_dosage_leave_no_heading_on_the_public_page(): void
    {
        // Spaces only count as empty too.
        $product = Product::factory()->create(['description' => 'A plain test description', 'efficacy_en' => '   ', 'efficacy_bn' => null,
            'dose_en' => null, 'dose_bn' => '  ', 'manufacturer_en' => null, 'group_en' => null]);

        $page = $this->withCookie('locale', 'en')->get('/products/'.$product->id)->assertOk()->assertSee('A plain test description');

        $page->assertDontSee(__('product_facts.efficacy'))
            ->assertDontSee(__('site.pd_directions_title'))
            ->assertDontSee('id="usage-tab"', false)
            ->assertDontSee('<th>'.__('product_facts.manufacturer').'</th>', false)
            ->assertDontSee('<th>'.__('product_facts.group').'</th>', false);
    }

    public function test_a_filled_efficacy_shows_with_its_heading(): void
    {
        $product = Product::factory()->create(['efficacy_en' => 'Helps the test digestion', 'dose_en' => 'One spoon after the test meal']);

        $this->withCookie('locale', 'en')->get('/products/'.$product->id)->assertOk()
            ->assertSee(__('product_facts.efficacy'))->assertSee('Helps the test digestion')
            ->assertSee(__('site.pd_directions_title'))->assertSee('One spoon after the test meal');
    }

    public function test_a_product_with_no_category_shows_no_empty_category_line(): void
    {
        $product = Product::factory()->create(['category' => '']);

        $this->withCookie('locale', 'en')->get('/products/'.$product->id)->assertOk()
            ->assertDontSee('<dt>'.__('site.category').'</dt>', false);
    }
}
