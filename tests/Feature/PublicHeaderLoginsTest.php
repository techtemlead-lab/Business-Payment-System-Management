<?php

namespace Tests\Feature;

use App\Models\User;
use App\Support\PortalHost;
use App\Support\SiteCustomization;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Client voice note, 2026-11-27: Dealer login and Sub-dealer login must be two separate links in the top line, the
 * menu and the phone bar (the phone "Account" tab only led to the dealer login).
 */
class PublicHeaderLoginsTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->withoutVite();
        SiteCustomization::reset();
    }

    public function test_signed_out_visitors_get_two_separate_login_links_in_the_top_line_the_menu_and_the_phone_bar(): void
    {
        $dealer = PortalHost::url('dealer', '/login');
        $subDealer = PortalHost::url('subdealer', '/login');

        foreach (['en', 'bn'] as $locale) {
            $html = $this->withCookie('locale', $locale)->get('/')->assertOk()->getContent();

            $this->assertSame(1, substr_count($html, '<a href="'.$dealer.'" class="ref-header-login">'));
            $this->assertSame(1, substr_count($html, '<a href="'.$subDealer.'" class="ref-header-login">'));
            $this->assertSame(1, substr_count($html, '<a href="'.$dealer.'" class="ref-drawer-btn ref-drawer-btn-primary">'));
            $this->assertSame(1, substr_count($html, '<a href="'.$subDealer.'" class="ref-drawer-btn ref-drawer-btn-primary">'));
            $this->assertSame(1, substr_count($html, '<a href="'.$dealer.'" class="ref-mobile-nav-item is-login">'));
            $this->assertSame(1, substr_count($html, '<a href="'.$subDealer.'" class="ref-mobile-nav-item is-login">'));
            $this->assertStringContainsString(__('site.subdealer_login', [], $locale), $html);
        }
    }

    public function test_the_top_line_order_is_home_products_dealer_login_sub_dealer_login(): void
    {
        $this->withCookie('locale', 'en')->get('/')->assertOk()->assertSeeInOrder([
            'class="ref-nav-home"',
            'class="ref-header-links"',
            'class="ref-shop"',
            'class="ref-header-login"',
            'class="ref-header-login"',
            'class="ref-locale-switch"',
        ], false);
    }

    public function test_signed_in_visitors_do_not_see_the_login_links_and_keep_sign_out(): void
    {
        $user = User::factory()->create();

        $html = $this->actingAs($user)->withCookie('locale', 'en')->get('/')->assertOk()->getContent();

        $this->assertStringNotContainsString('class="ref-header-login"', $html);
        $this->assertStringNotContainsString('is-login"', $html);
        $this->assertStringContainsString('ref-header-logout-btn', $html);
    }
}
