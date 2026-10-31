<?php

namespace Tests\Feature;

use App\Models\Product;
use App\Models\User;
use App\Support\PortalHost;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Password;
use Tests\TestCase;

class PublicWebsiteTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->withoutVite();
    }

    public function test_catalog_filters_public_products_and_uses_exact_price_order(): void
    {
        foreach ([['A', '200.01', 4, true], ['B', '200.02', 0, true], ['C', '90.00', 4, false]] as [$name,$price,$stock,$active]) {
            Product::create(['sku' => 'TEST-'.$name, 'name' => 'Public '.$name, 'category' => 'Care', 'mrp' => $price, 'dealer_price' => '50.00', 'stock' => $stock, 'active' => $active]);
        }
        $this->get('/products?sort=price_desc')->assertOk()->assertSeeInOrder(['Public B', 'Public A'])->assertDontSee('Public C')->assertDontSee('50.00 Tk');
        $this->get('/products?in_stock=1&min_price=200.01&max_price=200.01')->assertOk()->assertSee('Public A')->assertDontSee('Public B');
        $this->get('/products?min_price=invalid')->assertSessionHasErrors('min_price');
        $this->get('/products/preview/reference-product-01')->assertNotFound();
    }

    public function test_directory_only_publishes_consented_active_dealers(): void
    {
        $visible = User::factory()->create(['role' => 'dealer', 'status' => 'active', 'name' => 'Published Partner', 'directory_visible' => true, 'division' => 'Dhaka', 'district' => 'Dhaka', 'phone' => 'PUBLIC-TEST-PHONE', 'nid' => 'PRIVATE-NID', 'profile' => ['father_name' => 'PRIVATE-PARENT']]);
        User::factory()->create(['name' => 'Hidden Partner', 'directory_visible' => false]);
        User::factory()->create(['name' => 'Suspended Partner', 'status' => 'suspended', 'directory_visible' => true]);
        User::factory()->create(['name' => 'Staff Partner', 'role' => 'super_admin', 'directory_visible' => true]);
        $this->get('/dealers?district=Dhaka')->assertOk()->assertSee('Published Partner')->assertSee('PUBLIC-TEST-PHONE')->assertDontSee('Hidden Partner')->assertDontSee('Suspended Partner')->assertDontSee('Staff Partner')->assertDontSee('PRIVATE-NID')->assertDontSee('PRIVATE-PARENT')->assertDontSee($visible->email);
    }

    public function test_enquiry_is_saved_without_creating_an_account_and_rejects_honeypot(): void
    {
        $data = ['name' => 'Synthetic Visitor', 'phone' => 'TEST-PHONE', 'topic' => 'Dealer enquiry', 'message' => 'Please share dealer information.'];
        $this->from('/contact')->post('/enquiries', $data)->assertRedirect('/contact')->assertSessionHas('success');
        $this->assertDatabaseCount('users', 0);
        $this->assertDatabaseCount('enquiries', 1);
        $this->assertDatabaseHas('enquiries', ['name' => 'Synthetic Visitor', 'status' => 'new']);
        $this->post('/enquiries', [...$data, 'website' => 'spam'])->assertSessionHasErrors('website');
        $this->assertDatabaseCount('enquiries', 1);

        $partnership = [...$data, 'name' => 'Partner Applicant', 'topic' => 'Partnership enquiry', 'message' => 'Interested in a dealership partnership in Chittagong.'];
        $this->from('/contact')->post('/enquiries', $partnership)->assertRedirect('/contact')->assertSessionHas('success');
        $this->assertDatabaseHas('enquiries', ['name' => 'Partner Applicant', 'topic' => 'partnership_enquiry', 'status' => 'new']);
    }

    public function test_cms_text_is_escaped_and_login_uses_staff_created_accounts(): void
    {
        DB::table('content_pages')->insert(['slug' => 'about', 'title' => 'Company information', 'body' => '<script>alert("cms")</script>', 'published' => true, 'created_at' => now(), 'updated_at' => now()]);
        $this->get('/about')->assertOk()->assertSee('&lt;script&gt;', false)->assertDontSee('<script>alert("cms")</script>', false);
        $this->get('/login')->assertOk()->assertSee('Account Login')->assertSee('/madina-header-logo.png')->assertDontSee('/register');
        $u = User::factory()->create(['status' => 'active', 'password' => 'TestPassword12345']);
        $this->post('/login', ['email' => $u->email, 'password' => 'TestPassword12345'])->assertRedirect('/');
        $this->assertAuthenticatedAs($u);
    }

    public function test_password_recovery_form_resets_an_eligible_account(): void
    {
        $u = User::factory()->create(['status' => 'active', 'password' => 'PreviousPassword123']);
        $token = Password::createToken($u);
        $this->get('/reset-password/'.$token.'?email='.urlencode($u->email))->assertOk()->assertSee('Confirm Password');
        $this->post('/reset-password', ['email' => $u->email, 'token' => $token, 'password' => 'ReplacementPassword123', 'password_confirmation' => 'ReplacementPassword123'])->assertRedirect('/login');
        $this->assertTrue(Hash::check('ReplacementPassword123', $u->fresh()->password));
        $this->post('/reset-password', ['email' => $u->email, 'token' => $token, 'password' => 'AnotherPassword123', 'password_confirmation' => 'AnotherPassword123'])->assertSessionHasErrors('email');
    }

    public function test_nearest_dealer_search_uses_browser_gps_coordinates_and_calculates_distance(): void
    {
        $near = User::factory()->create([
            'role' => 'dealer', 'status' => 'active', 'name' => 'Nearby Savar Dealer',
            'dealer_code' => 'D-SAVAR-01', 'directory_visible' => true,
            'division' => 'Dhaka', 'district' => 'Dhaka',
            'latitude' => '23.914000', 'longitude' => '90.260000',
            'profile' => ['upazila' => 'Savar', 'pharmacy' => 'Madina Health Point'],
        ]);
        $far = User::factory()->create([
            'role' => 'dealer', 'status' => 'active', 'name' => 'Far Chittagong Dealer',
            'dealer_code' => 'D-CTG-01', 'directory_visible' => true,
            'division' => 'Chittagong', 'district' => 'Chittagong',
            'latitude' => '22.356900', 'longitude' => '91.783200',
        ]);

        // Default view: shows location button and dealers
        $res = $this->get('/dealers');
        $res->assertOk();
        $res->assertSee('Find nearest dealers');
        $res->assertSee('Nearby Savar Dealer');
        $res->assertSee('Far Chittagong Dealer');

        // With user GPS coordinates close to Savar (Lat 23.91, Lng 90.26)
        $gpsRes = $this->get('/dealers?lat=23.910000&lng=90.260000');
        $gpsRes->assertOk();
        $gpsRes->assertSeeInOrder(['Nearby Savar Dealer', 'Far Chittagong Dealer']);
        $gpsRes->assertSee('km away');
        $gpsRes->assertSee('Madina Health Point');
        $gpsRes->assertSee('Showing dealers sorted by distance to your detected location.');
    }

    public function test_contact_page_renders_office_location_map_and_directions(): void
    {
        $res = $this->get('/contact');
        $res->assertOk();
        $res->assertSee('Office &amp; Healthcare Center Location', false);
        $res->assertSee('https://www.google.com/maps/dir/?api=1&amp;destination=25.7701%2C88.824', false);
        $res->assertSee('maps.google.com/maps?q=25.7701%2C88.824', false);
        $res->assertSee('Thana Road, Pirganj 5470, Rangpur');
        $res->assertDontSee('openstreetmap.org', false);
        $res->assertDontSee('23.914')->assertDontSee('contact@madinanatural.com');
        $res->assertSee('Open Map Directions');
    }

    public function test_contact_map_directions_follow_cms_office_address_and_optional_coordinates(): void
    {
        $editor = User::factory()->create(['role' => 'editor']);
        $this->actingAs($editor)->post('/commands/customization', [
            'customization' => [
                'office_address' => 'Chaira, Moshorikhula, Hemayetpur, Savar, Dhaka',
                'map_latitude' => '23.914100',
                'map_longitude' => '90.260200',
            ],
        ])->assertSessionHas('success');

        $res = $this->get('/contact');
        $res->assertOk();
        $res->assertSee('Chaira, Moshorikhula, Hemayetpur, Savar, Dhaka');
        $res->assertSee('https://www.google.com/maps/dir/?api=1&amp;destination=23.9141%2C90.2602', false);
        $res->assertDontSee('openstreetmap.org', false);
        $res->assertDontSee(rawurlencode('Thana Road, Pirganj 5470, Rangpur, Bangladesh'), false);
    }

    public function test_about_page_renders_structured_history_mission_vision_objectives(): void
    {
        $res = $this->get('/about');
        $res->assertOk();
        $res->assertSee('Company History');
        $res->assertSee('Our Mission');
        $res->assertSee('Our Vision');
        $res->assertSee('Core Objectives');
        $res->assertSee('Authentic Wellness:');
        $res->assertSee('Empowered Partners:');
        $res->assertSee('Trade License 2026-00563');
        $res->assertSee('Thana Road, Pirganj 5470, Rangpur');
        $res->assertSee('staff-verified dealer and sub-dealer network');
        $res->assertSee('dependable mother company for dealers');
        $res->assertSee('Chaira, Moshorikhula, Hemayetpur, Savar, Dhaka');
        $res->assertSee('Dealer &amp; Sub-dealer Network', false);
        $res->assertSee('no public self-registration');
        $res->assertSee('product documentation for every SKU');
    }

    public function test_privacy_and_terms_pages_render_section_headings_and_cms_legal_meta(): void
    {
        $privacy = $this->get('/privacy');
        $privacy->assertOk();
        $privacy->assertSee('Privacy Policy');
        $privacy->assertSee('1. Who We Are');
        $privacy->assertSee('3. Partner Network &amp; Accounts', false);
        $privacy->assertSee('4. Payments &amp; Transaction IDs (TrxID)', false);
        $privacy->assertSee('10. Updates &amp; Contact', false);
        $privacy->assertSee('Effective: October 2026');
        $privacy->assertSee('Madina Natural Health Care · Operational policy', false);
        $privacy->assertSee('Pirganj, Rangpur, Bangladesh');
        $privacy->assertDontSee('কার্যকর সংস্করণ: অক্টোবর ২০২৬', false);

        $terms = $this->get('/terms');
        $terms->assertOk();
        $terms->assertSee('Terms of Use');
        $terms->assertSee('1. Agreement to These Terms');
        $terms->assertSee('3. Partner Network Accounts');
        $terms->assertSee('4. Orders, Payments &amp; TrxID Claims', false);
        $terms->assertSee('9. Contact');
        $terms->assertSee('Effective: October 2026');
        $terms->assertSee('Madina Natural Health Care · Operational policy', false);
    }

    public function test_home_page_presents_the_company_and_dealer_network_without_a_public_store(): void
    {
        $response = $this->get('/')->assertOk();
        $response->assertSee('Company &amp; Partner Network', false)->assertSee('About Our Company')->assertSee('Find a Dealer');
        foreach (['Natural Food, Medicine &amp; Wellness Product Shop', 'ref-home-about', 'Special Discounts', 'Add to Cart', 'Buy Now', 'data-open-cart', 'data-favorite', 'data-open-compare', 'ref-promotion-dialog', 'ref-rating-num'] as $retiredStoreElement) {
            $response->assertDontSee($retiredStoreElement, false);
        }
    }

    public function test_public_product_pages_use_mrp_and_dealer_enquiries_without_purchase_controls(): void
    {
        $product = Product::factory()->create(['name' => 'Public Information Product', 'mrp' => '233.00', 'dealer_price' => '116.50', 'featured' => true]);
        foreach (['/', '/products', '/products/'.$product->id] as $path) {
            $response = $this->get($path)->assertOk()->assertSee($product->name)->assertSee('MRP')->assertSee('Find a Dealer');
            foreach (['116.50', 'Add to Cart', 'Buy Now', 'data-cart=', 'data-buy-now=', 'data-quantity-step', 'ref-rating-num', '% OFF'] as $privateOrShoppingElement) {
                $response->assertDontSee($privateOrShoppingElement, false);
            }
        }
        $this->get('/products/'.$product->id)->assertSee('৳ 233.00', false)->assertDontSee('233.00 BDT')->assertSee('Dealer / Sub-dealer Login');
        $this->post('/commands/orders', ['items' => [['product_id' => $product->id, 'quantity' => 1]]])->assertRedirect('/login');
        $this->assertDatabaseCount('orders', 0);
    }

    public function test_signed_in_partners_order_from_their_dashboard_instead_of_public_product_pages(): void
    {
        $product = Product::factory()->create();
        foreach (['dealer', 'sub_dealer'] as $role) {
            $partner = User::factory()->create(['role' => $role]);
            $this->actingAs($partner)->get('/products/'.$product->id)->assertOk()
                ->assertSee('Open Partner Dashboard')->assertSee(PortalHost::url(PortalHost::forUser($partner), '/catalog'), false)->assertDontSee('data-cart=', false)->assertDontSee('Buy Now');
            $this->get('/workspace/catalog')->assertOk();
        }
        $this->assertDatabaseCount('orders', 0);
    }

    public function test_home_page_renders_dealer_network_banner_and_view_all_link(): void
    {
        $res = $this->get('/');
        $res->assertOk();
        $res->assertSee('Authorized Dealer Network');
        $res->assertSee('Find Nearest Dealer');
        $res->assertSee('/dealers');
        $res->assertSee('View All Products');
        $res->assertDontSee('Medical Equipment');
        $res->assertDontSee('Electronic Devices');
        $res->assertDontSee('Fashion &amp; Lifestyle', false);
    }

    public function test_legacy_apex_paths_redirect_to_the_portal_that_owns_them(): void
    {
        $this->onPortal('public')->get('/login')->assertOk()->assertSee('Dealer Login')->assertSee('Staff Login');
        $this->onPortal('public')->get('/workspace/orders?q=KEEP')->assertRedirect(PortalHost::url('dealer', '/orders').'?q=KEEP');
        $this->onPortal('public')->get('/company/catalog')->assertRedirect(PortalHost::url('manage', '/catalog'));
        $this->onPortal('public')->get('/owner/login')->assertRedirect(PortalHost::url('sadmin', '/login'));
    }

    public function test_outlets_url_redirects_to_dealers_directory(): void
    {
        $res = $this->get('/outlets');
        $res->assertRedirect('/dealers');
        $this->assertEquals(301, $res->getStatusCode());
    }

    public function test_public_notice_and_circular_pages_render_published_cms_content(): void
    {
        DB::table('content_pages')->insert([
            'slug' => 'notice',
            'title' => 'Ramadan training schedule',
            'body' => 'Partners should attend the Friday briefing in Pirganj.',
            'published' => true,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
        DB::table('content_pages')->insert([
            'slug' => 'circular-price',
            'title' => 'April price circular',
            'body' => 'Dealer purchase value schedule updates next week.',
            'published' => true,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $this->get('/notice')
            ->assertOk()
            ->assertSee('Ramadan training schedule')
            ->assertSee('Friday briefing in Pirganj');
        $this->get('/circular-price')
            ->assertOk()
            ->assertSee('April price circular')
            ->assertSee('Dealer purchase value schedule');
        $this->get('/circular-training')->assertOk();
        $this->get('/circular-meeting')->assertOk();
        $this->get('/')->assertOk()->assertSee('Company Notice')->assertSee('/notice', false);
    }
}
