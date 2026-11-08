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
 * website editor: the home page intro cards, the order invoice letterhead
 * and the distributor name on product pages.
 */
class WebsiteSectionsCmsTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->withoutVite();
    }

    public function test_home_intro_cards_show_factory_text_in_each_language(): void
    {
        $this->withSession(['locale' => 'en'])->get('/')->assertOk()
            ->assertSee(__('site.company_intro_eyebrow', [], 'en'))
            ->assertSee(__('site.product_range_title', [], 'en'))
            ->assertSee(__('site.partner_with_us_sub', [], 'en'));

        $this->withSession(['locale' => 'bn'])->get('/')->assertOk()
            ->assertSee(__('site.company_intro_eyebrow', [], 'bn'))
            ->assertSee(__('site.product_range_title', [], 'bn'))
            ->assertSee(__('site.partner_with_us_sub', [], 'bn'))
            ->assertDontSee(__('site.company_intro_eyebrow', [], 'en'));
    }

    public function test_home_page_shows_bismillah_above_the_headline_and_owner_can_turn_it_off(): void
    {
        $this->withSession(['locale' => 'bn'])->get('/')->assertOk()
            ->assertSeeInOrder(['ref-bismillah', 'বিসমিল্লাহির রাহমানির রাহিম', 'company-intro-title'], false);

        $this->withSession(['locale' => 'en'])->get('/')->assertOk()
            ->assertSee('Bismillahir Rahmanir Rahim');

        SiteCustomization::save(['home_bismillah_text' => 'بسم الله الرحمن الرحيم']);
        $this->withSession(['locale' => 'bn'])->get('/')->assertOk()->assertSee('بسم الله الرحمن الرحيم');

        SiteCustomization::save(['home_bismillah_enabled' => '0']);
        $this->withSession(['locale' => 'bn'])->get('/')->assertOk()->assertDontSee('ref-bismillah', false);
    }

    public function test_owner_edits_to_the_home_intro_cards_are_shown_as_written(): void
    {
        SiteCustomization::save([
            'company_intro_eyebrow' => 'Since 2019 in Rangpur',
            'product_range_title' => 'Herbal shelf',
            'product_range_sub' => 'Teas, oils and honey',
            'partner_with_us_title' => 'Open a Madina shop',
            'partner_with_us_sub' => 'Talk to our dealer team',
        ]);

        foreach (['en', 'bn'] as $locale) {
            $this->withSession(['locale' => $locale])->get('/')->assertOk()
                ->assertSee('Since 2019 in Rangpur')
                ->assertSee('Herbal shelf')
                ->assertSee('Teas, oils and honey')
                ->assertSee('Open a Madina shop')
                ->assertSee('Talk to our dealer team');
        }
    }

    public function test_services_card_text_is_editable_when_the_products_page_is_off(): void
    {
        SiteCustomization::save([
            'page_enabled_products' => '0',
            'our_services_title' => 'Physio and herbal advice',
            'our_services_sub' => 'Open six days a week',
        ]);

        $this->get('/')->assertOk()
            ->assertSee('Physio and herbal advice')
            ->assertSee('Open six days a week');
    }

    public function test_website_text_with_web_code_is_shown_as_plain_text(): void
    {
        SiteCustomization::save(['company_intro_eyebrow' => '<script>alert(1)</script>Welcome']);

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
            'footer_support_link' => 'javascript:alert(1)',
        ]])->assertSessionHasErrors('customization.footer_support_link');
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
            ->assertSee(config('madina.invoice_marketed_phones'));
    }

    public function test_invoice_letterhead_uses_what_the_owner_typed(): void
    {
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
            ->assertDontSee(config('madina.invoice_manufacturer'));
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
