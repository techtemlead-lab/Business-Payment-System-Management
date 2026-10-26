<?php

namespace Tests\Feature;

use App\Models\AreaDealerAssignment;
use App\Models\PlaceCode;
use App\Models\User;
use Database\Seeders\PlaceCodeSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\TestCase;

class PartnerAccountOnboardingTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->withoutVite();
        $this->seed(PlaceCodeSeeder::class);
        config(['madina.sms.driver' => 'array', 'madina.sms.sent' => []]);
    }

    public function test_create_page_defaults_to_birth_certificate_and_lists_divisions(): void
    {
        $admin = User::factory()->create(['role' => 'super_admin']);

        $this->actingAs($admin)->onPortal('sadmin')->get('/accounts/create')
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->component('AccountCreate')
                ->where('intent', 'dealer')
                ->where('roles', ['dealer'])
                ->where('default_identity_type', 'birth_certificate')
                ->has('place_divisions', 8)
                ->where('place_divisions.0.code', 'MNHC-101'));

        $this->actingAs($admin)->onPortal('sadmin')->get('/accounts/create?intent=sub_dealer')
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->component('AccountCreate')
                ->where('intent', 'sub_dealer')
                ->where('roles', ['sub_dealer']));
    }

    public function test_bdris_lookup_falls_back_to_manual_when_disabled(): void
    {
        $admin = User::factory()->create(['role' => 'super_admin']);
        config(['madina.bdris.enabled' => false]);

        $this->actingAs($admin)->onPortal('sadmin')->postJson('/commands/identity/bdris-lookup', [
            'birth_certificate_number' => '19901234567890123',
            'date_of_birth' => '1990-01-15',
        ])->assertOk()
            ->assertJsonPath('data.success', false)
            ->assertJsonPath('data.source', 'disabled');
    }

    public function test_bdris_lookup_autofills_when_remote_succeeds(): void
    {
        $admin = User::factory()->create(['role' => 'super_admin']);
        config([
            'madina.bdris.enabled' => true,
            'madina.bdris.base_url' => 'https://bdris.test/api',
            'madina.bdris.api_key' => 'test-key',
        ]);
        Http::fake([
            'https://bdris.test/api/birth-registrations/lookup*' => Http::response([
                'data' => [
                    'name' => 'Md. Karim',
                    'name_bn' => 'মোঃ করিম',
                    'father_name' => 'Abdul',
                    'mother_name' => 'Rahima',
                    'occupation' => 'Business',
                    'date_of_birth' => '1990-01-15',
                ],
            ], 200),
        ]);

        $this->actingAs($admin)->onPortal('sadmin')->postJson('/commands/identity/bdris-lookup', [
            'birth_certificate_number' => '19901234567890123',
            'date_of_birth' => '1990-01-15',
        ])->assertOk()
            ->assertJsonPath('data.success', true)
            ->assertJsonPath('data.details.name', 'Md. Karim')
            ->assertJsonPath('data.details.name_bn', 'মোঃ করিম');
    }

    public function test_dealer_created_by_place_code_with_unique_refer_codes_and_sms(): void
    {
        $admin = User::factory()->create(['role' => 'super_admin']);
        $sponsor = User::factory()->dealer()->create([
            'status' => 'active',
            'refer_code' => 'REF-SPONSOR',
            'dealer_code' => 'D-SPONSOR',
        ]);
        $rangpur = PlaceCode::query()->where('code', 'MNHC-102')->firstOrFail();

        $this->actingAs($admin)->onPortal('sadmin')->post('/commands/accounts', [
            'role' => 'dealer',
            'identity_type' => 'birth_certificate',
            'birth_certificate_number' => '19901234567890123',
            'date_of_birth' => '1990-01-15',
            'name' => 'Md. Rafiq',
            'name_bn' => 'মোঃ রফিক',
            'father_name' => 'Abdur Rahman',
            'mother_name' => 'Rahima Begum',
            'occupation' => 'Pharmacy',
            'place_code' => 'MNHC-102',
            'reference_code' => 'REF-SPONSOR',
            'refer_code' => 'REF-RAFIQ',
            'phone' => '01712345678',
            'email' => 'rafiq-place@example.com',
            'reason' => 'Onboarded under Rangpur division code.',
        ])->assertRedirect('/accounts')->assertSessionHas('success');

        $user = User::query()->where('email', 'rafiq-place@example.com')->firstOrFail();
        $this->assertSame('dealer', $user->role);
        $this->assertSame('MNHC-102', $user->dealer_code);
        $this->assertSame('REF-RAFIQ', $user->refer_code);
        $this->assertSame($sponsor->id, $user->sponsor_id);
        $this->assertSame('01712345678', $user->phone);
        $this->assertSame('birth_certificate', $user->profile['identity_type']);
        $this->assertTrue(AreaDealerAssignment::query()->where('user_id', $user->id)->where('place_code_id', $rangpur->id)->where('is_active', true)->exists());

        $sent = config('madina.sms.sent');
        $this->assertNotEmpty($sent);
        $this->assertStringContainsString('01712345678', $sent[0]['message']);
        $this->assertStringContainsString('Password:', $sent[0]['message']);
    }

    public function test_second_dealer_cannot_take_same_area_code(): void
    {
        $admin = User::factory()->create(['role' => 'super_admin']);
        $this->actingAs($admin)->onPortal('sadmin')->post('/commands/accounts', [
            'role' => 'dealer',
            'identity_type' => 'nid',
            'nid' => '1985123456789',
            'date_of_birth' => '1985-05-01',
            'name' => 'First Dealer',
            'name_bn' => 'প্রথম',
            'father_name' => 'Father',
            'mother_name' => 'Mother',
            'place_code' => 'MNHC-101',
            'refer_code' => 'REF-FIRST',
            'phone' => '01710000001',
            'email' => 'first@example.com',
            'reason' => 'First Dhaka dealer',
        ])->assertRedirect('/accounts');

        $this->actingAs($admin)->onPortal('sadmin')->from('/accounts/create')->post('/commands/accounts', [
            'role' => 'dealer',
            'identity_type' => 'nid',
            'nid' => '1985123456790',
            'date_of_birth' => '1986-05-01',
            'name' => 'Second Dealer',
            'name_bn' => 'দ্বিতীয়',
            'father_name' => 'Father',
            'mother_name' => 'Mother',
            'place_code' => 'MNHC-101',
            'refer_code' => 'REF-SECOND',
            'phone' => '01710000002',
            'email' => 'second@example.com',
            'reason' => 'Duplicate Dhaka dealer',
        ])->assertRedirect('/accounts/create')->assertSessionHasErrors('place_code');
    }

    public function test_sub_dealer_gets_autogenerated_union_serial_without_seat_limit(): void
    {
        $admin = User::factory()->create(['role' => 'super_admin']);
        $management = PlaceCode::query()->where('code', 'MNHC-100')->firstOrFail();
        $division = PlaceCode::query()->where('code', 'MNHC-102')->firstOrFail();
        $district = PlaceCode::factory()->district($division)->create(['code' => 'MNHC-301', 'name' => 'Rangpur']);
        $upazila = PlaceCode::factory()->upazila($district)->create(['code' => 'MNHC-401', 'name' => 'Sadar']);
        $union = PlaceCode::factory()->union($upazila)->create(['code' => 'MNHC-457482', 'name' => 'Rangpur City Corporation']);
        $this->assertNotNull($management);

        $this->actingAs($admin)->onPortal('sadmin')->post('/commands/accounts', [
            'role' => 'sub_dealer',
            'identity_type' => 'birth_certificate',
            'birth_certificate_number' => '20001234567890123',
            'date_of_birth' => '2000-02-02',
            'name' => 'Sub One',
            'name_bn' => 'সাব এক',
            'father_name' => 'Father',
            'mother_name' => 'Mother',
            'place_code' => 'MNHC-457482',
            'refer_code' => 'REF-SUB1',
            'phone' => '01810000001',
            'email' => 'sub1@example.com',
            'reason' => 'First sub under union',
        ])->assertRedirect('/sub-dealers');

        $first = User::query()->where('email', 'sub1@example.com')->firstOrFail();
        $this->assertSame('MNHC-457482-000001', $first->dealer_code);

        $this->actingAs($admin)->onPortal('sadmin')->post('/commands/accounts', [
            'role' => 'sub_dealer',
            'identity_type' => 'birth_certificate',
            'birth_certificate_number' => '20001234567890124',
            'date_of_birth' => '2001-02-02',
            'name' => 'Sub Two',
            'name_bn' => 'সাব দুই',
            'father_name' => 'Father',
            'mother_name' => 'Mother',
            'place_code' => 'MNHC-457482',
            'refer_code' => 'REF-SUB2',
            'phone' => '01810000002',
            'email' => 'sub2@example.com',
            'reason' => 'Second sub under same union',
        ])->assertRedirect('/sub-dealers');

        $second = User::query()->where('email', 'sub2@example.com')->firstOrFail();
        $this->assertSame('MNHC-457482-000002', $second->dealer_code);
        $this->assertSame($union->code, $second->profile['union_place_code']);
    }

    public function test_refer_preview_warnings_use_portal_locale_copy(): void
    {
        $admin = User::factory()->create(['role' => 'super_admin', 'locale' => 'bn']);
        User::factory()->dealer()->create(['refer_code' => 'REF-TAKEN', 'dealer_code' => 'D-TAKEN']);

        $this->actingAs($admin)->onPortal('sadmin')
            ->getJson('/commands/identity/refer-preview?reference_code=REF-MISSING&refer_code=REF-TAKEN')
            ->assertOk()
            ->assertJsonPath('data.warnings.0', __('portal.acct_val_refer_taken', [], 'bn'))
            ->assertJsonPath('data.warnings.1', __('portal.acct_val_reference_missing', [], 'bn'));

        $this->actingAs($admin)->onPortal('sadmin')
            ->getJson('/commands/identity/refer-preview?reference_code=SAME&refer_code=SAME')
            ->assertOk()
            ->assertJsonPath('data.warnings.0', __('portal.acct_val_reference_same_as_refer', [], 'bn'));
    }

    public function test_duplicate_refer_code_is_rejected(): void
    {
        $admin = User::factory()->create(['role' => 'super_admin']);
        User::factory()->dealer()->create(['refer_code' => 'REF-TAKEN', 'dealer_code' => 'D-TAKEN']);

        $this->actingAs($admin)->onPortal('sadmin')->from('/accounts/create')->post('/commands/accounts', [
            'role' => 'dealer',
            'identity_type' => 'nid',
            'nid' => '1234567890',
            'date_of_birth' => '1991-01-01',
            'name' => 'Clone',
            'name_bn' => 'ক্লোন',
            'father_name' => 'Father',
            'mother_name' => 'Mother',
            'place_code' => 'MNHC-103',
            'refer_code' => 'REF-TAKEN',
            'phone' => '01910000001',
            'email' => 'clone@example.com',
            'reason' => 'Duplicate refer code',
        ])->assertRedirect('/accounts/create')->assertSessionHasErrors('refer_code');
    }

    public function test_place_preview_shows_label_and_commission_path(): void
    {
        $admin = User::factory()->create(['role' => 'super_admin']);
        $division = PlaceCode::query()->where('code', 'MNHC-102')->firstOrFail();
        $district = PlaceCode::factory()->district($division)->create(['code' => 'MNHC-301', 'name' => 'Rangpur']);
        $upazila = PlaceCode::factory()->upazila($district)->create(['code' => 'MNHC-401', 'name' => 'Sadar']);
        $union = PlaceCode::factory()->union($upazila)->create(['code' => 'MNHC-501', 'name' => 'City Corp']);
        $division->update(['base_place_id' => $district->id]);
        $district->update(['base_place_id' => $upazila->id]);
        $upazila->update(['base_place_id' => $union->id]);

        $this->actingAs($admin)->onPortal('sadmin')->getJson('/commands/identity/place-preview?place_code=102&role=dealer')
            ->assertOk()
            ->assertJsonPath('data.label', 'MNHC-102-Division-Rangpur')
            ->assertJsonPath('data.commission_places.0.level', 'management')
            ->assertJsonPath('data.commission_places.1.code', 'MNHC-102')
            ->assertJsonPath('data.commission_places.4.code', 'MNHC-501')
            ->assertJsonPath('data.available', true);
    }

    public function test_account_update_rebinds_dealer_place_code_and_moves_area_assignment(): void
    {
        $admin = User::factory()->create(['role' => 'super_admin']);
        $from = PlaceCode::query()->where('code', 'MNHC-101')->firstOrFail();
        $to = PlaceCode::query()->where('code', 'MNHC-102')->firstOrFail();

        $this->actingAs($admin)->onPortal('sadmin')->post('/commands/accounts', [
            'role' => 'dealer',
            'identity_type' => 'nid',
            'nid' => '1985123456789',
            'date_of_birth' => '1985-05-01',
            'name' => 'Moveable Dealer',
            'name_bn' => 'স্থানান্তরযোগ্য',
            'father_name' => 'Father',
            'mother_name' => 'Mother',
            'place_code' => $from->code,
            'refer_code' => 'REF-MOVE',
            'phone' => '01710000011',
            'email' => 'moveable@example.com',
            'reason' => 'Create under Dhaka before geography correction',
        ])->assertRedirect('/accounts');

        $dealer = User::query()->where('email', 'moveable@example.com')->firstOrFail();
        $this->assertTrue(AreaDealerAssignment::query()->where('user_id', $dealer->id)->where('place_code_id', $from->id)->where('is_active', true)->exists());

        $this->actingAs($admin)->onPortal('sadmin')->patch('/commands/accounts/'.$dealer->id, [
            'name' => 'Moveable Dealer',
            'place_code' => $to->code,
            'reason' => 'Correct territory to Rangpur division catalog code',
        ])->assertRedirect()->assertSessionHas('success');

        $dealer->refresh();
        $this->assertSame($to->code, $dealer->profile['place_code']);
        $this->assertSame($to->id, $dealer->profile['place_code_id']);
        $this->assertSame($to->code, $dealer->profile['area_code']);
        $this->assertFalse(AreaDealerAssignment::query()->where('user_id', $dealer->id)->where('place_code_id', $from->id)->where('is_active', true)->exists());
        $this->assertTrue(AreaDealerAssignment::query()->where('user_id', $dealer->id)->where('place_code_id', $to->id)->where('is_active', true)->exists());
        $sent = collect(config('madina.sms.sent'))->firstWhere('event', 'account.geography_updated');
        $this->assertNotNull($sent);
        $this->assertStringContainsString($to->code, (string) ($sent['message'] ?? ''));
        $this->assertDatabaseHas('account_notices', [
            'user_id' => $dealer->id,
            'title' => __('portal.notice_geography_updated_title', [], $dealer->preferredLocale()),
        ]);
    }

    public function test_account_update_rejects_rebinding_dealer_onto_taken_place_code(): void
    {
        $admin = User::factory()->create(['role' => 'super_admin']);

        $this->actingAs($admin)->onPortal('sadmin')->post('/commands/accounts', [
            'role' => 'dealer',
            'identity_type' => 'nid',
            'nid' => '1985123456701',
            'date_of_birth' => '1985-05-01',
            'name' => 'Seat Holder',
            'name_bn' => 'অধিকারী',
            'father_name' => 'Father',
            'mother_name' => 'Mother',
            'place_code' => 'MNHC-101',
            'refer_code' => 'REF-SEAT',
            'phone' => '01710000021',
            'email' => 'seat@example.com',
            'reason' => 'Owns Dhaka seat',
        ])->assertRedirect('/accounts');

        $this->actingAs($admin)->onPortal('sadmin')->post('/commands/accounts', [
            'role' => 'dealer',
            'identity_type' => 'nid',
            'nid' => '1985123456702',
            'date_of_birth' => '1986-05-01',
            'name' => 'Other Dealer',
            'name_bn' => 'অন্য',
            'father_name' => 'Father',
            'mother_name' => 'Mother',
            'place_code' => 'MNHC-102',
            'refer_code' => 'REF-OTHER',
            'phone' => '01710000022',
            'email' => 'other-seat@example.com',
            'reason' => 'Owns Rangpur seat',
        ])->assertRedirect('/accounts');

        $other = User::query()->where('email', 'other-seat@example.com')->firstOrFail();
        $this->actingAs($admin)->onPortal('sadmin')->from('/accounts')->patch('/commands/accounts/'.$other->id, [
            'name' => 'Other Dealer',
            'place_code' => 'MNHC-101',
            'reason' => 'Attempt to steal Dhaka seat',
        ])->assertRedirect('/accounts')->assertSessionHasErrors('place_code');

        $this->assertTrue(AreaDealerAssignment::query()->where('user_id', $other->id)->where('place_code_id', PlaceCode::query()->where('code', 'MNHC-102')->value('id'))->where('is_active', true)->exists());
    }
}
