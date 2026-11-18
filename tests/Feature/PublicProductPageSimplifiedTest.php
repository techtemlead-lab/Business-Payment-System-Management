<?php

namespace Tests\Feature;

use App\Models\Product;
use App\Support\LocalDigits;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Public product page (LC-02, PW-05, PW-06 and the simplify lines): the registration number is
 * easy to find, each fact appears once, prices use lakh grouping, three share controls, and the
 * review form is folded until someone wants it.
 */
class PublicProductPageSimplifiedTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->withoutVite();
    }

    public function test_amounts_use_lakh_and_crore_grouping_in_the_page_digits(): void
    {
        $this->assertSame('2,50,000.00', LocalDigits::money(250000, 2, 'en'));
        $this->assertSame('1,23,45,678.90', LocalDigits::money(12345678.9, 2, 'en'));
        $this->assertSame('99,999.00', LocalDigits::money('99999', 2, 'en'));
        $this->assertSame('350', LocalDigits::money(350, 0, 'en'));
        $this->assertSame('-2,50,000.00', LocalDigits::money(-250000, 2, 'en'));
        $this->assertSame('২,৫০,০০০', LocalDigits::money(250000, 0, 'bn'));
    }

    public function test_the_registration_number_sits_in_the_top_block_once(): void
    {
        $product = Product::factory()->create(['mrp' => '250000.00', 'registration_number' => 'DGDA-TEST-7731']);

        $page = $this->withCookie('locale', 'en')->get('/products/'.$product->id)->assertOk();

        $page->assertSeeInOrder(['ref-detail-facts', __('product_facts.registration_number'), 'DGDA-TEST-7731', 'data-share-panel'], false);
        $this->assertSame(1, substr_count($page->getContent(), 'DGDA-TEST-7731'), 'The number is shown once, not again in the specification table.');
        $page->assertSee('৳ 2,50,000.00')->assertDontSee('250,000.00');
    }

    public function test_code_category_and_unit_are_not_repeated_in_the_specification_table(): void
    {
        $product = Product::factory()->create(['sku' => 'ONCE-CODE-42']);

        $html = $this->withCookie('locale', 'en')->get('/products/'.$product->id)->assertOk()->getContent();

        $this->assertSame(1, substr_count($html, 'ONCE-CODE-42'));
    }

    public function test_the_share_bar_has_three_controls(): void
    {
        $product = Product::factory()->create();

        $html = $this->withCookie('locale', 'en')->get('/products/'.$product->id)->assertOk()->getContent();
        preg_match('#<div class="ref-share-bar".*?</div>#s', $html, $bar);

        $this->assertSame(3, substr_count($bar[0], '<button') + substr_count($bar[0], '<a '));
    }

    public function test_the_review_form_is_folded_while_there_are_no_reviews(): void
    {
        $product = Product::factory()->create();

        $html = $this->withCookie('locale', 'en')->get('/products/'.$product->id)->assertOk()->getContent();

        $this->assertMatchesRegularExpression('#<details class="ref-review-fold"\s*>#', $html);
        $this->assertStringContainsString('name="message"', $html, 'The form is still on the page, just folded.');
    }
}
