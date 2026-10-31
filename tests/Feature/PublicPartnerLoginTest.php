<?php

namespace Tests\Feature;

use App\Support\PortalHost;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class PublicPartnerLoginTest extends TestCase
{
    use RefreshDatabase;

    public function test_public_login_shows_dealer_subdealer_and_staff_options(): void
    {
        $this->onPortal('public')->get('/login')
            ->assertOk()
            ->assertSee('Partner Login')
            ->assertSee('Owner Login')
            ->assertSee('Dealer Login')
            ->assertSee('Sub-dealer Login')
            ->assertSee('Staff Login')
            ->assertSee(PortalHost::url('sadmin', '/login'), false)
            ->assertSee(PortalHost::url('dealer', '/login'), false)
            ->assertSee(PortalHost::url('subdealer', '/login'), false)
            ->assertSee(PortalHost::url('manage', '/login'), false)
            ->assertDontSee('Account Login')
            ->assertDontSee('There is no separate supplier sign-in');
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
