<?php

namespace Tests\Feature;

use App\Support\PortalHost;
use App\Support\SiteCustomization;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class PublicPartnerLoginTest extends TestCase
{
    use RefreshDatabase;

    public function test_public_login_shows_only_the_dealer_and_sub_dealer_options(): void
    {
        $this->onPortal('public')->get('/login')
            ->assertOk()
            ->assertSee('Partner Login')
            ->assertSee('Dealer Login')
            ->assertSee('Sub-dealer Login')
            ->assertSee(PortalHost::url('dealer', '/login'), false)
            ->assertSee(PortalHost::url('subdealer', '/login'), false)
            ->assertDontSee('Owner Login')
            ->assertDontSee('Staff Login')
            ->assertDontSee(PortalHost::url('sadmin', '/login'), false)
            ->assertDontSee(PortalHost::url('manage', '/login'), false)
            ->assertDontSee('Account Login')
            ->assertDontSee('There is no separate supplier sign-in');
    }

    public function test_dealer_login_page_names_only_the_dealer(): void
    {
        $this->onPortal('dealer')->get('/login')
            ->assertOk()
            ->assertSee('Dealer log in')
            ->assertSee('Email, Phone Number or Dealer Code')
            ->assertSee('Enter your email, phone number or dealer code')
            ->assertSee('Need a dealer account?')
            ->assertDontSee('Account Login')
            ->assertDontSee('Sub-dealer Code')
            ->assertDontSee('sub-dealer code');
    }

    public function test_sub_dealer_login_page_names_only_the_sub_dealer(): void
    {
        $this->onPortal('subdealer')->get('/login')
            ->assertOk()
            ->assertSee('Sub-dealer log in')
            ->assertSee('Email, Phone Number or Sub-dealer Code')
            ->assertSee('Enter your email, phone number or sub-dealer code')
            ->assertSee('Need a sub-dealer account?')
            ->assertDontSee('or Dealer Code')
            ->assertDontSee('Need a dealer account?');
    }

    public function test_bangla_login_pages_name_their_own_partner(): void
    {
        $this->onPortal('dealer')->from('/login')->post('/locale', ['locale' => 'bn'])->assertRedirect('/login');
        $this->onPortal('dealer')->get('/login')
            ->assertOk()
            ->assertSee('ডিলার লগইন')
            ->assertSee('ইমেইল, ফোন নম্বর বা ডিলার কোড')
            ->assertDontSee('সাব-ডিলার কোড')
            ->assertDontSee('অ্যাকাউন্ট লগইন');

        $this->onPortal('subdealer')->from('/login')->post('/locale', ['locale' => 'bn'])->assertRedirect('/login');
        $this->onPortal('subdealer')->get('/login')
            ->assertOk()
            ->assertSee('সাব-ডিলার লগইন')
            ->assertSee('ইমেইল, ফোন নম্বর বা সাব-ডিলার কোড')
            ->assertSee('সাব-ডিলার অ্যাকাউন্ট দরকার?')
            ->assertDontSee('বা ডিলার কোড');
    }

    public function test_a_blank_login_error_names_the_code_of_that_portal(): void
    {
        $response = $this->onPortal('subdealer')->from('/login')->post('/login', ['password' => 'unused', 'captcha' => 'unused']);

        $response->assertSessionHasErrors('email');
        $this->assertStringContainsString('Sub-dealer Code', (string) session('errors')->first('email'));
    }

    public function test_the_old_saved_title_gives_way_but_a_custom_title_stays(): void
    {
        $this->assertSame('Dealer log in', SiteCustomization::localizedCopy(['auth_login_title' => 'Account Login'], 'auth_login_title', 'auth_ui.dealer_sign_in'));
        $this->assertSame('Madina Partner Door', SiteCustomization::localizedCopy(['auth_login_title' => 'Madina Partner Door'], 'auth_login_title', 'auth_ui.dealer_sign_in'));
    }

    public function test_partner_login_alias_redirects_to_public_login_chooser(): void
    {
        $this->onPortal('public')->get('/partner-login')->assertRedirect('/login');
    }

    public function test_guest_can_switch_public_locale_to_bangla(): void
    {
        $this->onPortal('public')->from('/')->post('/locale', ['locale' => 'bn'])->assertRedirect('/');
        $this->onPortal('public')->get('/')
            ->assertOk()
            ->assertSee('lang="bn"', false)
            ->assertSee('পার্টনার লগইন')
            ->assertSee('is-active', false);
    }
}
