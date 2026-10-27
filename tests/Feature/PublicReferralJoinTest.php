<?php

namespace Tests\Feature;

use App\Models\Product;
use App\Models\User;
use App\Support\PortalHost;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class PublicReferralJoinTest extends TestCase
{
    use RefreshDatabase;

    public function test_join_link_shows_partner_landing_with_open_graph_and_contact_prefill(): void
    {
        $dealer = User::factory()->dealer()->create([
            'status' => 'active',
            'name' => 'Refer Partner',
            'refer_code' => 'REF-PARTNER-01',
            'dealer_code' => 'REF-PARTNER-01',
            'phone' => '01710000011',
            'profile' => [
                'photo_url' => '/storage/partner-photos/1/demo.jpg',
                'avatar_url' => '/storage/partner-photos/1/demo.jpg',
            ],
        ]);

        $this->onPortal('public')
            ->get('/join/ref-partner-01')
            ->assertOk()
            ->assertSee('Refer Partner', false)
            ->assertSee('REF-PARTNER-01', false)
            ->assertSee('og:title', false)
            ->assertSee('og:image', false)
            ->assertSee('/contact?referred_by=REF-PARTNER-01', false)
            ->assertSee((string) $dealer->phone, false);
    }

    public function test_contact_page_prefills_referred_by_from_query(): void
    {
        $this->onPortal('public')
            ->get('/contact?referred_by=REF-ABC&topic=partnership_enquiry')
            ->assertOk()
            ->assertSee('name="referred_by"', false)
            ->assertSee('value="REF-ABC"', false);
    }

    public function test_enquiry_stores_referred_by_code(): void
    {
        $this->onPortal('public')->post('/enquiries', [
            'name' => 'Curious Buyer',
            'phone' => '01719998877',
            'topic' => 'partnership_enquiry',
            'referred_by' => 'ref-home-88',
            'message' => 'I want to join after seeing a referral link shared on Facebook.',
            'website' => '',
        ])->assertRedirect();

        $this->assertDatabaseHas('enquiries', [
            'name' => 'Curious Buyer',
            'referred_by' => 'REF-HOME-88',
            'topic' => 'partnership_enquiry',
        ]);
    }

    public function test_partnership_page_prefills_sponsor_code_from_query(): void
    {
        $this->onPortal('public')
            ->get('/partnership?sponsor_code=REF-ABC')
            ->assertOk()
            ->assertSee('name="sponsor_code"', false)
            ->assertSee('value="REF-ABC"', false);
    }

    public function test_partner_home_exposes_public_referral_url(): void
    {
        $dealer = User::factory()->create([
            'role' => 'dealer',
            'status' => 'active',
            'refer_code' => 'REF-HOME-88',
        ]);

        $this->actingAs($dealer)->onPortal('dealer')
            ->get('/')
            ->assertOk()
            ->assertInertia(fn ($page) => $page
                ->component('portals/dealer/Dashboard')
                ->where('data.partner_home.referral.code', 'REF-HOME-88')
                ->where('data.partner_home.referral.url', PortalHost::url('public', '/join/REF-HOME-88')));
    }

    public function test_homepage_includes_open_graph_company_meta(): void
    {
        $hero = PortalHost::url('public', '/branding/madina-hero-banner-1.jpg').'?v=og-20261027';

        $this->onPortal('public')
            ->get('/')
            ->assertOk()
            ->assertSee('og:title', false)
            ->assertSee('og:description', false)
            ->assertSee('twitter:card', false)
            ->assertSee('property="og:url" content="'.PortalHost::url('public', '/').'"', false)
            ->assertSee('property="og:image" content="'.$hero.'"', false)
            ->assertSee('property="og:image:secure_url" content="'.$hero.'"', false)
            ->assertSee('property="og:image:type" content="image/jpeg"', false)
            ->assertSee('property="og:image:width" content="1376"', false)
            ->assertSee('property="og:image:height" content="768"', false);
    }

    public function test_products_index_and_detail_expose_https_ready_open_graph_cards(): void
    {
        config(['app.url' => 'https://'.PortalHost::domain()]);

        $product = Product::factory()->create([
            'active' => true,
            'name' => 'OG Face Wash',
            'description' => 'Shareable wellness product for social cards.',
        ]);

        $this->onPortal('public')
            ->get('/products')
            ->assertOk()
            ->assertSee('property="og:url" content="'.PortalHost::url('public', '/products').'"', false)
            ->assertSee('og:title', false);

        $this->onPortal('public')
            ->get('/products/'.$product->id)
            ->assertOk()
            ->assertSee('property="og:type" content="product"', false)
            ->assertSee('OG Face Wash', false)
            ->assertSee('property="og:url" content="'.PortalHost::url('public', '/products/'.$product->id).'"', false)
            ->assertSee('facebook.com/dialog/send', false)
            ->assertSee('sms:?&body=', false)
            ->assertSee('mailto:?subject=', false);
    }
}
