<?php

namespace Tests\Feature;

use App\Models\User;
use App\Support\SiteCustomization;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\TestCase;

/**
 * The help box in the dealer and sub-dealer side menu: its words and the
 * office phone come from the website editor.
 */
class PartnerHelpBoxCmsTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->withoutVite();
    }

    public function test_dealer_menu_help_box_shows_factory_words_and_the_office_phone(): void
    {
        $dealer = User::factory()->create(['role' => 'dealer', 'status' => 'active', 'locale' => 'en']);

        $this->actingAs($dealer)->onPortal('dealer')->get('/')
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->where('siteCustomization.partner_help_title', __('site.partner_help_title', [], 'en'))
                ->where('siteCustomization.partner_help_body', __('site.partner_help_body', [], 'en'))
                ->where('siteCustomization.partner_help_phone', SiteCustomization::defaults()['contact_phone'])
                ->has('siteCustomization.header_offers_title'));
    }

    public function test_bangla_partner_sees_bangla_factory_words(): void
    {
        $subDealer = User::factory()->create(['role' => 'sub_dealer', 'status' => 'active', 'locale' => 'bn']);

        $this->actingAs($subDealer)->withSession(['locale' => 'bn'])->onPortal('subdealer')->get('/')
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->where('siteCustomization.partner_help_title', __('site.partner_help_title', [], 'bn'))
                ->where('siteCustomization.partner_help_body', __('site.partner_help_body', [], 'bn')));
    }

    public function test_owner_edits_and_the_contact_phone_reach_the_partner_menu(): void
    {
        SiteCustomization::save([
            'partner_help_title' => 'Need stock fast?',
            'partner_help_body' => 'Our office answers 9am to 6pm.',
            'contact_phone' => '01900-111111',
        ]);
        $dealer = User::factory()->create(['role' => 'dealer', 'status' => 'active', 'locale' => 'bn']);

        $this->actingAs($dealer)->withSession(['locale' => 'bn'])->onPortal('dealer')->get('/')
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->where('siteCustomization.partner_help_title', 'Need stock fast?')
                ->where('siteCustomization.partner_help_body', 'Our office answers 9am to 6pm.')
                ->where('siteCustomization.partner_help_phone', '01900-111111'));
    }

    public function test_staff_panels_do_not_receive_the_partner_help_box(): void
    {
        $editor = User::factory()->staff('editor')->create();

        $this->actingAs($editor)->onPortal('manage')->get('/website')
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page->where('siteCustomization', []));
    }
}
