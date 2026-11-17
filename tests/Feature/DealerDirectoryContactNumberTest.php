<?php

namespace Tests\Feature;

use App\Models\User;
use App\Support\SiteCustomization;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * The public dealer list never shows a dealer's or sub-dealer's own phone;
 * every card shows the one dealer number the owner sets in the website editor.
 */
class DealerDirectoryContactNumberTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->withoutVite();
        SiteCustomization::forgetCache();
    }

    private function listedPartners(): void
    {
        User::factory()->dealer()->create(['name' => 'Listed Dealer', 'phone' => '01711000001', 'directory_visible' => true]);
        User::factory()->subDealer()->create(['name' => 'Listed Sub Dealer', 'phone' => '01711000002', 'directory_visible' => true]);
    }

    public function test_the_list_shows_the_company_number_by_default_and_hides_partner_numbers(): void
    {
        $this->listedPartners();

        $this->get('/dealers')->assertOk()
            ->assertSee('Listed Dealer')
            ->assertSee('Listed Sub Dealer')
            ->assertSee('+8801730532966')
            ->assertSee('tel:+8801730532966', false)
            ->assertDontSee('01711000001')
            ->assertDontSee('01711000002');
    }

    public function test_one_edit_changes_the_number_on_every_dealer_and_sub_dealer(): void
    {
        $this->listedPartners();
        $editor = User::factory()->create(['role' => 'editor']);

        $this->actingAs($editor)->post('/commands/customization', ['customization' => ['dealer_contact_phone' => '01800-555555']])
            ->assertSessionHasNoErrors();
        auth()->logout();

        $page = $this->get('/dealers')->assertOk();
        $page->assertSee('01800-555555');
        $this->assertSame(2, substr_count($page->getContent(), 'tel:01800555555'));
        $page->assertDontSee('01711000001')->assertDontSee('01711000002');
    }

    public function test_a_blank_box_goes_back_to_the_built_in_company_number(): void
    {
        SiteCustomization::save(['dealer_contact_phone' => '01800555555']);
        $this->assertSame('01800555555', SiteCustomization::dealerContactPhone());

        SiteCustomization::save(['dealer_contact_phone' => '']);
        $this->assertSame('+8801730532966', SiteCustomization::dealerContactPhone());
    }

    public function test_the_website_editor_has_a_box_for_the_dealer_number(): void
    {
        $source = file_get_contents(resource_path('js/components/website/modules/InnerPageModules.tsx'));

        $this->assertStringContainsString('fieldKey="dealer_contact_phone"', $source);
        $this->assertSame('+8801730532966', SiteCustomization::defaults()['dealer_contact_phone']);
    }
}
