<?php

namespace Tests\Feature;

use App\Models\User;
use App\Support\PortalHost;
use App\Support\SiteCustomization;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Client voice note, 2026-11-27: Dealer login and Sub-dealer login must be two separate links in the top line and the
 * menu. Voice notes of 2026-11-28: on a phone they sit with Products in big buttons under the Home line (the bottom
 * bar was too crowded), and a signed-in visitor gets Sign out there too.
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
            $this->assertSame(1, substr_count($html, '<a href="'.$dealer.'" class="ref-quick-link is-login">'));
            $this->assertSame(1, substr_count($html, '<a href="'.$subDealer.'" class="ref-quick-link is-login">'));
            // The bottom bar keeps one Login tab that opens the dealer / sub-dealer choice.
            $this->assertSame(1, substr_count($html, 'class="ref-mobile-nav-item is-login"'));
            $this->assertStringNotContainsString('<a href="'.$dealer.'" class="ref-mobile-nav-item', $html);
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
        $this->assertStringContainsString('class="ref-quick-link is-logout"', $html);
        $this->assertStringContainsString('class="ref-quick-link is-account"', $html);
    }

    /**
     * Regression (live, 2026-11-28): `class="ref-mobile-nav@guest ...@endguest"` was never compiled by Blade (a directive
     * glued to a word is plain text), so the bottom bar lost its class and showed unstyled under the footer.
     */
    public function test_the_bottom_bar_keeps_its_class_and_no_blade_word_leaks_into_the_page(): void
    {
        foreach ([null, User::factory()->create()] as $user) {
            $request = $user !== null ? $this->actingAs($user) : $this;
            $html = $request->withCookie('locale', 'bn')->get('/')->assertOk()->getContent();

            $this->assertStringContainsString('<nav class="ref-mobile-nav" aria-label=', $html);
            foreach (['@guest', '@endguest', '@auth', '@endauth', '@endif'] as $directive) {
                $this->assertStringNotContainsString($directive, $html);
            }
        }
    }

    public function test_the_phone_quick_row_follows_the_home_line_with_products_first(): void
    {
        $this->withCookie('locale', 'bn')->get('/')->assertOk()->assertSeeInOrder([
            'class="ref-nav-home"',
            'class="ref-quick-row"',
            'class="ref-quick-link is-products"',
            'ডিলার লগইন',
            'সাব-ডিলার লগইন',
        ], false);
    }
}
