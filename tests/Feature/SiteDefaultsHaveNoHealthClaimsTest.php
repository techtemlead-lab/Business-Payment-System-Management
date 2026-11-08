<?php

namespace Tests\Feature;

use App\Models\Product;
use App\Support\SiteCustomization;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * Built-in website text must not claim anything the owner has not supplied.
 * Text the owner writes himself is his own and is left exactly as saved.
 */
class SiteDefaultsHaveNoHealthClaimsTest extends TestCase
{
    use RefreshDatabase;

    /** @var list<string> */
    private const Claims = ['100%', '১০০%', 'Pure', 'খাঁটি', 'Immunity', 'Vitality', 'রোগ প্রতিরোধ', 'NATURAL CARE', 'প্রাকৃতিক যত্ন'];

    protected function setUp(): void
    {
        parent::setUp();
        $this->withoutVite();
    }

    public function test_side_banner_picture_descriptions_and_the_tagline_are_neutral_in_both_languages(): void
    {
        $keys = ['side_banner_1_alt', 'side_banner_1_slide1_alt', 'side_banner_1_slide2_alt', 'side_banner_1_slide3_alt', 'brand_tagline'];

        foreach (['en', 'bn'] as $locale) {
            foreach ($keys as $key) {
                $text = trans('site.'.$key, [], $locale);
                foreach (self::Claims as $claim) {
                    $this->assertStringNotContainsString($claim, $text, $locale.' '.$key);
                }
            }
            $this->assertSame(
                $locale === 'en' ? 'PRODUCT' : 'পণ্য',
                trans('portal.product_art_tagline', [], $locale),
            );
        }
    }

    public function test_home_page_shows_no_claim_in_either_language(): void
    {
        foreach (['en', 'bn'] as $locale) {
            $page = $this->withCookie('locale', $locale)->get('/')->assertOk();
            foreach (['100% Pure', '১০০% খাঁটি', 'Immunity &amp; Vitality', 'রোগ প্রতিরোধ'] as $claim) {
                $page->assertDontSee($claim, false);
            }
        }
    }

    public function test_placeholder_product_art_has_no_natural_care_tagline(): void
    {
        Product::factory()->create(['active' => true, 'image_path' => null]);

        foreach (['en', 'bn'] as $locale) {
            $this->withCookie('locale', $locale)->get('/products')->assertOk()
                ->assertDontSee('NATURAL CARE')
                ->assertDontSee('প্রাকৃতিক যত্ন');
        }
    }

    public function test_a_campaign_card_left_blank_falls_back_to_a_neutral_line(): void
    {
        $this->saveCustomization([
            'campaign_section_enabled' => '1',
            'campaign_cards' => [['type' => 'bundle', 'title' => 'Winter offer', 'feat1' => '', 'link' => '/products']],
        ]);

        $card = SiteCustomization::getCampaignCards()[0];

        $this->assertSame('Available from authorized dealers', $card['feat1']);
    }

    public function test_a_campaign_line_the_owner_saved_stays_as_it_is(): void
    {
        $this->saveCustomization([
            'campaign_cards' => [['type' => 'bundle', 'title' => 'Winter offer', 'feat1' => '100% Pure Botanical Ingredients', 'link' => '/products']],
        ]);

        $this->assertSame('100% Pure Botanical Ingredients', SiteCustomization::getCampaignCards()[0]['feat1']);
    }

    public function test_old_factory_banner_text_saved_by_the_editor_shows_the_new_wording_but_owner_text_stays(): void
    {
        $this->saveCustomization([
            'side_banner_1_slides' => [
                ['image' => '/branding/madina-side-banner-1.jpg', 'link' => '/products', 'alt' => 'Madina Premium Natural Care - 100% Pure Botanical Ingredients'],
                ['image' => '/branding/madina-top-skincare.jpg', 'link' => '/products', 'alt' => 'My own picture description'],
                ['image' => '/branding/madina-top-immunity.jpg', 'link' => '/products', 'alt' => 'Madina Daily Immunity & Vitality Boosters'],
            ],
        ]);

        $this->withCookie('locale', 'en')->get('/')->assertOk()
            ->assertDontSee('100% Pure Botanical')
            ->assertDontSee('Immunity &amp; Vitality', false)
            ->assertSee('alt="'.__('site.side_banner_1_slide1_alt', [], 'en').'"', false)
            ->assertSee('My own picture description');

        $stored = json_decode((string) DB::table('content_pages')->where('slug', 'frontend_customization')->value('body'), true);
        $this->assertSame('Madina Premium Natural Care - 100% Pure Botanical Ingredients', $stored['side_banner_1_slides'][0]['alt'], 'The saved record itself is not rewritten.');
    }

    /**
     * @param  array<string, mixed>  $values
     */
    private function saveCustomization(array $values): void
    {
        DB::table('content_pages')->insert([
            'slug' => 'frontend_customization',
            'title' => 'Frontend Customization',
            'body' => json_encode($values, JSON_UNESCAPED_UNICODE),
            'published' => true,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
        SiteCustomization::forgetCache();
    }
}
