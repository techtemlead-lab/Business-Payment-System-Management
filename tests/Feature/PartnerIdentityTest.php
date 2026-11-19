<?php

namespace Tests\Feature;

use App\Domain\Accounts\PartnerAccountList;
use App\Domain\Accounts\PartnerAppointment;
use App\Domain\Accounts\PartnerDashboardHome;
use App\Domain\Accounts\PartnerProfileCard;
use App\Models\AreaDealerAssignment;
use App\Models\PlaceCode;
use App\Models\User;
use App\Support\TranslationBundle;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Request;
use Tests\TestCase;

/** V1: what a partner is appointed as and where, the dashboard boxes, the read-only profile and the sign-out button. */
class PartnerIdentityTest extends TestCase
{
    use RefreshDatabase;

    /** @return array{division: PlaceCode, district: PlaceCode, upazila: PlaceCode, union: PlaceCode, pourashava: PlaceCode} */
    private function places(): array
    {
        $division = PlaceCode::factory()->division()->create(['name' => 'Rangpur']);
        $district = PlaceCode::factory()->district($division)->create(['name' => 'Rangpur']);
        $upazila = PlaceCode::factory()->upazila($district)->create(['name' => 'Pirganj']);
        $union = PlaceCode::factory()->union($upazila)->create(['name' => 'Kabilpur']);
        $pourashava = PlaceCode::factory()->union($upazila)->create(['name' => 'Syedpur Pourashava']);

        return compact('division', 'district', 'upazila', 'union', 'pourashava');
    }

    private function dealerAt(PlaceCode $place, array $extra = []): User
    {
        return User::factory()->create(['role' => 'dealer', 'status' => 'active', 'profile' => ['place_code_id' => $place->id, 'place_code' => $place->code], ...$extra]);
    }

    public function test_each_level_reads_as_a_plain_bangla_sentence(): void
    {
        $places = $this->places();
        $expected = [
            'division' => 'রংপুর বিভাগের বিভাগীয় ডিলার',
            'district' => 'রংপুর জেলার জেলা ডিলার',
            'upazila' => 'পীরগঞ্জ উপজেলার থানা ডিলার',
        ];
        foreach ($expected as $level => $line) {
            $result = app(PartnerAppointment::class)->for($this->dealerAt($places[$level]), 'bn');
            $this->assertSame($line, $result['line'], $level);
            $this->assertSame($level, $result['kind']);
        }

        $union = app(PartnerAppointment::class)->for($this->dealerAt($places['union']), 'bn');
        $this->assertSame('union', $union['kind']);
        $this->assertStringEndsWith('ইউনিয়নের ইউনিয়ন ডিলার', $union['line']);

        $pourashava = app(PartnerAppointment::class)->for($this->dealerAt($places['pourashava']), 'bn');
        $this->assertSame('pourashava', $pourashava['kind']);
        $this->assertStringEndsWith('পৌরসভার পৌরসভা ডিলার', $pourashava['line']);
        $this->assertStringNotContainsString('Pourashava', $pourashava['line']);
    }

    public function test_the_same_levels_read_in_english(): void
    {
        $places = $this->places();

        $this->assertSame('Divisional dealer of Rangpur Division', app(PartnerAppointment::class)->for($this->dealerAt($places['division']), 'en')['line']);
        $this->assertSame('District dealer of Rangpur District', app(PartnerAppointment::class)->for($this->dealerAt($places['district']), 'en')['line']);
        $this->assertSame('Thana dealer of Pirganj Upazila', app(PartnerAppointment::class)->for($this->dealerAt($places['upazila']), 'en')['line']);
        $this->assertSame('Union dealer of Kabilpur Union', app(PartnerAppointment::class)->for($this->dealerAt($places['union']), 'en')['line']);
        $this->assertSame('Pourashava dealer of Syedpur Pourashava', app(PartnerAppointment::class)->for($this->dealerAt($places['pourashava']), 'en')['line']);
    }

    public function test_a_sub_dealer_is_the_sub_dealer_of_their_union_or_pourashava(): void
    {
        $places = $this->places();
        $sub = User::factory()->create(['role' => 'sub_dealer', 'status' => 'active', 'profile' => ['place_code_id' => $places['pourashava']->id]]);

        $bn = app(PartnerAppointment::class)->for($sub, 'bn');
        $this->assertSame('sub_dealer', $bn['kind']);
        $this->assertStringEndsWith('পৌরসভা-এর সাব-ডিলার', $bn['line']);
        $this->assertSame('Sub-dealer of Syedpur Pourashava', app(PartnerAppointment::class)->for($sub, 'en')['line']);
    }

    public function test_the_active_area_seat_decides_the_level_for_a_dealer(): void
    {
        $places = $this->places();
        $dealer = $this->dealerAt($places['upazila']);
        AreaDealerAssignment::query()->create(['user_id' => $dealer->id, 'level' => 'district', 'zone_name' => 'Rangpur', 'division' => 'Rangpur', 'district' => 'Rangpur', 'place_code_id' => $places['district']->id, 'monthly_quota' => 40000, 'is_active' => true, 'territory_key' => 'district:'.$places['district']->id]);

        $this->assertSame('district', app(PartnerAppointment::class)->for($dealer, 'bn')['kind']);
    }

    public function test_missing_data_falls_back_without_breaking(): void
    {
        $nobody = User::factory()->create(['role' => 'dealer', 'status' => 'active', 'profile' => []]);
        $this->assertSame(['kind' => null, 'area' => null, 'line' => null, 'short' => null], app(PartnerAppointment::class)->for($nobody, 'bn'));

        $placeholder = PlaceCode::factory()->district(PlaceCode::factory()->division()->create())->create(['name' => '—']);
        $result = app(PartnerAppointment::class)->for($this->dealerAt($placeholder), 'bn');
        $this->assertSame('জেলা ডিলার', $result['line']);
        $this->assertNull($result['area']);
    }

    public function test_the_list_gets_every_partners_line_with_a_fixed_number_of_queries(): void
    {
        $places = $this->places();
        $users = collect([$this->dealerAt($places['district']), $this->dealerAt($places['upazila']), User::factory()->create(['role' => 'sub_dealer', 'profile' => ['place_code_id' => $places['union']->id]])]);

        $many = app(PartnerAppointment::class)->forMany($users, 'en');

        $this->assertSame('District dealer of Rangpur District', $many[$users[0]->id]['line']);
        $this->assertSame('Thana dealer of Pirganj Upazila', $many[$users[1]->id]['line']);
        $this->assertSame('Sub-dealer of Kabilpur Union', $many[$users[2]->id]['line']);
    }

    public function test_the_office_list_carries_level_and_area_for_each_row(): void
    {
        app()->setLocale('en');
        $places = $this->places();
        $this->dealerAt($places['district']);

        $table = app(PartnerAccountList::class)->table(Request::create('/accounts'), 'dealer', PartnerAccountList::filters(Request::create('/accounts')));

        $this->assertSame('District dealer of Rangpur District', $table['rows']->first()->appointment['line']);
    }

    public function test_the_dashboard_card_keeps_the_appointed_area_apart_from_the_home_address(): void
    {
        $places = $this->places();
        $dealer = $this->dealerAt($places['district'], ['phone' => '01712345678', 'dealer_code' => $places['district']->code, 'division' => 'Dhaka', 'district' => 'Gazipur']);

        $home = app(PartnerDashboardHome::class)->for($dealer);

        $this->assertSame('District dealer of Rangpur District', $home['card']['appointment']['line']);
        $this->assertStringContainsString('Gazipur', (string) $home['card']['area']);
        $this->assertSame($dealer->dealer_code, $home['card']['code']);
        $this->assertSame('dealer', $home['card']['rank']);
        $this->assertArrayHasKey('month_purchase', $home['card']);
        $this->assertSame($home['targets'][0]['remaining'], $home['card']['target_left']);
    }

    public function test_a_sub_dealer_has_no_rank_or_target_box(): void
    {
        $sub = User::factory()->create(['role' => 'sub_dealer', 'status' => 'active', 'profile' => []]);

        $card = app(PartnerDashboardHome::class)->for($sub)['card'];

        $this->assertNull($card['rank']);
        $this->assertNull($card['target_left']);
        $this->assertSame('0.00', $card['month_purchase']);
    }

    public function test_the_profile_shows_the_biodata_masked_and_read_only(): void
    {
        $places = $this->places();
        $dealer = $this->dealerAt($places['upazila'], [
            'nid' => '1234567890123',
            'profile' => ['place_code_id' => $places['upazila']->id, 'father_name' => 'Abdul Karim', 'mother_name' => 'Rahima Begum', 'date_of_birth' => '1980-05-17', 'village' => 'Nayabari', 'post_office' => 'Pirganj', 'upazila' => 'Pirganj', 'union' => 'Kabilpur'],
        ]);

        $card = app(PartnerProfileCard::class)->for($dealer);

        $this->assertSame('Abdul Karim', $card['biodata']['father_name']);
        $this->assertSame('Rahima Begum', $card['biodata']['mother_name']);
        $this->assertSame('17/05/1980', $card['biodata']['date_of_birth']);
        $this->assertStringEndsWith('0123', (string) $card['biodata']['nid']);
        $this->assertStringNotContainsString('1234567', (string) $card['biodata']['nid']);
        $this->assertStringContainsString('Nayabari', (string) $card['biodata']['full_address']);
        $this->assertNotNull($card['appointment']['line']);
    }

    public function test_the_profile_page_has_no_form_that_changes_the_code_or_the_area(): void
    {
        $page = (string) file_get_contents(resource_path('js/components/PartnerProfileCard.tsx'));

        foreach (['dealer_code', 'place_code', 'area_code', 'place_code_id'] as $locked) {
            $this->assertStringNotContainsString("name:'{$locked}'", $page);
        }
        $this->assertStringContainsString('profile-biodata', $page);
    }

    public function test_the_partner_profile_page_answers_for_a_dealer(): void
    {
        $this->withoutVite();
        $dealer = $this->dealerAt($this->places()['district'], ['nid' => '9876543210']);

        $this->actingAs($dealer)->onPortal('dealer')->get('/profile')
            ->assertOk()
            ->assertInertia(fn ($page) => $page
                ->where('data.profile.partner_card.appointment.kind', 'district')
                ->has('data.profile.partner_card.biodata.nid'));
    }

    public function test_the_sign_out_button_is_in_the_partner_top_bar_and_the_phone_menu_only(): void
    {
        $shell = (string) file_get_contents(resource_path('js/components/Shell.tsx'));

        $this->assertStringContainsString('{!staff && <PartnerSignOut variant="bar" />}', $shell);
        $this->assertStringContainsString('{!staff && <PartnerSignOut variant="drawer" />}', $shell);
        $button = (string) file_get_contents(resource_path('js/components/PartnerSignOut.tsx'));
        $this->assertStringContainsString("router.post('/logout')", $button);
        $this->assertStringContainsString('sign_out_ask', $button);
    }

    public function test_the_partner_sidebar_order_is_home_profile_account_then_orders(): void
    {
        $menu = (string) file_get_contents(resource_path('js/shared/portalNav.ts'));

        $this->assertMatchesRegularExpression("/function partnerMothers[\s\S]*?'home'[\s\S]*?rowMother\('partner-account'[\s\S]*?mother\('my-income', 'nav_module_account'[\s\S]*?mother\('new-order'[\s\S]*?rowMother\('partner-orders'[\s\S]*?'partner-money'/", $menu);
        $this->assertStringContainsString("hrefPath: 'my-income'", $menu);
        $this->assertSame('আমার প্রোফাইল', __('portal.nav_my_profile', [], 'bn'));
        $this->assertSame('আমার অ্যাকাউন্ট', __('portal.nav_module_account', [], 'bn'));
    }

    public function test_every_new_key_exists_in_both_languages_and_in_the_bundle(): void
    {
        $bn = require lang_path('bn/cr21_identity.php');
        $en = require lang_path('en/cr21_identity.php');

        $this->assertSame(array_keys($bn), array_keys($en));
        $this->assertArrayHasKey('cr21_identity', TranslationBundle::for('bn'));
        $this->assertSame('দায়িত্বের এলাকা', TranslationBundle::for('bn')['cr21_identity']['label_area']);
        $this->assertSame('ঠিকানা', $bn['label_address']);
    }
}
