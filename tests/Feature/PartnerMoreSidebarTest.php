<?php

namespace Tests\Feature;

use App\Domain\Accounts\BanglaPlaceNames;
use App\Domain\Accounts\PartnerAreaLine;
use App\Models\User;
use App\Support\PartnerHubTabs;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\TestCase;

/** CR-14: the lean dealer and sub-dealer sidebar with one More page, the Bangla area line and the Bangla "page not found". */
class PartnerMoreSidebarTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->withoutVite();
    }

    /** @return list<string> */
    private function moreKeys(User $user): array
    {
        return collect(PartnerHubTabs::moreGroups($user))->flatMap(fn ($group) => collect($group['links'])->pluck('key'))->all();
    }

    public function test_the_dealer_and_sub_dealer_more_page_lists_boxes_with_a_line_each(): void
    {
        foreach ([['dealer', 'dealer'], ['sub_dealer', 'subdealer']] as [$role, $portal]) {
            $partner = User::factory()->create(['role' => $role, 'status' => 'active']);

            $this->actingAs($partner)->onPortal($portal)->get('/more')->assertOk()
                ->assertInertia(fn (Assert $page) => $page
                    ->where('section', 'more')
                    ->where('data.more_groups', function ($groups): bool {
                        $keys = collect($groups)->flatMap(fn ($group) => collect($group['links'])->pluck('key'))->all();
                        $text = collect($groups)->flatMap(fn ($group) => collect($group['links'])->map(fn ($link) => $link['label'].$link['description']))->all();

                        // Best sellers, contests and the contract each have one home elsewhere (findability #18).
                        return in_array('share-feedback', $keys, true) && array_intersect(['agreement', 'top-sellers', 'contests'], $keys) === []
                            && collect($text)->every(fn ($line) => $line !== '' && ! str_contains($line, 'office_menu.') && ! str_contains($line, 'cr14.'));
                    }));
        }
    }

    public function test_every_more_box_has_a_name_and_a_line_in_both_languages(): void
    {
        $dealer = User::factory()->create(['role' => 'dealer', 'status' => 'active']);

        foreach (['bn', 'en'] as $locale) {
            app()->setLocale($locale);
            $groups = PartnerHubTabs::moreGroups($dealer);
            $this->assertNotEmpty($groups);
            foreach ($groups as $group) {
                $this->assertStringNotContainsString('cr14.', $group['label']);
                foreach ($group['links'] as $link) {
                    $this->assertStringNotContainsString('office_menu.', $link['label'], $link['key']);
                    $this->assertStringNotContainsString('cr14.', $link['description'], $link['key'].' '.$locale);
                }
            }
        }
    }

    public function test_prescriptions_never_appear_on_the_partner_more_page_and_stay_in_the_sidebar(): void
    {
        $dealer = User::factory()->create(['role' => 'dealer', 'status' => 'active']);

        $this->assertNotContains('prescriptions', $this->moreKeys($dealer));
        $menu = (string) file_get_contents(resource_path('js/shared/portalNav.ts'));
        $this->assertMatchesRegularExpression("/function partnerMothers[\s\S]*?mother\('clinic', 'prescriptions'/", $menu);
        $this->assertDoesNotMatchRegularExpression("/id: 'partner-more'[^\n]*prescriptions/", $menu);
        $this->assertStringContainsString("'prescriptions'", (string) file_get_contents(base_path('config/portals.php')));
    }

    public function test_the_moved_pages_keep_their_own_addresses_and_stay_open(): void
    {
        $dealer = User::factory()->create(['role' => 'dealer', 'status' => 'active']);
        $subDealer = User::factory()->create(['role' => 'sub_dealer', 'status' => 'active']);

        $this->actingAs($dealer)->onPortal('dealer')->get('/agreement')->assertOk();
        $this->actingAs($dealer)->onPortal('dealer')->get('/share-feedback')->assertOk();
        $this->actingAs($subDealer)->onPortal('subdealer')->get('/customers')->assertOk();
        $this->assertNotContains('network', $this->moreKeys($dealer));
    }

    public function test_the_sidebar_rows_hold_the_everyday_pages_and_more_holds_the_rest(): void
    {
        $menu = (string) file_get_contents(resource_path('js/shared/portalNav.ts'));

        $this->assertMatchesRegularExpression("/id: 'partner-team'[^\n]*tabs: \['network', 'customers', 'ranks', 'top-sellers', 'contests'\]/", $menu);
        $this->assertMatchesRegularExpression("/id: 'partner-office'[^\n]*tabs: \['messages', 'partner-notices', 'help'\]/", $menu);
        $this->assertMatchesRegularExpression("/id: 'partner-account'[^\n]*tabs: \['profile', 'security', 'agreement'\]/", $menu);
        $this->assertMatchesRegularExpression("/id: 'partner-more'[^\n]*home: 'more'[^\n]*'training'[^\n]*'events'[^\n]*'sales-kit'[^\n]*'share-feedback'\]/", $menu);
        $this->assertStringNotContainsString("rowMother('partner-learning'", $menu);
    }

    public function test_an_unknown_page_on_a_partner_host_is_not_found(): void
    {
        $dealer = User::factory()->create(['role' => 'dealer', 'status' => 'active']);

        $this->actingAs($dealer)->onPortal('dealer')->get('/products')->assertNotFound();
    }

    public function test_bangla_place_names_match_forgivingly(): void
    {
        $this->assertSame(['division' => 'রংপুর', 'district' => 'রংপুর', 'upazila' => 'পীরগঞ্জ', 'union' => null],
            BanglaPlaceNames::translate(['division' => 'Rangpur', 'district' => 'rangpur', 'upazila' => 'Pirgonj', 'union' => null]));
        $this->assertSame('পীরগঞ্জ', BanglaPlaceNames::translate(['division' => 'Rangpur', 'district' => 'Rangpur', 'upazila' => 'Pirganj'])['upazila']);
        $this->assertSame('পীরগঞ্জ', BanglaPlaceNames::translate(['division' => ' RANGPUR ', 'district' => 'Rangpur', 'upazila' => 'pir-ganj'])['upazila']);
        $this->assertSame('চট্টগ্রাম', BanglaPlaceNames::translate(['division' => 'Chittagong'])['division']);
        $this->assertSame('চট্টগ্রাম', BanglaPlaceNames::translate(['division' => 'Chattogram'])['division']);
        $this->assertSame('বরিশাল', BanglaPlaceNames::translate(['division' => 'Barishal'])['division']);
    }

    public function test_an_unknown_name_keeps_the_saved_text_and_a_bangla_name_is_left_alone(): void
    {
        $result = BanglaPlaceNames::translate(['division' => 'Rangpur', 'district' => 'Nowhereville', 'upazila' => 'রংপুর সদর', 'union' => 'Somewhere']);

        $this->assertSame('রংপুর', $result['division']);
        $this->assertSame('Nowhereville', $result['district']);
        $this->assertSame('রংপুর সদর', $result['upazila']);
        $this->assertSame('Somewhere', $result['union']);
        $this->assertNull(BanglaPlaceNames::translate([])['division']);
    }

    /** R1: a Pourashava or a city corporation is not in the geo union list; the place-code data names it. */
    public function test_pourashava_and_city_corporation_names_are_bangla(): void
    {
        foreach (['Saidpur Pourashava', 'Saidpur Paurashava', 'Saidpur Municipality', 'saidpur pourashava'] as $spelling) {
            $this->assertSame('সৈয়দপুর পৌরসভা', BanglaPlaceNames::translate(['division' => 'Rangpur', 'district' => 'Nilphamari', 'upazila' => 'Saidpur', 'union' => $spelling])['union'], $spelling);
        }
        $this->assertSame('সৈয়দপুর পৌরসভা', BanglaPlaceNames::translate(['union' => 'Saidpur Pourashava'])['union']);
        $this->assertSame('রংপুর সিটি কর্পোরেশন', BanglaPlaceNames::translate(['union' => 'Rangpur City Corporation'])['union']);
        $this->assertSame('ঢাকা মহানগর', BanglaPlaceNames::translate(['division' => 'Dhaka', 'district' => 'Dhaka', 'upazila' => 'Dhaka Metro'])['upazila']);
        $this->assertSame('Nowhere Pourashava', BanglaPlaceNames::translate(['upazila' => 'Nowhere', 'union' => 'Nowhere Pourashava'])['union']);
    }

    public function test_a_place_is_looked_up_inside_its_own_district(): void
    {
        $inRangpur = BanglaPlaceNames::translate(['division' => 'Rangpur', 'district' => 'Rangpur', 'upazila' => 'Rangpur Sadar']);
        $inDhaka = BanglaPlaceNames::translate(['division' => 'Dhaka', 'district' => 'Dhaka', 'upazila' => 'Rangpur Sadar']);

        $this->assertNotSame('Rangpur Sadar', $inRangpur['upazila']);
        $this->assertSame('Rangpur Sadar', $inDhaka['upazila']);
    }

    public function test_the_area_line_is_bangla_on_a_bangla_screen_and_english_on_an_english_one(): void
    {
        $dealer = User::factory()->create(['role' => 'dealer', 'status' => 'active', 'division' => 'Rangpur', 'district' => 'Rangpur']);

        app()->setLocale('bn');
        $this->assertSame('রংপুর', app(PartnerAreaLine::class)->for($dealer));
        app()->setLocale('en');
        $this->assertSame('Rangpur', app(PartnerAreaLine::class)->for($dealer));
    }

    public function test_the_not_found_page_on_a_partner_host_is_bangla_unless_english_was_chosen(): void
    {
        config(['madina.guest_locale' => 'bn']);

        $this->onPortal('dealer')->get('/products')->assertNotFound()
            ->assertSee(__('site.error_404_heading', [], 'bn'), false);

        $this->onPortal('dealer')->withUnencryptedCookie('locale', 'en')->get('/products')->assertNotFound()
            ->assertSee(__('site.error_404_heading', [], 'en'), false);
    }
}
