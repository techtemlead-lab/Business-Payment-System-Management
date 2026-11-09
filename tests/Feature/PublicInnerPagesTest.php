<?php

namespace Tests\Feature;

use App\Models\Product;
use App\Support\SiteCustomization;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

/**
 * Every inner public page opens the same way, and long policy text stays easy to move around in.
 */
class PublicInnerPagesTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->withoutVite();
        SiteCustomization::forgetCache();
    }

    /**
     * @return array<string, array{string}>
     */
    public static function innerPages(): array
    {
        $paths = ['/products', '/dealers', '/about', '/contact', '/partnership', '/services', '/quality', '/faq', '/privacy', '/terms', '/notice', '/feedback', '/batch-check', '/prescribers', '/verify-dealer', '/grievance', '/refund-policy', '/delivery-policy', '/code-of-conduct'];

        return array_combine($paths, array_map(fn (string $path): array => [$path], $paths));
    }

    #[DataProvider('innerPages')]
    public function test_every_inner_page_opens_with_one_title_plate(string $path): void
    {
        $html = (string) $this->get($path)->assertOk()->getContent();

        $this->assertSame(1, substr_count($html, 'class="ref-page-plate"'), 'One title plate');
        $this->assertSame(1, substr_count($html, '<h1'), 'One main heading');
    }

    public function test_a_long_policy_lists_its_sections_and_each_one_can_be_jumped_to(): void
    {
        $html = (string) $this->get('/privacy')->assertOk()->assertSee(__('site.on_this_page'))->getContent();

        $this->assertStringContainsString('href="#part-1"', $html);
        $this->assertStringContainsString('id="part-1"', $html);
    }

    public function test_a_bangla_section_number_with_a_zero_is_still_a_heading(): void
    {
        $html = view('reference.partials.trust-prose', ['text' => "১০. পরিবর্তন\nনীতিমালা বদলাতে পারে।"])->render();

        $this->assertStringContainsString('<h2 class="ref-legal-heading">১০. পরিবর্তন</h2>', $html);
    }

    public function test_a_short_text_gets_no_list_of_sections(): void
    {
        $html = view('reference.partials.trust-prose', ['text' => "১. প্রথম\nলেখা\n\n২. দ্বিতীয়\nলেখা", 'asPage' => true])->render();

        $this->assertStringContainsString('ref-legal-body-card', $html);
        $this->assertStringNotContainsString('ref-reading-contents', $html);
    }

    public function test_the_map_looks_the_company_up_by_its_name(): void
    {
        $html = (string) $this->get('/contact')->assertOk()->assertSee('ref-map-card', false)->getContent();

        $this->assertStringNotContainsString('%7Bcompany%7D', $html);
        $this->assertStringNotContainsString('{company}', $html);
    }

    public function test_a_notice_page_with_nothing_published_says_so_in_its_own_card(): void
    {
        $this->get('/notice')->assertOk()->assertSee('ref-notice-empty', false);
    }

    public function test_the_questions_page_offers_both_company_numbers(): void
    {
        $this->get('/faq')->assertOk()
            ->assertSee('ref-faq-item', false)
            ->assertSeeInOrder(['ref-faq-help', '+8801730532966', '01730532967'], false);
    }

    public function test_the_product_page_leads_with_the_price_and_keeps_sharing_beside_the_details(): void
    {
        $product = Product::factory()->create(['name' => 'Plate Test Product', 'mrp' => '350.00']);

        $this->get('/products/'.$product->id)->assertOk()
            ->assertSeeInOrder(['ref-detail-card', 'Plate Test Product', 'ref-detail-price', '৳ 350.00', 'ref-detail-facts', 'data-share-panel', 'ref-product-description'], false);
    }
}
