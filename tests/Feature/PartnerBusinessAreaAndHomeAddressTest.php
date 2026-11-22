<?php

namespace Tests\Feature;

use App\Domain\Accounts\PartnerAccountProvisioning;
use App\Domain\Accounts\PartnerProfileCard;
use App\Domain\Accounts\PartnerRegistrationForm;
use App\Models\AreaDealerAssignment;
use App\Models\PlaceCode;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Notification;
use Inertia\Testing\AssertableInertia as Assert;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

/**
 * Client, 2026-11-21: the add-partner form keeps the area that sets the code as the BUSINESS
 * AREA (a dealer picks only down to the level of his kind of dealer) and records a separate
 * PERMANENT ADDRESS, where the person lives, which never touches the code or the area.
 */
class PartnerBusinessAreaAndHomeAddressTest extends TestCase
{
    use RefreshDatabase;

    /** @var array<string, PlaceCode> */
    private array $business = [];

    /** @var array<string, PlaceCode> */
    private array $home = [];

    protected function setUp(): void
    {
        parent::setUp();
        $this->withoutVite();
        Notification::fake();
        $this->seedClassicPlaceCodes();

        $rangpur = PlaceCode::query()->where('code', 'MNHC102')->firstOrFail();
        $district = PlaceCode::factory()->district($rangpur)->create(['code' => 'MNHC302', 'name' => 'Rangpur District']);
        $upazila = PlaceCode::factory()->upazila($district)->create(['code' => 'MNHC402', 'name' => 'Pirganj']);
        $union = PlaceCode::factory()->union($upazila)->create(['code' => 'MNHC502', 'name' => 'Kabilpur']);
        $this->business = ['division' => $rangpur, 'district' => $district, 'upazila' => $upazila, 'union' => $union];

        $dhaka = PlaceCode::query()->where('code', 'MNHC101')->firstOrFail();
        $gazipur = PlaceCode::factory()->district($dhaka)->create(['code' => 'MNHC301', 'name' => 'Gazipur']);
        $kaliakair = PlaceCode::factory()->upazila($gazipur)->create(['code' => 'MNHC401', 'name' => 'Kaliakair']);
        $chapair = PlaceCode::factory()->union($kaliakair)->create(['code' => 'MNHC501', 'name' => 'Chapair']);
        $this->home = ['division' => $dhaka, 'district' => $gazipur, 'upazila' => $kaliakair, 'union' => $chapair];
    }

    /** @return array<string, array{string}> */
    public static function dealerKinds(): array
    {
        return [
            'division dealer' => ['division'],
            'district dealer' => ['district'],
            'upazila dealer' => ['upazila'],
            'union dealer' => ['union'],
        ];
    }

    #[DataProvider('dealerKinds')]
    public function test_each_kind_of_dealer_is_seated_on_a_place_of_its_own_level(string $kind): void
    {
        $place = $this->business[$kind];

        $this->asOwner()->post('/commands/accounts', $this->payload([
            'dealer_level' => $kind,
            'place_code' => $place->code,
            'permanent_place_id' => $this->home['union']->id,
        ]))->assertRedirect('/accounts')->assertSessionHasNoErrors();

        $dealer = $this->created();
        $this->assertSame($place->code, $dealer->dealer_code);
        $this->assertSame($place->code, $dealer->profile['place_code']);
        $seat = AreaDealerAssignment::query()->where('user_id', $dealer->id)->where('is_active', true)->sole();
        $this->assertSame($kind, $seat->level);
        $this->assertSame($place->id, $seat->place_code_id);
    }

    public function test_a_place_of_another_level_than_the_kind_of_dealer_is_refused(): void
    {
        $this->asOwner()->post('/commands/accounts', $this->payload([
            'dealer_level' => 'district',
            'place_code' => $this->business['upazila']->code,
        ]))->assertSessionHasErrors(['place_code' => __('portal.acct_val_place_not_dealer_level', ['kind' => __('portal.acct_dealer_kind_district')])]);

        $this->assertNull(User::query()->where('email', 'new.partner@example.test')->first());
        $this->assertSame(0, AreaDealerAssignment::query()->count());
    }

    public function test_an_unknown_kind_of_dealer_is_refused(): void
    {
        $this->asOwner()->post('/commands/accounts', $this->payload([
            'dealer_level' => 'management',
            'place_code' => $this->business['division']->code,
        ]))->assertSessionHasErrors('dealer_level');

        $this->assertNull(User::query()->where('email', 'new.partner@example.test')->first());
    }

    public function test_one_dealer_per_place_still_holds_with_a_kind_of_dealer(): void
    {
        $this->asOwner()->post('/commands/accounts', $this->payload([
            'dealer_level' => 'upazila',
            'place_code' => $this->business['upazila']->code,
        ]))->assertSessionHasNoErrors();

        $this->asOwner()->post('/commands/accounts', $this->payload([
            'dealer_level' => 'upazila',
            'place_code' => $this->business['upazila']->code,
            'email' => 'second.partner@example.test',
            'phone' => '01812345679',
            'birth_certificate_number' => '19911234567890112',
        ]))->assertSessionHasErrors(['place_code' => __('portal.acct_val_place_taken')]);
    }

    public function test_a_sub_dealer_takes_a_union_and_gets_the_permanent_address(): void
    {
        $this->asOwner()->post('/commands/accounts', $this->payload([
            'role' => 'sub_dealer',
            'place_code' => $this->business['union']->code,
            'permanent_place_id' => $this->home['upazila']->id,
        ]))->assertRedirect('/sub-dealers')->assertSessionHasNoErrors();

        $sub = $this->created();
        $this->assertSame('MNHC502-000001', $sub->dealer_code);
        $this->assertSame('Kabilpur', $sub->profile['union']);
        $this->assertSame('Kaliakair', $sub->profile['permanent_upazila']);
        $this->assertSame('', $sub->profile['permanent_union']);
    }

    public function test_the_permanent_address_is_saved_apart_from_the_business_area_and_kept_secret(): void
    {
        $this->asOwner()->post('/commands/accounts', $this->payload([
            'dealer_level' => 'upazila',
            'place_code' => $this->business['upazila']->code,
            'permanent_place_id' => $this->home['union']->id,
            'village' => 'Ratanpur, House 12',
            'post_office' => 'Kaliakair-1750',
        ]))->assertSessionHasNoErrors();

        $dealer = $this->created();
        // The business area still comes from the place code alone.
        $this->assertSame('Rangpur', $dealer->division);
        $this->assertSame('Rangpur District', $dealer->district);
        $this->assertSame('Pirganj', $dealer->profile['upazila']);
        // The home is somewhere else and is kept beside it.
        $this->assertSame('Dhaka', $dealer->profile['permanent_division']);
        $this->assertSame('Gazipur', $dealer->profile['permanent_district']);
        $this->assertSame('Kaliakair', $dealer->profile['permanent_upazila']);
        $this->assertSame('Chapair', $dealer->profile['permanent_union']);
        $this->assertSame((string) $this->home['union']->id, $dealer->profile['permanent_place_id']);
        $this->assertSame('Ratanpur, House 12', $dealer->profile['village']);

        $stored = json_decode((string) DB::table('users')->where('id', $dealer->id)->value('profile'), true);
        foreach (['permanent_division', 'permanent_district', 'permanent_upazila', 'permanent_union', 'permanent_place_id'] as $key) {
            $this->assertStringStartsWith('enc:', (string) $stored[$key], $key.' is kept encrypted at rest');
        }
    }

    public function test_a_home_on_an_upazila_never_takes_the_base_union_of_that_upazila(): void
    {
        $this->home['upazila']->forceFill(['base_place_id' => $this->home['union']->id])->save();

        $this->asOwner()->post('/commands/accounts', $this->payload([
            'dealer_level' => 'division',
            'place_code' => $this->business['division']->code,
            'permanent_place_id' => $this->home['upazila']->id,
        ]))->assertSessionHasNoErrors();

        $profile = $this->created()->profile;
        $this->assertSame('Kaliakair', $profile['permanent_upazila']);
        $this->assertSame('', $profile['permanent_union']);
    }

    public function test_a_permanent_address_that_stops_above_the_upazila_is_refused(): void
    {
        $this->asOwner()->post('/commands/accounts', $this->payload([
            'dealer_level' => 'division',
            'place_code' => $this->business['division']->code,
            'permanent_place_id' => $this->home['district']->id,
        ]))->assertSessionHasErrors(['permanent_place_id' => __('portal.acct_val_home_place_level')]);

        $this->assertNull(User::query()->where('email', 'new.partner@example.test')->first());
        $this->assertSame(0, AreaDealerAssignment::query()->count());
    }

    public function test_an_unknown_or_switched_off_permanent_place_is_refused(): void
    {
        $this->asOwner()->post('/commands/accounts', $this->payload([
            'dealer_level' => 'division',
            'place_code' => $this->business['division']->code,
            'permanent_place_id' => 999999,
        ]))->assertSessionHasErrors(['permanent_place_id' => __('portal.acct_val_home_place_unknown')]);

        $this->home['union']->forceFill(['is_active' => false])->save();
        $this->asOwner()->post('/commands/accounts', $this->payload([
            'dealer_level' => 'division',
            'place_code' => $this->business['division']->code,
            'permanent_place_id' => $this->home['union']->id,
        ]))->assertSessionHasErrors(['permanent_place_id' => __('portal.acct_val_home_place_unknown')]);

        $this->asOwner()->post('/commands/accounts', $this->payload([
            'dealer_level' => 'division',
            'place_code' => $this->business['division']->code,
            'permanent_place_id' => 'Kaliakair',
        ]))->assertSessionHasErrors('permanent_place_id');

        $this->assertNull(User::query()->where('email', 'new.partner@example.test')->first());
    }

    public function test_the_add_partner_form_refuses_a_partner_without_a_permanent_address(): void
    {
        foreach (['dealer' => $this->business['district']->code, 'sub_dealer' => $this->business['union']->code] as $role => $code) {
            $this->asOwner()->post('/commands/accounts', $this->payload([
                'role' => $role,
                'place_code' => $code,
                'permanent_place_id' => '',
            ]))->assertSessionHasErrors(['permanent_place_id' => __('portal.acct_val_home_place_level')]);
        }

        $this->assertNull(User::query()->where('email', 'new.partner@example.test')->first());
        $this->assertSame(0, AreaDealerAssignment::query()->count());
    }

    public function test_other_ways_of_adding_a_partner_still_work_without_a_permanent_address(): void
    {
        // Imports and approved applications call the same service without a permanent address.
        $created = app(PartnerAccountProvisioning::class)->create(User::factory()->create(['role' => 'super_admin']), $this->payload([
            'place_code' => $this->business['district']->code,
        ]));

        $profile = $created['user']->profile;
        $this->assertArrayNotHasKey('permanent_division', $profile);
        $this->assertArrayNotHasKey('permanent_place_id', $profile);
    }

    public function test_the_office_partner_page_shows_the_permanent_address_apart_from_the_business_area(): void
    {
        $dealer = $this->dealerWithHome();

        $this->asOwner()->onPortal('sadmin')->get('/accounts/'.$dealer->id)->assertOk()->assertInertia(fn (Assert $page) => $page
            ->component('PartnerProfile')
            ->where('sheet.permanent_address.division', 'Dhaka')
            ->where('sheet.permanent_address.district', 'Gazipur')
            ->where('sheet.permanent_address.upazila', 'Kaliakair')
            ->where('sheet.permanent_address.union', 'Chapair')
            ->where('sheet.permanent_address.village', 'Ratanpur, House 12')
            ->where('sheet.permanent_address.post_office', 'Kaliakair-1750')
            ->where('sheet.place.division', 'Rangpur')
            ->where('sheet.place.upazila', 'Pirganj')
            ->where('sheet.place.place_code', 'MNHC402')
            ->missing('sheet.contact.village'));
    }

    public function test_the_partners_own_profile_shows_both_places_and_the_home_as_the_full_address(): void
    {
        $dealer = $this->dealerWithHome();

        app()->setLocale('en');
        $card = app(PartnerProfileCard::class)->for($dealer);

        $this->assertSame(['division' => 'Dhaka', 'district' => 'Gazipur', 'upazila' => 'Kaliakair', 'union' => 'Chapair', 'village' => 'Ratanpur, House 12', 'post_office' => 'Kaliakair-1750'], $card['permanent_address']);
        $this->assertSame('Pirganj', $card['address']['upazila']);
        $this->assertSame('Ratanpur, House 12, Kaliakair-1750, Chapair, Kaliakair, Gazipur, Dhaka', $card['biodata']['full_address']);
    }

    public function test_the_registration_form_prints_the_permanent_address_and_the_business_area_apart(): void
    {
        $sections = collect(app(PartnerRegistrationForm::class)->sections($this->dealerWithHome(), 'bn', true))->keyBy('title');

        $home = collect($sections['স্থায়ী ঠিকানা']['rows'])->pluck('value')->all();
        $business = collect($sections['ব্যবসার এলাকা']['rows'])->pluck('value')->all();
        $this->assertSame(['Dhaka', 'Gazipur', 'Kaliakair', 'Chapair', 'Ratanpur, House 12', 'Kaliakair-1750'], $home);
        // The business area is the four places picked on the form, down to the union (client, 2026-11-22).
        $this->assertSame(['Rangpur', 'Rangpur District', 'Pirganj', 'Kabilpur', 'MNHC402'], $business);
    }

    public function test_an_older_partner_without_a_permanent_place_still_shows_the_village(): void
    {
        $older = User::factory()->create(['role' => 'dealer', 'status' => 'active', 'profile' => ['village' => 'Nayabari', 'upazila' => 'Pirganj']]);

        $card = app(PartnerProfileCard::class)->for($older);

        $this->assertSame('Nayabari', $card['permanent_address']['village']);
        $this->assertNull($card['permanent_address']['upazila']);
        $this->assertSame('Nayabari', $card['biodata']['full_address']);
    }

    public function test_the_office_changes_a_partners_permanent_address_and_it_is_audited(): void
    {
        $dealer = $this->dealerWithHome();
        $owner = User::query()->where('role', 'super_admin')->firstOrFail();
        $rajshahi = PlaceCode::query()->where('code', 'MNHC104')->firstOrFail();
        $bogura = PlaceCode::factory()->district($rajshahi)->create(['code' => 'MNHC304', 'name' => 'Bogura']);
        $sherpur = PlaceCode::factory()->upazila($bogura)->create(['code' => 'MNHC404', 'name' => 'Sherpur']);

        $this->actingAs($owner)->onPortal('sadmin')->patch('/commands/accounts/'.$dealer->id, [
            'permanent_place_id' => $sherpur->id,
            'reason' => 'Moved home',
        ])->assertRedirect()->assertSessionHasNoErrors();

        $profile = $dealer->fresh()->profile;
        $this->assertSame(['Rajshahi', 'Bogura', 'Sherpur', ''], [$profile['permanent_division'], $profile['permanent_district'], $profile['permanent_upazila'], $profile['permanent_union']]);
        // The business area and the village are not touched.
        $this->assertSame('Pirganj', $profile['upazila']);
        $this->assertSame('MNHC402', $profile['place_code']);
        $this->assertSame('Ratanpur, House 12', $profile['village']);

        $audit = DB::table('audit_events')->where('action', 'account.permanent_address_updated')->where('subject', (string) $dealer->id)->sole();
        $payload = json_decode((string) $audit->metadata, true);
        $this->assertSame(['division' => 'Dhaka', 'district' => 'Gazipur', 'upazila' => 'Kaliakair', 'union' => 'Chapair'], $payload['before']);
        $this->assertSame(['division' => 'Rajshahi', 'district' => 'Bogura', 'upazila' => 'Sherpur', 'union' => null], $payload['after']);
    }

    public function test_the_editor_keeps_the_same_rule_and_leaves_the_address_alone_when_nothing_is_picked(): void
    {
        $dealer = $this->dealerWithHome();
        $owner = User::query()->where('role', 'super_admin')->firstOrFail();

        $this->actingAs($owner)->onPortal('sadmin')->patch('/commands/accounts/'.$dealer->id, [
            'permanent_place_id' => $this->home['district']->id,
            'reason' => 'Stops at the district',
        ])->assertSessionHasErrors(['permanent_place_id' => __('portal.acct_val_home_place_level')]);

        $this->actingAs($owner)->onPortal('sadmin')->patch('/commands/accounts/'.$dealer->id, [
            'name' => 'Renamed Partner',
            'reason' => 'Name only',
        ])->assertSessionHasNoErrors();

        $profile = $dealer->fresh()->profile;
        $this->assertSame('Chapair', $profile['permanent_union']);
        $this->assertSame(0, DB::table('audit_events')->where('action', 'account.permanent_address_updated')->count());

        $staff = User::factory()->create(['role' => 'employee']);
        $this->actingAs($owner)->onPortal('sadmin')->patch('/commands/accounts/'.$staff->id, [
            'permanent_place_id' => $this->home['upazila']->id,
            'reason' => 'Not for staff',
        ])->assertSessionHasErrors('permanent_place_id');
    }

    public function test_the_account_editor_has_the_permanent_address_lists(): void
    {
        $editor = (string) file_get_contents(resource_path('js/components/AccountEditor.tsx'));

        $this->assertStringContainsString('<PlacePickers', $editor);
        $this->assertStringContainsString('permanent_place_id', $editor);
    }

    private function dealerWithHome(): User
    {
        $this->asOwner()->post('/commands/accounts', $this->payload([
            'dealer_level' => 'upazila',
            'place_code' => $this->business['upazila']->code,
            'permanent_place_id' => $this->home['union']->id,
            'village' => 'Ratanpur, House 12',
            'post_office' => 'Kaliakair-1750',
        ]))->assertSessionHasNoErrors();

        return $this->created();
    }

    private function asOwner(): static
    {
        return $this->actingAs(User::query()->where('role', 'super_admin')->first() ?? User::factory()->create(['role' => 'super_admin']));
    }

    private function created(): User
    {
        return User::query()->where('email', 'new.partner@example.test')->firstOrFail();
    }

    /**
     * @param  array<string, mixed>  $overrides
     * @return array<string, mixed>
     */
    private function payload(array $overrides): array
    {
        return [
            'role' => 'dealer',
            'identity_type' => 'birth_certificate',
            'birth_certificate_number' => '19911234567890111',
            'date_of_birth' => '1991-02-02',
            'name' => 'New Partner',
            'name_bn' => 'নতুন পার্টনার',
            'father_name' => 'Father',
            'mother_name' => 'Mother',
            'email' => 'new.partner@example.test',
            'phone' => '01812345678',
            'reason' => 'Area and address test',
            ...$overrides,
        ];
    }
}
