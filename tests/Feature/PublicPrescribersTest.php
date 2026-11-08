<?php

namespace Tests\Feature;

use App\Domain\Clinic\ConsultationRequests;
use App\Models\ConsultationRequest;
use App\Models\PlaceCode;
use App\Models\PrescriberPublicProfile;
use App\Models\PrescriberRegistration;
use App\Models\PrescriptionPatient;
use App\Models\PrescriptionPatientConsent;
use App\Models\PrivacyRequest;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Testing\TestResponse;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\TestCase;

/**
 * The public prescriber directory, private consultation requests, patient consent records
 * and the owners' queue of partners' data requests.
 */
class PublicPrescribersTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->withoutVite();
    }

    /**
     * A dealer the owner has switched on, optionally listed on the public page.
     *
     * @param  array<string, mixed>  $profile
     */
    private function prescriber(string $name, bool $listed = true, array $profile = [], ?string $chamberHours = null): User
    {
        $dealer = User::factory()->dealer()->create(['status' => 'active', 'name' => $name, 'can_prescribe' => true, 'phone' => fake()->unique()->numerify('01719######'), 'profile' => $profile]);
        PrescriberRegistration::factory()->create(['user_id' => $dealer->id, 'enabled' => true, 'registration_type' => 'bmdc', 'registration_number' => 'A-'.$dealer->id]);
        if ($listed) {
            PrescriberPublicProfile::factory()->create(['user_id' => $dealer->id, 'listed' => true, 'chamber_hours' => $chamberHours]);
        }

        return $dealer;
    }

    private function listingId(User $prescriber): int
    {
        return (int) PrescriberPublicProfile::query()->where('user_id', $prescriber->id)->value('id');
    }

    /** @param array<string, mixed> $overrides */
    private function consult(User $prescriber, array $overrides = []): TestResponse
    {
        return $this->onPortal('public')->post('/prescribers/'.$this->listingId($prescriber).'/consult', [
            'name' => 'Karim Mia',
            'phone' => '01812345678',
            'problem' => 'Pain in my left knee for two weeks',
            'preferred_time' => 'After 5 pm',
            'consent' => '1',
            ...$overrides,
        ]);
    }

    private function place(string $level, string $code, ?int $parentId = null): PlaceCode
    {
        return PlaceCode::query()->create(['level' => $level, 'code' => $code, 'name' => 'Place '.$code, 'parent_id' => $parentId, 'is_active' => true]);
    }

    public function test_only_enabled_prescribers_who_opted_in_are_listed(): void
    {
        $this->prescriber('Listed Doctor');
        $this->prescriber('Not Opted In', listed: false);

        $optedOut = $this->prescriber('Opted Back Out');
        PrescriberPublicProfile::query()->where('user_id', $optedOut->id)->update(['listed' => false]);

        $neverEnabled = User::factory()->dealer()->create(['status' => 'active', 'name' => 'Never Enabled', 'can_prescribe' => false]);
        PrescriberPublicProfile::factory()->create(['user_id' => $neverEnabled->id]);

        $suspended = $this->prescriber('Suspended Doctor');
        $suspended->forceFill(['status' => 'suspended'])->save();

        $this->onPortal('public')->get('/prescribers')
            ->assertOk()
            ->assertSee('Listed Doctor')
            ->assertSee('A-')
            ->assertDontSee('Not Opted In')
            ->assertDontSee('Opted Back Out')
            ->assertDontSee('Never Enabled')
            ->assertDontSee('Suspended Doctor');
    }

    public function test_listing_is_off_by_default_and_the_prescriber_turns_it_on_themselves(): void
    {
        $dealer = $this->prescriber('Self Service Doctor', listed: false);
        $this->onPortal('public')->get('/prescribers')->assertDontSee('Self Service Doctor');

        $this->actingAs($dealer)->onPortal('dealer')
            ->post('/commands/prescriptions/public-listing', ['listed' => true, 'chamber_hours' => 'Sat-Thu 5pm to 9pm'])
            ->assertRedirect();

        auth()->logout();
        $this->onPortal('public')->get('/prescribers')->assertSee('Self Service Doctor')->assertSee('Sat-Thu 5pm to 9pm');
    }

    public function test_a_partner_who_cannot_prescribe_cannot_opt_in(): void
    {
        $dealer = User::factory()->dealer()->create(['status' => 'active', 'can_prescribe' => false]);

        $this->actingAs($dealer)->onPortal('dealer')
            ->post('/commands/prescriptions/public-listing', ['listed' => true])
            ->assertForbidden();
        $this->assertSame(0, PrescriberPublicProfile::query()->count());
    }

    public function test_disabling_prescribing_removes_them_from_the_page_and_blocks_new_requests(): void
    {
        $owner = User::factory()->staff('super_admin')->create();
        $dealer = $this->prescriber('Soon Disabled');
        $this->onPortal('public')->get('/prescribers')->assertSee('Soon Disabled');

        $this->actingAs($owner)->onPortal('sadmin')
            ->delete('/commands/accounts/'.$dealer->id.'/prescriber', ['reason' => 'Licence expired'])
            ->assertRedirect();
        auth()->logout();
        // The owner's "turned off for <name>" message would otherwise show on the next page.
        $this->flushSession();

        $this->onPortal('public')->get('/prescribers')->assertDontSee('Soon Disabled');
        $this->consult($dealer)->assertNotFound();
        $this->assertSame(0, ConsultationRequest::query()->count());
    }

    public function test_the_page_shows_registration_and_area_but_never_a_phone_number(): void
    {
        $division = $this->place(PlaceCode::LevelDivision, 'RNG');
        $dealer = $this->prescriber('Phone Check Doctor', profile: ['place_code_id' => $division->id, 'pharmacy' => 'Secret Pharmacy'], chamberHours: '10am to 2pm');
        $phone = (string) $dealer->phone;

        $this->onPortal('public')->get('/prescribers')
            ->assertOk()
            ->assertSee('A-'.$dealer->id)
            ->assertSee('RNG')
            ->assertSee('10am to 2pm')
            ->assertDontSee($phone)
            ->assertDontSee(substr($phone, -8))
            ->assertDontSee('Secret Pharmacy');
    }

    public function test_chamber_hours_cannot_hold_a_phone_number(): void
    {
        $dealer = $this->prescriber('Hours Doctor', listed: false);

        foreach (['Call 01712345678', 'Call 017-1234-5678', 'ফোন ০১৭১২৩৪৫৬৭৮'] as $withPhone) {
            $this->actingAs($dealer)->onPortal('dealer')
                ->post('/commands/prescriptions/public-listing', ['listed' => true, 'chamber_hours' => $withPhone])
                ->assertSessionHasErrors('chamber_hours');
        }
        $this->assertSame(0, PrescriberPublicProfile::query()->count());

        $this->actingAs($dealer)->onPortal('dealer')
            ->post('/commands/prescriptions/public-listing', ['listed' => true, 'chamber_hours' => 'Sat-Thu 10:00-13:00, 17:00-21:00'])
            ->assertSessionHasNoErrors();
    }

    public function test_the_list_filters_down_the_place_code_tree(): void
    {
        $division = $this->place(PlaceCode::LevelDivision, 'D1');
        $otherDivision = $this->place(PlaceCode::LevelDivision, 'D2');
        $district = $this->place(PlaceCode::LevelDistrict, 'D1-A', $division->id);
        $upazila = $this->place(PlaceCode::LevelUpazila, 'D1-A-U', $district->id);
        $otherDistrict = $this->place(PlaceCode::LevelDistrict, 'D1-B', $division->id);
        $this->prescriber('Upazila Doctor', profile: ['place_code_id' => $upazila->id]);
        $this->prescriber('Other District Doctor', profile: ['place_code_id' => $otherDistrict->id]);
        $this->prescriber('Other Division Doctor', profile: ['place_code_id' => $otherDivision->id]);

        $this->onPortal('public')->get('/prescribers?division='.$division->id)
            ->assertSee('Upazila Doctor')->assertSee('Other District Doctor')->assertDontSee('Other Division Doctor');

        $this->onPortal('public')->get('/prescribers?division='.$division->id.'&district='.$district->id)
            ->assertSee('Upazila Doctor')->assertDontSee('Other District Doctor');

        $this->onPortal('public')->get('/prescribers?division='.$division->id.'&district='.$district->id.'&upazila='.$upazila->id)
            ->assertSee('Upazila Doctor')->assertDontSee('Other District Doctor');

        // A district from another branch of the tree is not a valid choice under this division.
        $this->onPortal('public')->get('/prescribers?division='.$otherDivision->id.'&district='.$district->id)
            ->assertSee('Other Division Doctor')->assertDontSee('Upazila Doctor');
    }

    public function test_a_request_reaches_only_its_prescriber_with_consent_stored(): void
    {
        $dealer = $this->prescriber('Request Doctor');
        $other = $this->prescriber('Other Doctor');

        $this->consult($dealer)->assertRedirect()->assertSessionHas('success');

        $request = ConsultationRequest::query()->sole();
        $this->assertSame($dealer->id, $request->prescriber_id);
        $this->assertSame(ConsultationRequest::StatusNew, $request->status);
        $this->assertNotNull($request->consented_at);
        $this->assertStringContainsString('only to this practitioner', $request->consent_statement);

        $this->actingAs($dealer->fresh())->onPortal('dealer')->get('/prescriptions')
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->has('data.prescriber_public.requests', 1)
                ->where('data.prescriber_public.requests.0.name', 'Karim Mia')
                ->where('data.prescriber_public.requests.0.phone', '01812345678')
                ->where('data.prescriber_public.new_requests', 1));

        $this->actingAs($other->fresh())->onPortal('dealer')->get('/prescriptions')
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page->has('data.prescriber_public.requests', 0));
    }

    public function test_the_owner_never_sees_a_request_even_as_a_prescriber(): void
    {
        $owner = User::factory()->staff('super_admin')->create();
        $dealer = $this->prescriber('Private Doctor');
        $this->consult($dealer)->assertRedirect();

        $this->actingAs($owner)->onPortal('sadmin')->get('/prescriptions')
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page->has('data.prescriber_public.requests', 0));
        $this->actingAs($owner)->onPortal('sadmin')->get('/audit')
            ->assertOk()
            ->assertDontSee('Karim Mia')
            ->assertDontSee('01812345678');

        $audit = DB::table('audit_events')->where('action', 'consultation.requested')->sole();
        $this->assertNull($audit->actor_id);
        $this->assertStringNotContainsString('Karim', (string) $audit->metadata);
        $notice = DB::table('account_notices')->where('user_id', $dealer->id)->orderByDesc('id')->first();
        $this->assertStringNotContainsString('Karim', $notice->title.' '.$notice->body);
        $this->assertStringNotContainsString('knee', $notice->title.' '.$notice->body);

        $request = ConsultationRequest::query()->sole();
        $this->actingAs($owner)->onPortal('sadmin')->post('/commands/prescriptions/consultations/'.$request->id.'/accept')->assertNotFound();
        $this->assertSame(0, PrescriptionPatient::query()->count());
    }

    public function test_a_prescriber_cannot_answer_another_prescribers_request(): void
    {
        $dealer = $this->prescriber('First Doctor');
        $other = $this->prescriber('Second Doctor');
        $this->consult($dealer)->assertRedirect();
        $request = ConsultationRequest::query()->sole();

        $this->actingAs($other)->onPortal('dealer')->post('/commands/prescriptions/consultations/'.$request->id.'/accept')->assertNotFound();
        $this->actingAs($other)->onPortal('dealer')->post('/commands/prescriptions/consultations/'.$request->id.'/decline')->assertNotFound();
        $this->assertSame(ConsultationRequest::StatusNew, $request->fresh()->status);
    }

    public function test_accepting_a_request_adds_the_patient_once_with_consent(): void
    {
        $dealer = $this->prescriber('Accepting Doctor');
        $this->consult($dealer)->assertRedirect();
        $request = ConsultationRequest::query()->sole();

        $this->actingAs($dealer)->onPortal('dealer')->post('/commands/prescriptions/consultations/'.$request->id.'/accept')->assertRedirect();
        $this->actingAs($dealer)->onPortal('dealer')->post('/commands/prescriptions/consultations/'.$request->id.'/accept')->assertSessionHasErrors('consultation');

        $patient = PrescriptionPatient::query()->sole();
        $this->assertSame($dealer->id, $patient->prescriber_id);
        $this->assertSame('Karim Mia', $patient->name);
        $this->assertSame('01812345678', $patient->phone);
        $this->assertSame(ConsultationRequest::StatusAccepted, $request->fresh()->status);
        $this->assertSame($patient->id, $request->fresh()->patient_id);

        $consent = PrescriptionPatientConsent::query()->sole();
        $this->assertSame(PrescriptionPatientConsent::SourcePublicRequest, $consent->source);
        $this->assertSame($request->consent_statement, $consent->statement);
        $this->assertSame($request->id, $consent->consultation_request_id);
    }

    public function test_declining_a_request_adds_no_patient(): void
    {
        $dealer = $this->prescriber('Declining Doctor');
        $this->consult($dealer)->assertRedirect();
        $request = ConsultationRequest::query()->sole();

        $this->actingAs($dealer)->onPortal('dealer')->post('/commands/prescriptions/consultations/'.$request->id.'/decline')->assertRedirect();

        $this->assertSame(ConsultationRequest::StatusDeclined, $request->fresh()->status);
        $this->assertSame(0, PrescriptionPatient::query()->count());
    }

    public function test_the_form_needs_consent_and_a_valid_phone(): void
    {
        $dealer = $this->prescriber('Validation Doctor');

        $this->consult($dealer, ['consent' => ''])->assertSessionHasErrors('consent');
        $this->consult($dealer, ['phone' => 'not a phone'])->assertSessionHasErrors('phone');
        $this->assertSame(0, ConsultationRequest::query()->count());
    }

    public function test_a_bangla_digit_phone_is_saved_in_plain_digits(): void
    {
        $dealer = $this->prescriber('Bangla Digits Doctor');

        $this->consult($dealer, ['phone' => '০১৮১২৩৪৫৬৭৮'])->assertRedirect()->assertSessionHasNoErrors();

        $this->assertSame('01812345678', ConsultationRequest::query()->sole()->phone);
    }

    public function test_the_hidden_trap_field_stops_robots(): void
    {
        $dealer = $this->prescriber('Trap Doctor');

        $this->consult($dealer, ['website' => 'http://spam.example'])->assertSessionHasErrors('website');
        $this->assertSame(0, ConsultationRequest::query()->count());
    }

    public function test_requests_are_rate_limited_per_visitor(): void
    {
        $dealer = $this->prescriber('Busy Doctor');

        for ($attempt = 1; $attempt <= 5; $attempt++) {
            $this->consult($dealer, ['phone' => '0181234560'.$attempt])->assertRedirect();
        }
        $this->consult($dealer, ['phone' => '01812345606'])->assertStatus(429);

        $this->assertSame(5, ConsultationRequest::query()->count());
    }

    public function test_one_phone_is_limited_per_prescriber_per_day(): void
    {
        $dealer = $this->prescriber('Popular Doctor');
        for ($attempt = 1; $attempt <= ConsultationRequests::PhoneDailyLimit; $attempt++) {
            $this->consult($dealer)->assertSessionHasNoErrors();
        }

        $this->consult($dealer)->assertSessionHasErrors('phone');

        $this->assertSame(ConsultationRequests::PhoneDailyLimit, ConsultationRequest::query()->count());
    }

    public function test_the_page_carries_the_emergency_note_and_can_be_switched_off(): void
    {
        $this->prescriber('Note Doctor');

        $this->onPortal('public')->get('/prescribers')->assertOk()->assertSee('go to the nearest hospital');

        config(['madina.prescriptions.enabled' => false]);
        $this->onPortal('public')->get('/prescribers')->assertNotFound();
    }

    public function test_a_new_patient_needs_consent_and_it_is_stored(): void
    {
        $dealer = $this->prescriber('Consent Doctor');

        $this->actingAs($dealer)->onPortal('dealer')
            ->post('/commands/prescriptions/patients', ['name' => 'No Consent'])
            ->assertSessionHasErrors('patient_consent');
        $this->assertSame(0, PrescriptionPatient::query()->count());

        $this->actingAs($dealer)->onPortal('dealer')
            ->post('/commands/prescriptions/patients', ['name' => 'With Consent', 'patient_consent' => true])
            ->assertRedirect()
            ->assertSessionHasNoErrors();

        $patient = PrescriptionPatient::query()->sole();
        $consent = PrescriptionPatientConsent::query()->sole();
        $this->assertSame($patient->id, $consent->patient_id);
        $this->assertSame($dealer->id, $consent->prescriber_id);
        $this->assertSame(PrescriptionPatientConsent::SourcePrescriber, $consent->source);
        $this->assertNotNull($consent->confirmed_at);

        $this->actingAs($dealer->fresh())->onPortal('dealer')->get('/prescriptions')
            ->assertInertia(fn (Assert $page) => $page->where('data.prescriptions.patients.0.consent_at', $consent->confirmed_at->toIso8601String()));
    }

    public function test_a_patient_without_consent_can_have_it_recorded_when_edited(): void
    {
        $dealer = $this->prescriber('Edit Doctor');
        $patient = PrescriptionPatient::factory()->create(['prescriber_id' => $dealer->id, 'name' => 'Old Patient']);

        $this->actingAs($dealer)->onPortal('dealer')
            ->patch('/commands/prescriptions/patients/'.$patient->id, ['name' => 'Old Patient'])
            ->assertSessionHasNoErrors();
        $this->assertSame(0, PrescriptionPatientConsent::query()->count());

        $this->actingAs($dealer)->onPortal('dealer')
            ->patch('/commands/prescriptions/patients/'.$patient->id, ['name' => 'Old Patient', 'patient_consent' => true])
            ->assertSessionHasNoErrors();
        $this->assertSame(1, PrescriptionPatientConsent::query()->where('patient_id', $patient->id)->count());
    }

    public function test_a_partner_sends_a_data_request_and_the_owner_queue_shows_no_patient_records(): void
    {
        $owner = User::factory()->staff('super_admin')->create();
        $dealer = $this->prescriber('Privacy Partner');
        PrescriptionPatient::factory()->create(['prescriber_id' => $dealer->id, 'name' => 'Hidden Patient Name', 'phone' => '01655554444', 'notes' => 'Hidden note']);

        $this->actingAs($dealer)->onPortal('dealer')
            ->post('/commands/privacy-requests', ['kind' => PrivacyRequest::KindDelete])
            ->assertRedirect()
            ->assertSessionHasNoErrors();
        $this->actingAs($dealer)->onPortal('dealer')
            ->post('/commands/privacy-requests', ['kind' => PrivacyRequest::KindDelete])
            ->assertSessionHasErrors('kind');

        $request = PrivacyRequest::query()->sole();
        $this->actingAs($dealer->fresh())->onPortal('dealer')->get('/security')
            ->assertInertia(fn (Assert $page) => $page->has('data.privacy_requests.mine', 1));

        $response = $this->actingAs($owner)->onPortal('sadmin')->get('/privacy-requests')
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->where('section', 'privacy-requests')
                ->where('data.privacy_queue.rows.data.0.partner', 'Privacy Partner')
                ->where('data.privacy_queue.rows.data.0.kind', PrivacyRequest::KindDelete));
        $response->assertDontSee('Hidden Patient Name')->assertDontSee('01655554444')->assertDontSee('Hidden note');

        $this->actingAs($owner)->onPortal('sadmin')
            ->post('/commands/privacy-requests/'.$request->id.'/resolve', ['status' => PrivacyRequest::StatusDone, 'note' => 'Handled by hand'])
            ->assertRedirect()
            ->assertSessionHasNoErrors();

        $this->assertSame(PrivacyRequest::StatusDone, $request->fresh()->status);
        $this->assertSame($owner->id, $request->fresh()->resolved_by);
        $this->assertSame(1, PrescriptionPatient::query()->count());
        $this->assertTrue(DB::table('account_notices')->where('user_id', $dealer->id)->where('title', __('prescriber_public.privacy_notice_update_title'))->exists());
    }

    public function test_only_owners_can_open_the_queue_or_answer_a_request(): void
    {
        $dealer = $this->prescriber('Queue Partner');
        $request = PrivacyRequest::factory()->create(['user_id' => $dealer->id]);
        $finance = User::factory()->staff('finance')->create();

        $this->actingAs($finance)->onPortal('sadmin')->get('/privacy-requests')->assertForbidden();
        $this->actingAs($finance)->onPortal('sadmin')->post('/commands/privacy-requests/'.$request->id.'/resolve', ['status' => PrivacyRequest::StatusDone])->assertForbidden();
        $this->actingAs($dealer)->onPortal('dealer')->post('/commands/privacy-requests/'.$request->id.'/resolve', ['status' => PrivacyRequest::StatusDone])->assertForbidden();
        $this->assertSame(PrivacyRequest::StatusOpen, $request->fresh()->status);

        $this->actingAs($finance)->onPortal('sadmin')->post('/commands/privacy-requests', ['kind' => PrivacyRequest::KindAccess])->assertForbidden();
    }
}
