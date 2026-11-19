<?php

namespace Tests\Feature;

use App\Domain\Audience\PublicPartnerApplication;
use App\Models\AreaDealerAssignment;
use App\Models\PlaceCode;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * From the public form to the approved account: the type the applicant picks stays, the places come
 * from lists, the applicant keeps a number and can read the result, and staff see what they need.
 */
class PartnerApplicationJourneyTest extends TestCase
{
    use RefreshDatabase;

    private PlaceCode $division;

    private PlaceCode $district;

    private PlaceCode $upazila;

    private PlaceCode $union;

    protected function setUp(): void
    {
        parent::setUp();
        $this->withoutVite();
        $this->division = PlaceCode::factory()->division()->create(['name' => 'Rajshahi']);
        $this->district = PlaceCode::factory()->district($this->division)->create(['name' => 'Natore']);
        $this->upazila = PlaceCode::factory()->upazila($this->district)->create(['name' => 'Singra']);
        $this->union = PlaceCode::factory()->union($this->upazila)->create(['name' => 'Chaugram', 'code' => 'MNHC100222']);
    }

    /** @return array<string, mixed> */
    private function form(array $over = []): array
    {
        return [
            'name' => 'Dr Shirin Sultana',
            'email' => 'shirin@example.test',
            'phone' => '01711000001',
            'dob_day' => 15, 'dob_month' => 1, 'dob_year' => 1980,
            'nid' => '1234567890123',
            'role' => 'dealer',
            'division_place_id' => $this->division->id,
            'district_place_id' => $this->district->id,
            'upazila_place_id' => $this->upazila->id,
            'union_place_id' => $this->union->id,
            ...$over,
        ];
    }

    private function owner(): User
    {
        return User::factory()->create(['role' => 'super_admin', 'status' => 'active']);
    }

    public function test_the_form_does_not_pick_a_type_for_the_applicant(): void
    {
        $html = $this->onPortal('public')->get('/partnership')->assertOk()->getContent();

        $this->assertDoesNotMatchRegularExpression('/name="role" value="sub_dealer"[^>]*\schecked/', $html);
        $this->assertDoesNotMatchRegularExpression('/name="role" value="dealer"[^>]*\schecked/', $html);
        $this->assertStringContainsString('id="partner-role-summary"', $html);
        $this->assertStringNotContainsString(__('site.partner_dealer_badge'), $html);
    }

    public function test_a_dealer_application_is_filed_as_a_dealer_and_the_applicant_gets_a_number(): void
    {
        $response = $this->onPortal('public')->from('/partnership')->post('/partnership/apply', $this->form());

        $response->assertSessionHasNoErrors()->assertSessionHas('application_number');
        $application = DB::table('dealer_applications')->sole();
        $payload = json_decode($application->data, true);
        $this->assertSame('dealer', $payload['role']);
        $this->assertSame('Rajshahi', $payload['division']);
        $this->assertSame('Natore', $payload['district']);
        $this->assertSame('Singra', $payload['upazila']);
        $this->assertSame('Chaugram', $payload['union']);
        $this->assertSame('1980-01-15', $payload['date_of_birth']);
        $this->assertSame('1234567890123', $payload['nid']);
        $this->assertSame(PublicPartnerApplication::numberFor($application->id), $application->application_number);
    }

    public function test_with_the_join_link_off_an_application_never_names_a_partner_as_sponsor(): void
    {
        config(['madina.partner_join_link_enabled' => false]);

        $this->onPortal('public')->from('/partnership')->post('/partnership/apply', $this->form(['sponsor_code' => 'REF200']))
            ->assertSessionHasNoErrors();

        $payload = json_decode((string) DB::table('dealer_applications')->sole()->data, true);
        $this->assertEmpty($payload['sponsor_code'] ?? null);
    }

    public function test_a_place_that_does_not_belong_to_the_district_is_refused(): void
    {
        $otherDistrict = PlaceCode::factory()->district($this->division)->create(['name' => 'Bogura']);

        $this->onPortal('public')->from('/partnership')->post('/partnership/apply', $this->form(['district_place_id' => $otherDistrict->id]))
            ->assertSessionHasErrors('upazila_place_id');
        $this->assertSame(0, DB::table('dealer_applications')->count());
    }

    public function test_the_place_lists_come_from_the_office_places_and_flag_a_held_upazila(): void
    {
        $holder = User::factory()->dealer()->create();
        AreaDealerAssignment::create([
            'user_id' => $holder->id, 'level' => 'upazila', 'place_code_id' => $this->upazila->id, 'zone_name' => 'x',
            'division' => 'Rajshahi', 'district' => 'Natore', 'upazila' => 'Singra', 'monthly_quota' => 0,
            'territory_key' => 'k1', 'is_active' => true,
        ]);

        $this->onPortal('public')->getJson('/partnership/places?level=division')->assertOk()
            ->assertJsonFragment(['id' => $this->division->id, 'name' => 'Rajshahi']);
        $this->onPortal('public')->getJson('/partnership/places?level=upazila&parent_id='.$this->district->id)->assertOk()
            ->assertJsonFragment(['id' => $this->upazila->id, 'held' => true]);
    }

    public function test_the_applicant_reads_the_result_with_the_number_and_the_phone(): void
    {
        $this->onPortal('public')->post('/partnership/apply', $this->form());
        $number = DB::table('dealer_applications')->value('application_number');

        $this->onPortal('public')->post('/partnership/status', ['number' => $number, 'phone' => '01711000001'])
            ->assertOk()->assertSee($number)->assertSee(__('joining.status_pending'));
        $this->onPortal('public')->post('/partnership/status', ['number' => $number, 'phone' => '01711999999'])
            ->assertOk()->assertSee(__('joining.status_not_found'))->assertDontSee('Dr Shirin');
    }

    public function test_staff_change_the_type_at_approval_and_the_account_follows_the_new_type(): void
    {
        $this->onPortal('public')->post('/partnership/apply', $this->form(['role' => 'sub_dealer']));
        $id = DB::table('dealer_applications')->value('id');

        $this->actingAs($this->owner())->onPortal('sadmin')->from('/partnerships')
            ->post('/commands/operations/staff/applications/'.$id.'/review', [
                'decision' => 'approved', 'role' => 'dealer', 'place_code' => $this->upazila->code, 'reason' => 'Checked',
            ])->assertSessionHasNoErrors();

        $partner = User::query()->where('email', 'shirin@example.test')->sole();
        $this->assertSame('dealer', $partner->role);
        $this->assertSame('1234567890123', $partner->nid);
    }

    public function test_a_dealer_is_an_area_dealer_only_when_the_reviewer_chooses_it(): void
    {
        $this->onPortal('public')->post('/partnership/apply', $this->form());
        $id = DB::table('dealer_applications')->value('id');

        $this->actingAs($this->owner())->onPortal('sadmin')->from('/partnerships')
            ->post('/commands/operations/staff/applications/'.$id.'/review', [
                'decision' => 'approved', 'place_code' => $this->upazila->code, 'area_dealer' => '0', 'reason' => 'Checked',
            ])->assertSessionHasNoErrors();

        $this->assertSame('dealer', User::query()->where('email', 'shirin@example.test')->value('role'));
        $this->assertSame(0, AreaDealerAssignment::query()->count());
    }

    public function test_a_place_outside_the_area_the_applicant_wrote_needs_an_explicit_yes(): void
    {
        $this->onPortal('public')->post('/partnership/apply', $this->form(['union_place_id' => null]));
        $id = DB::table('dealer_applications')->value('id');
        $otherUpazila = PlaceCode::factory()->upazila($this->district)->create(['name' => 'Lalpur']);
        $otherUnion = PlaceCode::factory()->union($otherUpazila)->create(['code' => 'MNHC100333']);
        $post = fn (array $extra) => $this->actingAs($this->owner())->onPortal('sadmin')->from('/partnerships')
            ->post('/commands/operations/staff/applications/'.$id.'/review', ['decision' => 'approved', 'role' => 'sub_dealer', 'place_code' => $otherUnion->code, 'reason' => 'Checked', ...$extra]);

        $post([])->assertSessionHasErrors(['place_code' => __('joining.err_place_outside_application')]);
        $this->assertDatabaseHas('dealer_applications', ['id' => $id, 'status' => 'pending']);

        $post(['accept_place_mismatch' => '1'])->assertSessionHasNoErrors();
        $this->assertDatabaseHas('dealer_applications', ['id' => $id, 'status' => 'approved']);
    }

    public function test_rejecting_needs_a_reason_and_the_applicant_can_read_it(): void
    {
        $this->onPortal('public')->post('/partnership/apply', $this->form());
        $row = DB::table('dealer_applications')->first();
        $review = fn (array $data) => $this->actingAs($this->owner())->onPortal('sadmin')->from('/partnerships')
            ->post('/commands/operations/staff/applications/'.$row->id.'/review', $data);

        $review(['decision' => 'rejected'])->assertSessionHasErrors('reason');
        $review(['decision' => 'rejected', 'reason' => 'Papers do not match'])->assertSessionHasNoErrors();

        $this->onPortal('public')->post('/partnership/status', ['number' => $row->application_number, 'phone' => '01711000001'])
            ->assertOk()->assertSee('Papers do not match');
    }

    public function test_an_application_sent_back_can_be_fixed_and_sent_again(): void
    {
        $this->onPortal('public')->post('/partnership/apply', $this->form(['nid' => '']));
        $row = DB::table('dealer_applications')->first();
        $this->actingAs($this->owner())->onPortal('sadmin')->from('/partnerships')
            ->post('/commands/operations/staff/applications/'.$row->id.'/review', ['decision' => 'correction', 'reason' => 'Write your ID number'])
            ->assertSessionHasNoErrors();
        $this->assertDatabaseHas('dealer_applications', ['id' => $row->id, 'status' => 'correction']);

        $this->onPortal('public')->post('/partnership/status', ['number' => $row->application_number, 'phone' => '01711000001'])
            ->assertOk()->assertSee('Write your ID number');
        $this->onPortal('public')->post('/partnership/status/resubmit', [
            'number' => $row->application_number, 'phone' => '01711000001', 'name' => 'Dr Shirin Sultana',
            'nid' => '1234567890123', 'dob_day' => 15, 'dob_month' => 1, 'dob_year' => 1980,
        ])->assertOk()->assertSee(__('joining.status_resubmitted'));

        $this->assertDatabaseHas('dealer_applications', ['id' => $row->id, 'status' => 'pending']);
        $this->assertSame('1234567890123', json_decode(DB::table('dealer_applications')->value('data'), true)['nid']);
    }

    public function test_the_review_list_warns_about_a_second_application_on_the_same_phone_and_a_missing_id(): void
    {
        $this->onPortal('public')->post('/partnership/apply', $this->form(['nid' => '']));
        $first = DB::table('dealer_applications')->first();
        DB::table('dealer_applications')->where('id', $first->id)->update(['status' => 'approved']);
        $this->onPortal('public')->post('/partnership/apply', $this->form(['email' => 'second@example.test', 'nid' => '']));

        $this->actingAs($this->owner())->onPortal('sadmin')->get('/partnerships?status=pending')->assertOk()
            ->assertInertia(fn ($page) => $page->where('data.applications.data.0.review.warnings', function ($warnings): bool {
                $kinds = collect($warnings)->pluck('kind')->all();

                return in_array('phone_in_application', $kinds, true) && in_array('nid_missing', $kinds, true);
            }));
    }

    public function test_a_referral_link_opens_the_application_form_not_the_contact_form(): void
    {
        $this->onPortal('public')->get('/join/MNHC404404')->assertRedirect('/partnership?sponsor_code=MNHC404404#partner-apply');

        $dealer = User::factory()->dealer()->create(['status' => 'active', 'dealer_code' => 'MNHC555', 'refer_code' => 'MNHC555']);
        $page = $this->onPortal('public')->get('/join/MNHC555')->assertOk();
        $page->assertSee('/partnership?sponsor_code=MNHC555', false)->assertDontSee('topic=partnership_enquiry', false);
        $this->assertNotNull($dealer->id);
    }

    public function test_the_main_menu_and_not_the_contact_form_lead_to_joining(): void
    {
        $this->onPortal('public')->get('/about')->assertOk()->assertSee('href="/partnership"', false);
        $contact = $this->onPortal('public')->get('/contact')->assertOk();
        $contact->assertSee(__('joining.contact_partner_hint'));
    }
}
