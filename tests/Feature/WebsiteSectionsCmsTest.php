<?php

namespace Tests\Feature;

use App\Models\Order;
use App\Models\Product;
use App\Models\User;
use App\Support\SiteCustomization;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Tests\TestCase;

/**
 * Website text that used to be fixed in code and is now edited in the
 * website editor: the top of the page, the order
 * invoice letterhead and the distributor name on product pages.
 */
class WebsiteSectionsCmsTest extends TestCase
{
    use RefreshDatabase;

    private const ArabicBismillah = 'بسم الله الرحمن الرحيم';

    protected function setUp(): void
    {
        parent::setUp();
        $this->withoutVite();
        SiteCustomization::forgetCache();
    }

    public function test_the_sections_the_owner_crossed_out_are_gone_from_the_home_page(): void
    {
        foreach (['en', 'bn'] as $locale) {
            $this->withSession(['locale' => $locale])->get('/')->assertOk()
                ->assertDontSee('company-intro-title', false)
                ->assertDontSee('ref-shortcuts', false)
                ->assertDontSee('ref-trust-pillars', false)
                ->assertDontSee('ref-featured-categories', false)
                ->assertDontSee(__('site.company_intro_eyebrow', [], $locale))
                ->assertDontSee(__('site.partner_with_us_sub', [], $locale));
        }
    }

    public function test_bismillah_is_the_first_line_of_every_public_page_and_owner_can_turn_it_off(): void
    {
        foreach (['/', '/about', '/products'] as $path) {
            $this->withSession(['locale' => 'bn'])->get($path)->assertOk()
                ->assertSeeInOrder(['ref-bismillah', 'বিসমিল্লাহির রাহমানির রাহিম', 'ref-company-bar', 'ref-sticky-chrome'], false);
        }

        $this->withSession(['locale' => 'bn'])->get('/')->assertOk()->assertDontSee('dir="rtl"', false);

        $this->withSession(['locale' => 'en'])->get('/')->assertOk()
            ->assertSee('<p class="ref-bismillah" lang="ar" dir="rtl"><span>'.self::ArabicBismillah.'</span></p>', false)
            ->assertDontSee('Bismillahir Rahmanir Rahim');

        SiteCustomization::save(['home_bismillah_enabled' => '0']);
        $this->withSession(['locale' => 'bn'])->get('/')->assertOk()->assertDontSee('ref-bismillah', false);
    }

    public function test_navigation_buttons_have_no_second_line_of_small_text(): void
    {
        foreach (['en', 'bn'] as $locale) {
            $html = $this->withSession(['locale' => $locale])->get('/')->assertOk()->getContent();

            $this->assertStringNotContainsString('<small', Str::between($html, 'class="ref-header-links"', 'ref-theme-toggle'));
            $this->assertStringNotContainsString(__('site.about_sub', [], $locale), $html);
            $this->assertStringNotContainsString(__('site.contact_sub', [], $locale), $html);
        }
    }

    public function test_managing_director_message_comes_before_the_products_on_the_home_page(): void
    {
        Product::factory()->create(['featured' => true]);

        $this->get('/')->assertOk()->assertSeeInOrder(['ref-md-message--home', 'ref-promotions', 'id="featured-products"'], false);
    }

    public function test_home_page_shows_a_short_managing_director_message_and_the_about_page_the_longer_one(): void
    {
        SiteCustomization::save(['md_message_body' => "First paragraph.\n\nSecond paragraph.\n\nThird paragraph.\n\nFourth paragraph.\n\nFifth paragraph."]);

        $home = $this->withSession(['locale' => 'en'])->get('/')->assertOk()->getContent();
        $this->assertStringContainsString('id="md-message-more-home" hidden', $home);
        $this->assertLessThan(strpos($home, 'Third paragraph.'), strpos($home, 'id="md-message-more-home" hidden'));

        $about = $this->withSession(['locale' => 'en'])->get('/about')->assertOk()->getContent();
        $this->assertLessThan(strpos($about, 'Fourth paragraph.'), strpos($about, 'Third paragraph.'));
        $this->assertLessThan(strpos($about, 'Fourth paragraph.'), strpos($about, 'id="md-message-more-about" hidden'));
    }

    public function test_product_cards_show_the_picture_with_nothing_on_top_of_it(): void
    {
        Product::factory()->create(['name' => 'Bright Card Honey', 'category' => 'Oil', 'image_path' => 'products/real.jpg', 'active' => true]);

        $html = $this->get('/products')->assertOk()->getContent();

        $this->assertStringContainsString('ref-card-picture', $html);
        $this->assertStringNotContainsString('luxury-hero-stage', $html);
        $this->assertStringNotContainsString('luxury-marble-pedestal', $html);
        $this->assertMatchesRegularExpression('#<a class="ref-card-picture"[^>]*>\s*<img [^>]*>\s*</a>#', $html);
    }

    public function test_saving_the_editor_without_touching_bismillah_keeps_each_languages_own_line(): void
    {
        $storedByAnUntouchedSave = [
            'empty box from a fresh editor' => '',
            'the English factory text saved by another field' => self::ArabicBismillah,
            'the old spelled-out English line' => 'Bismillahir Rahmanir Rahim',
            'the Bangla factory text' => 'বিসমিল্লাহির রাহমানির রাহিম',
        ];

        foreach ($storedByAnUntouchedSave as $case => $stored) {
            SiteCustomization::save(['home_bismillah_text' => $stored]);
            SiteCustomization::save(SiteCustomization::raw());

            $this->withSession(['locale' => 'en'])->get('/')->assertOk()
                ->assertSee('<p class="ref-bismillah" lang="ar" dir="rtl"><span>'.self::ArabicBismillah.'</span></p>', false);
            $this->withSession(['locale' => 'bn'])->get('/')->assertOk()
                ->assertSee('<p class="ref-bismillah"><span>বিসমিল্লাহির রাহমানির রাহিম</span></p>', false);
            $this->assertNotSame('Bismillahir Rahmanir Rahim', SiteCustomization::all()['home_bismillah_text'], $case);
        }
    }

    public function test_owner_wording_for_bismillah_is_shown_as_written_in_both_languages(): void
    {
        $withVowelMarks = 'بِسْمِ اللَّهِ الرَّحْمَنِ الرَّحِيمِ';
        SiteCustomization::save(['home_bismillah_text' => $withVowelMarks]);
        foreach (['en', 'bn'] as $locale) {
            $this->withSession(['locale' => $locale])->get('/')->assertOk()
                ->assertSee('<p class="ref-bismillah" lang="ar" dir="rtl"><span>'.$withVowelMarks.'</span></p>', false);
        }

        SiteCustomization::save(['home_bismillah_text' => 'In the name of Allah']);
        foreach (['en', 'bn'] as $locale) {
            $this->withSession(['locale' => $locale])->get('/')->assertOk()
                ->assertSee('<p class="ref-bismillah"><span>In the name of Allah</span></p>', false);
        }
    }

    public function test_website_text_with_web_code_is_shown_as_plain_text(): void
    {
        SiteCustomization::save(['home_bismillah_text' => '<script>alert(1)</script>Welcome']);

        $html = $this->get('/')->assertOk()->getContent();

        $this->assertStringNotContainsString('<script>alert(1)</script>', $html);
        $this->assertStringContainsString('&lt;script&gt;alert(1)&lt;/script&gt;Welcome', $html);
    }

    public function test_only_website_staff_can_save_the_new_fields(): void
    {
        $payload = ['customization' => ['company_intro_eyebrow' => 'Changed by staff']];

        $this->actingAs(User::factory()->staff('account_manager')->create())
            ->post('/commands/customization', $payload)->assertForbidden();
        $this->actingAs(User::factory()->create(['role' => 'dealer']))
            ->post('/commands/customization', $payload)->assertForbidden();
        $this->assertNotSame('Changed by staff', SiteCustomization::all()['company_intro_eyebrow']);

        $this->actingAs(User::factory()->staff('editor')->create())
            ->post('/commands/customization', $payload)->assertSessionHas('success');
        $this->assertSame('Changed by staff', SiteCustomization::all()['company_intro_eyebrow']);
    }

    public function test_the_editor_saves_when_picture_and_link_fields_are_left_empty(): void
    {
        $editor = User::factory()->staff('editor')->create();

        $this->actingAs($editor)->post('/commands/customization', ['customization' => [
            'slides' => [['image' => '', 'link' => '', 'alt' => ''], ['image' => '', 'link' => '', 'alt' => ''], ['image' => '', 'link' => '', 'alt' => '']],
            'footer_support_link' => '',
            'social_facebook' => '',
            'company_intro_eyebrow' => 'Saved with empty links',
        ]])->assertSessionHasNoErrors()->assertSessionHas('success');

        $this->assertSame('Saved with empty links', SiteCustomization::all()['company_intro_eyebrow']);
        $this->get('/')->assertOk()->assertSee('/branding/madina-hero-banner-1.jpg', false);
    }

    public function test_the_editor_still_refuses_unsafe_links(): void
    {
        $editor = User::factory()->staff('editor')->create();

        $this->actingAs($editor)->post('/commands/customization', ['customization' => [
            'footer_directory_link' => 'javascript:alert(1)',
        ]])->assertSessionHasErrors('customization.footer_directory_link');
    }

    public function test_invoice_letterhead_starts_from_the_company_settings(): void
    {
        $defaults = SiteCustomization::defaults();

        $this->assertSame(config('madina.invoice_manufacturer'), $defaults['invoice_manufacturer']);
        $this->assertSame(config('madina.invoice_marketed_by'), $defaults['invoice_marketed_by']);
        $this->assertSame(config('madina.invoice_marketed_phones'), $defaults['invoice_marketed_phones']);

        [$dealer, $order] = $this->dealerWithOrder();

        $this->actingAs($dealer)->onPortal('dealer')
            ->get('/orders/'.$order->id.'/invoice/print')
            ->assertOk()
            ->assertSee(config('madina.invoice_manufacturer'))
            ->assertSee('01730532967, +8801730532966');
    }

    public function test_invoice_letterhead_uses_what_the_owner_typed(): void
    {
        config(['madina.invoice_manufacturer' => 'Factory Default Maker']);
        SiteCustomization::save([
            'invoice_manufacturer' => 'Green Leaf Labs',
            'invoice_marketed_by' => 'Madina Herbal House',
            'invoice_marketed_phones' => '01800-000000',
            'contact_company_title' => 'Madina Herbal House Ltd',
            'office_address' => 'Station Road, Rangpur',
            'contact_phone' => '01900-111111',
        ]);
        [$dealer, $order] = $this->dealerWithOrder();

        $this->actingAs($dealer)->onPortal('dealer')
            ->get('/orders/'.$order->id.'/invoice/print')
            ->assertOk()
            ->assertSee('Green Leaf Labs')
            ->assertSee('Madina Herbal House')
            ->assertSee('01800-000000')
            ->assertSee('Station Road, Rangpur')
            ->assertDontSee('Factory Default Maker');
    }

    public function test_product_page_shows_the_company_name_from_the_website_editor(): void
    {
        $product = Product::factory()->create(['active' => true]);

        $this->withSession(['locale' => 'bn'])->get('/products/'.$product->id)->assertOk()
            ->assertSee(__('site.contact_company_title', [], 'bn'));

        SiteCustomization::save(['contact_company_title' => 'Madina Herbal House Ltd']);

        $this->get('/products/'.$product->id)->assertOk()->assertSee('Madina Herbal House Ltd');
    }

    /**
     * @return array{0: User, 1: Order}
     */
    private function dealerWithOrder(): array
    {
        $dealer = User::factory()->create(['role' => 'dealer', 'status' => 'active']);
        $order = Order::create([
            'user_id' => $dealer->id,
            'number' => 'INV-CMS-1',
            'status' => 'approved',
            'total' => '500.00',
            'paid' => '500.00',
            'idempotency_key' => (string) Str::uuid(),
            'payload_hash' => hash('sha256', 'INV-CMS-1'),
        ]);
        $product = Product::factory()->create(['mrp' => '500.00', 'dealer_price' => '500.00']);
        $order->lines()->create([
            'product_id' => $product->id,
            'name' => $product->name,
            'sku' => $product->sku,
            'quantity' => 1,
            'mrp' => $product->mrp,
            'unit_price' => '500.00',
            'total' => '500.00',
        ]);

        return [$dealer, $order];
    }
}
