<?php

namespace Tests\Feature;

use App\Models\Product;
use App\Models\User;
use App\Support\SiteCustomization;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\TestCase;

class SiteCustomizationTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->withoutVite();
    }

    public function test_guest_and_dealer_cannot_access_site_customization(): void
    {
        $this->get('/company/customization')->assertRedirect('/login');
        $dealer = User::factory()->create(['role' => 'dealer']);
        $this->actingAs($dealer)->get('/company/customization')->assertForbidden();
    }

    public function test_staff_with_content_permission_can_view_customization_dashboard(): void
    {
        $editor = User::factory()->create(['role' => 'editor']);
        $this->actingAs($editor)->get('/company/customization')
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->component('Portal')
                ->where('section', 'customization')
                ->has('data.customization')
                ->has('data.defaults')
            );
    }

    public function test_customization_can_be_saved_and_reflected_on_public_home_page(): void
    {
        $editor = User::factory()->create(['role' => 'editor']);
        $payload = [
            'customization' => [
                'top_bar_text' => 'OFFICIAL MADINA HEALTH STORE',
                'announcement_text' => 'Special Ramadan offer: 10% discount on all wellness supplements!',
                'featured_products_title' => 'Handpicked Wellness Collection',
                'about_headline' => 'Retired Shop Introduction',
                'company_intro_title' => 'Our Company and Distribution Network',
                'company_intro_body' => 'Custom company introduction for our partners.',
                'header_catalog_title' => 'Product Catalogue',
                'header_network_title' => 'Our Dealers',
                'copyright_text' => '© 2026 Madina Natural Health Care Custom Copyright.',
            ],
        ];

        $this->actingAs($editor)->post('/commands/customization', $payload)
            ->assertSessionHas('success');

        // Verify public home page reflects customized elements
        $response = $this->get('/');
        $response->assertOk();
        $response->assertSee('OFFICIAL MADINA HEALTH STORE');
        $response->assertSee('Special Ramadan offer: 10% discount on all wellness supplements!');
        $response->assertSee('Handpicked Wellness Collection');
        $response->assertSee('Our Company and Distribution Network');
        $response->assertSee('Custom company introduction for our partners.');
        $response->assertSee('Product Catalogue');
        $response->assertSee('Our Dealers');
        $response->assertDontSee('Retired Shop Introduction');
        $response->assertSee('© 2026 Madina Natural Health Care Custom Copyright.');

        // Verify unset elements still show standard placeholders
        $response->assertDontSee('Featured Categories');
        $response->assertSee('Our Products');
    }

    public function test_customization_can_be_reset_to_default_placeholders(): void
    {
        $editor = User::factory()->create(['role' => 'editor']);
        SiteCustomization::save([
            'top_bar_text' => 'CUSTOM TOP BAR TEXT',
        ]);

        $this->get('/')->assertSee('CUSTOM TOP BAR TEXT');

        $this->actingAs($editor)->post('/commands/customization/reset')
            ->assertSessionHas('success');

        $this->get('/')->assertSee('MADINA NATURAL HEALTH CARE');
        $this->get('/')->assertDontSee('CUSTOM TOP BAR TEXT');
    }

    public function test_inner_pages_and_catalog_reflect_customized_placeholders(): void
    {
        $editor = User::factory()->create(['role' => 'editor']);
        $payload = [
            'customization' => [
                'about_hero_title' => 'Custom About Hero Title',
                'contact_company_title' => 'Custom Contact Company Hub',
                'directory_title' => 'Custom Dealer Directory Point',
                'catalog_categories_title' => 'Custom Catalog Filter Head',
                'auth_login_title' => 'Custom Partner Access Portal',
            ],
        ];

        $this->actingAs($editor)->post('/commands/customization', $payload)
            ->assertSessionHas('success');

        $this->get('/about')->assertOk()->assertSee('Custom About Hero Title');
        $this->get('/contact')->assertOk()->assertSee('Custom Contact Company Hub');
        $this->get('/dealers')->assertOk()->assertSee('Custom Dealer Directory Point');
        $this->get('/products')->assertOk()->assertSee('Custom Catalog Filter Head');

        auth()->logout();
        $this->get('/login')->assertOk()->assertSee('Custom Partner Access Portal');
    }

    public function test_shortcuts_can_be_customized_added_removed_and_disabled(): void
    {
        $editor = User::factory()->create(['role' => 'editor']);

        // 1. Customize with 3 custom shortcuts (added/modified)
        $payload = [
            'customization' => [
                'shortcuts_section_enabled' => '1',
                'shortcuts' => [
                    ['title' => 'VIP Consultations', 'subtitle' => 'Book doctor session', 'icon' => 'fa-user-doctor', 'link' => '/vip-consult'],
                    ['title' => 'Rapid Medicine Dispatch', 'subtitle' => 'Same-day delivery', 'icon' => 'fa-truck-fast', 'link' => '/delivery'],
                    ['title' => 'Herbal Research Lab', 'subtitle' => 'Explore unani formulas', 'icon' => 'fa-flask', 'link' => '/lab'],
                ],
            ],
        ];

        $this->actingAs($editor)->post('/commands/customization', $payload)
            ->assertSessionHas('success');

        $response = $this->get('/');
        $response->assertOk();
        $response->assertSee('VIP Consultations');
        $response->assertSee('Book doctor session');
        $response->assertSee('Rapid Medicine Dispatch');
        $response->assertSee('Herbal Research Lab');
        // Unused default shortcuts should not be present
        $response->assertDontSee('Raise a Complain');

        // 2. Disable shortcuts section
        $payloadDisabled = [
            'customization' => [
                'shortcuts_section_enabled' => '0',
            ],
        ];
        $this->actingAs($editor)->post('/commands/customization', $payloadDisabled);

        $responseDisabled = $this->get('/');
        $responseDisabled->assertOk();
        $responseDisabled->assertDontSee('VIP Consultations');
        $responseDisabled->assertDontSee('Rapid Medicine Dispatch');
    }

    public function test_stored_campaigns_cannot_restore_public_shopping_or_override_product_mrp(): void
    {
        $editor = User::factory()->create(['role' => 'editor']);
        $product = Product::factory()->create([
            'name' => 'Super Ginseng Tonic',
            'mrp' => '1500.00',
            'active' => true,
        ]);

        // 1. Add 3 campaign cards with one linked to $product
        $payload = [
            'customization' => [
                'campaign_section_enabled' => '1',
                'campaign_title' => 'Mega Eid Wellness Festival',
                'campaign_cards' => [
                    [
                        'id' => 'card-custom-1',
                        'product_id' => (string) $product->id,
                        'tag' => '50% Mega Flash',
                        'title' => 'Super Ginseng Tonic Special Bundle',
                        'desc' => 'Buy Ginseng tonic with free unani honey.',
                        'price' => '999 Tk',
                        'mrp' => '1,500 Tk',
                        'save' => 'Save 501 Tk',
                        'btn' => 'Claim Tonic Offer',
                        'link' => '/products/'.$product->id,
                        'highlight' => '1',
                    ],
                    [
                        'id' => 'card-custom-2',
                        'product_id' => '',
                        'tag' => 'Dealer Starter',
                        'title' => 'Community Pharmacy Starter Package',
                        'desc' => 'Full retail starter kit for pharmacy dealers.',
                        'price' => '45,000 Tk',
                        'mrp' => '60,000 Tk',
                        'save' => 'Save 15,000 Tk',
                        'btn' => 'Become Partner',
                        'link' => '/dealers',
                        'highlight' => '0',
                    ],
                    [
                        'id' => 'card-custom-3',
                        'product_id' => '',
                        'tag' => 'Seasonal Bonus',
                        'title' => 'Family Daily Immunity Crate',
                        'desc' => '5 essential herbal tonics in one crate.',
                        'price' => '3,200 Tk',
                        'mrp' => '4,000 Tk',
                        'save' => 'Save 800 Tk',
                        'btn' => 'Order Crate',
                        'link' => '/products',
                        'highlight' => '0',
                    ],
                ],
            ],
        ];

        $this->actingAs($editor)->post('/commands/customization', $payload)
            ->assertSessionHas('success');

        $response = $this->get('/')->assertOk();
        $response->assertDontSee('Mega Eid Wellness Festival');
        $response->assertDontSee('Super Ginseng Tonic Special Bundle');
        $response->assertDontSee('999 Tk');
        $response->assertDontSee('Save 501 Tk');
        $response->assertDontSee('Community Pharmacy Starter Package');
        $response->assertDontSee('Family Daily Immunity Crate');
        $this->get('/products/'.$product->id)->assertOk()->assertSee('1500.00 BDT')->assertDontSee('999 Tk')->assertDontSee('Save 501 Tk');

        // 2. Test disabling campaign section
        $this->actingAs($editor)->post('/commands/customization', [
            'customization' => [
                'campaign_section_enabled' => '0',
            ],
        ]);

        $disabledResponse = $this->get('/');
        $disabledResponse->assertOk();
        $disabledResponse->assertDontSee('Mega Eid Wellness Festival');
        $disabledResponse->assertDontSee('Super Ginseng Tonic Special Bundle');
    }

    public function test_featured_products_section_can_be_customized_curated_and_filtered_by_tabs(): void
    {
        $editor = User::factory()->create(['role' => 'editor']);

        $p1 = Product::create([
            'name' => 'Madina Habbe Ambar',
            'sku' => 'UNANI-001',
            'category' => 'Unani Medicine',
            'unit' => 'box',
            'description' => 'Classical herbal tonic formulation.',
            'mrp' => '450.00',
            'dealer_price' => '320.00',
            'cost_price' => '200.00',
            'active' => true,
            'featured' => true,
            'stock' => 15,
            'low_stock_at' => 5,
        ]);

        $p2 = Product::create([
            'name' => 'Organic Spirulina Powder',
            'sku' => 'SUPP-002',
            'category' => 'Food Supplement',
            'unit' => 'jar',
            'description' => 'Pure spirulina dietary health supplement.',
            'mrp' => '850.00',
            'dealer_price' => '600.00',
            'cost_price' => '400.00',
            'active' => true,
            'featured' => false,
            'stock' => 4, // low stock test
            'low_stock_at' => 5,
        ]);

        $payload = [
            'customization' => [
                'featured_products_enabled' => '1',
                'featured_products_badge' => 'STAFF PICKS',
                'featured_products_title' => 'Top Natural Formulations',
                'public_products_subtitle' => 'Information about our botanical product range',
                'featured_products_view_all' => 'Explore Entire Catalog',
                'featured_products_tabs_enabled' => '1',
                'featured_products_mode' => 'curated',
                'featured_products_items' => [
                    [
                        'product_id' => (string) $p1->id,
                        'marketing_badge' => '★ Best Seller',
                        'override_title' => 'Habbe Ambar Premium',
                    ],
                    [
                        'product_id' => (string) $p2->id,
                        'marketing_badge' => '🔥 Hot Deal',
                        'override_title' => '',
                    ],
                ],
            ],
        ];

        $this->actingAs($editor)->post('/commands/customization', $payload)
            ->assertSessionHas('success');

        // Verify public home response
        $response = $this->get('/');
        $response->assertOk();
        $response->assertSee('STAFF PICKS');
        $response->assertSee('Top Natural Formulations');
        $response->assertSee('Information about our botanical product range');
        $response->assertSee('Explore Entire Catalog');
        $response->assertSee('Habbe Ambar Premium');
        $response->assertDontSee('★ Best Seller');
        $response->assertSee('Organic Spirulina Powder');
        $response->assertDontSee('🔥 Hot Deal');
        $response->assertDontSee('⚡ Only 4 Left!');
        $response->assertSee('Low Stock');
        $response->assertSee('Unani Medicine');
        $response->assertSee('Food Supplement');

        // Test Disabling Featured Products section
        $this->actingAs($editor)->post('/commands/customization', [
            'customization' => [
                'featured_products_enabled' => '0',
            ],
        ]);

        $disabledResponse = $this->get('/');
        $disabledResponse->assertOk();
        $disabledResponse->assertDontSee('Top Natural Formulations');
        $disabledResponse->assertDontSee('STAFF PICKS');
    }

    public function test_special_campaign_offers_supports_single_promo_override_and_package_bundles(): void
    {
        $editor = User::factory()->create(['role' => 'editor']);

        $p1 = Product::create([
            'name' => 'Botanical Vitality Syrup',
            'sku' => 'BOT-100',
            'category' => 'Herbal Care',
            'unit' => 'bottle',
            'description' => 'Pure herbal tonic.',
            'mrp' => '1000.00',
            'dealer_price' => '500.00', // Regular 50% catalog discount
            'cost_price' => '250.00',
            'active' => true,
            'stock' => 50,
        ]);

        $p2 = Product::create([
            'name' => 'Pure Black Seed Honey',
            'sku' => 'HON-200',
            'category' => 'Food Supplement',
            'unit' => 'jar',
            'description' => 'Natural pure organic honey.',
            'mrp' => '800.00',
            'dealer_price' => '480.00', // Regular 40% catalog discount
            'cost_price' => '300.00',
            'active' => true,
            'stock' => 30,
        ]);

        $payload = [
            'customization' => [
                'campaign_section_enabled' => '1',
                'campaign_badge' => 'EXCLUSIVE SPECIAL OFFERS',
                'campaign_title' => 'Limited Time Special Discounts',
                'campaign_cards' => [
                    // Single Product Offer: 60% promo discount (1000 MRP -> 400 price, Save 600)
                    [
                        'type' => 'single',
                        'product_id' => (string) $p1->id,
                        'promo_discount_pct' => 60,
                        'tag' => 'Single Deal',
                        'title' => 'Botanical Vitality Special Promo',
                        'desc' => 'Get 60% promotional discount instead of standard 50%.',
                        'feat1' => 'Immunity Booster',
                        'feat2' => 'Direct Factory Dispatch',
                        'price' => '400 Tk',
                        'mrp' => '1,000 Tk',
                        'save' => 'Save 600 Tk',
                        'btn' => 'Claim 60% Offer',
                        'link' => '/products/'.$p1->id,
                        'highlight' => '1',
                    ],
                    // Multi-Product Package Bundle: combined MRP 1800, flat 35% discount -> 1170 price, Save 630
                    [
                        'type' => 'bundle',
                        'product_id' => '',
                        'bundle_discount_mode' => 'total',
                        'bundle_discount_pct' => 35,
                        'bundle_items' => [
                            [
                                'product_id' => $p1->id,
                                'name' => $p1->name,
                                'sku' => $p1->sku,
                                'mrp' => '1000.00',
                            ],
                            [
                                'product_id' => $p2->id,
                                'name' => $p2->name,
                                'sku' => $p2->sku,
                                'mrp' => '800.00',
                            ],
                        ],
                        'tag' => 'Package Bundle',
                        'title' => 'Ultimate Wellness Duo Package',
                        'desc' => 'Comprehensive 2-in-1 family healthcare pack.',
                        'feat1' => 'Includes Syrup + Honey',
                        'feat2' => 'Free Priority Delivery',
                        'price' => '1,170 Tk',
                        'mrp' => '1,800 Tk',
                        'save' => 'Save 630 Tk',
                        'btn' => 'Order Package',
                        'link' => '/products',
                        'highlight' => '0',
                    ],
                ],
            ],
        ];

        $this->actingAs($editor)->post('/commands/customization', $payload)
            ->assertSessionHas('success');

        // Stored campaign configuration cannot restore public retail offers or purchase controls.
        $response = $this->get('/')->assertOk();
        $response->assertDontSee('EXCLUSIVE SPECIAL OFFERS');
        $response->assertDontSee('Limited Time Special Discounts');
        $response->assertDontSee('Botanical Vitality Special Promo');
        $response->assertDontSee('Ultimate Wellness Duo Package');
        $response->assertDontSee('Save 600 Tk');
        $response->assertDontSee('Package Includes (2 Products):');
        $response->assertDontSee('Add to Cart');
        $response->assertDontSee('Buy Now');
        $this->get('/products/'.$p1->id)->assertOk()->assertSee('1000.00 BDT')->assertDontSee('400 Tk')->assertDontSee('Save 600 Tk');

        // Public product information always uses MRP, even with stored legacy campaign settings.
        $cardActive = SiteCustomization::formatPublicProductCard($p1);
        $this->assertSame('1000.00', $cardActive['price']);

        // Removing an old campaign does not expose partner prices.
        SiteCustomization::save([
            'campaign_cards' => [],
        ]);

        $cardReverted = SiteCustomization::formatPublicProductCard($p1);
        $this->assertSame('1000.00', $cardReverted['price']);
    }

    public function test_footer_and_contact_support_phone_social_and_app_store_placeholders(): void
    {
        // 1. Verify default values in public home and contact views
        $home = $this->get('/');
        $home->assertOk();
        $home->assertSee('+8801730532966');
        $home->assertSee('tel:+8801730532966');
        $home->assertSee('href="#"', false);
        $home->assertSee('https://wa.me/8801730532966');

        $contact = $this->get('/contact');
        $contact->assertOk();
        $contact->assertSee('+8801730532966');
        $contact->assertSee('Customer Care &amp; Hotline', false);

        // 2. Staff customizes phone, social media, and app store URLs
        $editor = User::factory()->create(['role' => 'editor']);
        $payload = [
            'customization' => [
                'footer_support_title' => 'Hotline: +8801888888888',
                'footer_support_link' => 'tel:+8801888888888',
                'footer_phone' => '+8801888888888',
                'contact_phone' => '+8801888888888',
                'social_facebook' => 'https://facebook.com/custom-madina-page',
                'social_whatsapp' => '01888888888',
                'app_google_play_link' => 'https://play.google.com/store/apps/details?id=com.madina.app',
                'app_apple_store_link' => 'https://apps.apple.com/app/madina-care/id123456789',
                'footer_link_about_url' => '/custom-story',
            ],
        ];

        $this->actingAs($editor)->post('/commands/customization', $payload)
            ->assertSessionHas('success');

        $customHome = $this->get('/');
        $customHome->assertOk();
        $customHome->assertSee('Hotline: +8801888888888');
        $customHome->assertSee('tel:+8801888888888');
        $customHome->assertSee('https://facebook.com/custom-madina-page');
        $customHome->assertSee('https://wa.me/8801888888888');
        $customHome->assertSee('https://play.google.com/store/apps/details?id=com.madina.app');
        $customHome->assertSee('https://apps.apple.com/app/madina-care/id123456789');
        $customHome->assertSee('/custom-story');

        $customContact = $this->get('/contact');
        $customContact->assertOk();
        $customContact->assertSee('+8801888888888');
        $customContact->assertSee('https://facebook.com/custom-madina-page');
    }

    public function test_header_navigation_categories_placeholders_and_customization(): void
    {
        $this->assertSame([], SiteCustomization::getNavCategories());
        Product::factory()->create(['category' => 'Published Company Range', 'active' => true]);
        Product::factory()->create(['category' => 'Unpublished Range', 'active' => false]);
        $expectedDefaults = ['Published Company Range'];
        $this->assertEquals($expectedDefaults, SiteCustomization::getNavCategories());

        $home = $this->get('/');
        $home->assertOk();
        foreach ($expectedDefaults as $cat) {
            $home->assertSee($cat);
            $home->assertSee('/products?category='.urlencode($cat));
        }

        // 2. Staff customizes categories (e.g. adding 3 new categories, removing Furniture and Leather Items)
        $editor = User::factory()->create(['role' => 'editor']);
        $customCategories = [
            'Organic Honey',
            'Black Seed Oil',
            'Herbal Tonics',
            'Medical Equipment',
            'Medicine',
        ];

        $payload = [
            'customization' => [
                'nav_categories' => implode("\n", $customCategories),
            ],
        ];

        $this->actingAs($editor)->post('/commands/customization', $payload)
            ->assertSessionHas('success');

        // Verify backend helper returns custom items
        $this->assertEquals($customCategories, SiteCustomization::getNavCategories());

        // Verify public home page reflects custom categories
        $customHome = $this->get('/');
        $customHome->assertOk();
        foreach ($customCategories as $cat) {
            $customHome->assertSee($cat);
            $customHome->assertSee('/products?category='.urlencode($cat));
        }

        // Removed items must no longer appear in header category dropdown
        $customHtml = $customHome->getContent();
        $this->assertStringNotContainsString('All Furniture', $customHtml);
        $this->assertStringNotContainsString('All Leather Items', $customHtml);
        $this->assertStringContainsString('All Organic Honey', $customHtml);
        $this->assertStringContainsString('All Black Seed Oil', $customHtml);

        // Clearing the menu uses the published company inventory categories.
        $clearPayload = [
            'customization' => [
                'nav_categories' => '',
            ],
        ];
        $this->actingAs($editor)->post('/commands/customization', $clearPayload)
            ->assertSessionHas('success');

        $this->assertEquals($expectedDefaults, SiteCustomization::getNavCategories());

        $fallbackHome = $this->get('/');
        $fallbackHome->assertOk();
        foreach ($expectedDefaults as $cat) {
            $fallbackHome->assertSee($cat);
        }
    }
}
