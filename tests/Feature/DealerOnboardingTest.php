<?php

namespace Tests\Feature;

use App\Models\PlaceCode;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Notification;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\TestCase;

class DealerOnboardingTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->withoutVite();
    }

    public function test_accounts_create_page_requires_accounts_capability(): void
    {
        $admin = User::factory()->create(['role' => 'super_admin']);
        $dealer = User::factory()->create(['role' => 'dealer']);
        $editor = User::factory()->create(['role' => 'editor']);

        $this->actingAs($dealer)->get('/company/accounts/create')->assertForbidden();
        $this->actingAs($editor)->get('/company/accounts/create')->assertForbidden();

        $this->actingAs($admin)->get('/company/accounts/create')
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->component('AccountCreate')
                ->has('roles')
                ->has('sponsors')
            );
    }

    public function test_staff_can_create_dealer_with_manual_code_and_pdf_kyc_fields(): void
    {
        Notification::fake();
        $this->seedClassicPlaceCodes();
        $admin = User::factory()->create(['role' => 'super_admin']);

        $payload = [
            'role' => 'dealer',
            'identity_type' => 'nid',
            'date_of_birth' => '1985-03-03',
            'place_code' => 'MNHC102',
            'dealer_code' => 'D-DHK-001',
            'name' => 'Md. Rafiqul Islam',
            'name_bn' => 'মোঃ রফিকুল ইসলাম',
            'email' => 'rafiq@example.com',
            'phone' => '01712345678',
            'father_name' => 'Late Abdur Rahman',
            'mother_name' => 'Rahima Begum',
            'nid' => '19851234567890123',
            'pharmacy' => 'Madina Care Point Gazipur',
            'division' => 'Dhaka',
            'district' => 'Gazipur',
            'upazila' => 'Kaliakair',
            'union' => 'Chapair',
            'village' => 'Ratanpur',
            'post_office' => 'Kaliakair-1750',
            'latitude' => '24.0667000',
            'longitude' => '90.2167000',
            'directory_visible' => '1',
            'status' => 'active',
            'reason' => 'Physical contract verified and authorized by admin.',
        ];

        $res = $this->actingAs($admin)->post('/commands/accounts', $payload);
        $res->assertRedirect('/accounts');
        $res->assertSessionHas('success');

        $user = User::where('email', 'rafiq@example.com')->first();
        $this->assertNotNull($user);
        $res->assertSessionHas('partner_form.url', '/exports/partner-registration-form/'.$user->id);
        $this->assertSame('dealer', $user->role);
        $this->assertSame('D-DHK-001', $user->dealer_code);
        $this->assertSame('Md. Rafiqul Islam', $user->name);
        $this->assertSame('01712345678', $user->phone);
        $this->assertTrue($user->directory_visible);
        $this->assertSame('19851234567890123', $user->nid); // Decrypted via cast
        $this->assertSame('মোঃ রফিকুল ইসলাম', $user->profile['name_bn']);
        $this->assertSame('Late Abdur Rahman', $user->profile['father_name']);
        $this->assertSame('Rahima Begum', $user->profile['mother_name']);
        // The place code (a division here) decides the area; the typed upazila and union are not taken.
        $this->assertSame('', $user->profile['upazila']);
        $this->assertSame('', $user->profile['union']);
        $this->assertSame('Madina Care Point Gazipur', $user->profile['pharmacy']);

        // Check wallet initialized
        $this->assertDatabaseHas('wallets', ['user_id' => $user->id]);

        // Check audit event
        $this->assertDatabaseHas('audit_events', [
            'actor_id' => $admin->id,
            'action' => 'account.created',
            'subject' => (string) $user->id,
        ]);
    }

    public function test_staff_can_create_subdealer_and_link_active_sponsor(): void
    {
        Notification::fake();
        $this->seedClassicPlaceCodes();
        $admin = User::factory()->create(['role' => 'super_admin']);
        $sponsor = User::factory()->create([
            'role' => 'dealer',
            'status' => 'active',
            'dealer_code' => 'D-DHK-SPONSOR',
        ]);

        $res = $this->actingAs($admin)->post('/commands/accounts', $this->placeOnboarding([
            'role' => 'sub_dealer',
            'place_code' => $this->unionCode(),
            'name' => 'Abul Kashem',
            'email' => 'kashem@example.com',
            'phone' => '01811223344',
            'reference_code' => $sponsor->dealer_code,
            'status' => 'active',
            'reason' => 'Sub-dealer assigned under Gazipur dealer.',
        ]));
        $res->assertRedirect('/sub-dealers');

        $sub = User::where('email', 'kashem@example.com')->first();
        $this->assertNotNull($sub);
        $res->assertSessionHas('partner_form.url', '/exports/partner-registration-form/'.$sub->id);
        $this->assertSame('sub_dealer', $sub->role);
        $this->assertSame('MNHC561-000001', $sub->dealer_code);
        $this->assertSame($sponsor->id, $sub->sponsor_id);
    }

    public function test_subdealer_can_be_published_in_public_directory_when_the_admin_chooses(): void
    {
        $this->seedClassicPlaceCodes();
        $admin = User::factory()->create(['role' => 'super_admin']);

        $this->actingAs($admin)->post('/commands/accounts', $this->placeOnboarding([
            'role' => 'sub_dealer',
            'place_code' => $this->unionCode(),
            'name' => 'Listed Sub',
            'email' => 'err@example.com',
            'phone' => '01811110001',
            'directory_visible' => '1',
        ]))->assertSessionHasNoErrors();

        $this->assertTrue(User::where('email', 'err@example.com')->firstOrFail()->directory_visible);
    }

    public function test_a_partner_needs_a_place_and_a_free_dealer_code(): void
    {
        $this->seedClassicPlaceCodes();
        $admin = User::factory()->create(['role' => 'super_admin']);
        User::factory()->create([
            'role' => 'dealer',
            'dealer_code' => 'MNHC102',
            'email' => 'existing@example.com',
        ]);

        // No place
        $res = $this->actingAs($admin)->post('/commands/accounts', [
            'role' => 'dealer',
            'name' => 'No Place Dealer',
            'email' => 'noplace@example.com',
        ]);
        $res->assertSessionHasErrors('place_code');

        // The place's code is already someone's dealer code
        $res = $this->actingAs($admin)->post('/commands/accounts', $this->placeOnboarding([
            'name' => 'Duplicate Code Dealer',
            'email' => 'dup@example.com',
            'phone' => '01811110001',
        ]));
        $res->assertSessionHasErrors('dealer_code');
    }

    public function test_sponsor_must_be_active_dealer(): void
    {
        $admin = User::factory()->create(['role' => 'super_admin']);
        $this->seedClassicPlaceCodes();
        $suspendedDealer = User::factory()->create(['role' => 'dealer', 'status' => 'suspended', 'dealer_code' => 'D-SUSPENDED']);

        $res = $this->actingAs($admin)->post('/commands/accounts', $this->placeOnboarding([
            'name' => 'Sponsor Test',
            'email' => 'subtest@example.com',
            'phone' => '01811110002',
            'reference_code' => $suspendedDealer->dealer_code,
        ]));
        $res->assertSessionHasErrors('reference_code');
    }

    public function test_a_dealer_on_an_upazila_code_never_gets_a_union_the_person_did_not_choose(): void
    {
        Notification::fake();
        $this->seedClassicPlaceCodes();
        $admin = User::factory()->create(['role' => 'super_admin']);
        $division = PlaceCode::query()->where('code', 'MNHC102')->firstOrFail();
        $district = PlaceCode::factory()->district($division)->create(['code' => 'MNHC362', 'name' => 'Rangpur District']);
        $upazila = PlaceCode::factory()->upazila($district)->create(['code' => 'MNHC462', 'name' => 'Kaunia']);

        $res = $this->actingAs($admin)->post('/commands/accounts', $this->placeOnboarding([
            'place_code' => $upazila->code,
            'dealer_code' => 'D-KAU-001',
            'name' => 'Kaunia Dealer',
            'email' => 'kaunia@example.com',
            'phone' => '01712340001',
            // A name left over from an earlier pick in the form.
            'upazila' => 'Kaunia',
            'union' => 'Rangpur City Corporation',
        ]));
        $res->assertSessionHasNoErrors();

        $profile = User::where('email', 'kaunia@example.com')->firstOrFail()->profile;
        $this->assertSame('Kaunia', $profile['upazila']);
        $this->assertSame('', $profile['union']);
    }

    public function test_a_sub_dealer_gets_the_union_of_the_place_code(): void
    {
        Notification::fake();
        $this->seedClassicPlaceCodes();
        $admin = User::factory()->create(['role' => 'super_admin']);
        $sponsor = User::factory()->create(['role' => 'dealer', 'status' => 'active', 'dealer_code' => 'D-DHK-SPONSOR']);
        $code = $this->unionCode();

        $this->actingAs($admin)->post('/commands/accounts', $this->placeOnboarding([
            'role' => 'sub_dealer',
            'place_code' => $code,
            'name' => 'Union Sub',
            'email' => 'unionsub@example.com',
            'phone' => '01712340002',
            'reference_code' => $sponsor->dealer_code,
            'status' => 'active',
            'union' => 'Some Other Union',
        ]))->assertSessionHasNoErrors();

        $this->assertSame('Test Union', User::where('email', 'unionsub@example.com')->firstOrFail()->profile['union']);
    }

    private function unionCode(): string
    {
        $division = PlaceCode::query()->where('code', 'MNHC102')->firstOrFail();
        $district = PlaceCode::factory()->district($division)->create(['code' => 'MNHC361', 'name' => 'Test District']);
        $upazila = PlaceCode::factory()->upazila($district)->create(['code' => 'MNHC461', 'name' => 'Test Upazila']);

        return (string) PlaceCode::factory()->union($upazila)->create(['code' => 'MNHC561', 'name' => 'Test Union'])->code;
    }

    /**
     * @param  array<string, string>  $overrides
     * @return array<string, string>
     */
    private function placeOnboarding(array $overrides): array
    {
        return [
            'role' => 'dealer',
            'identity_type' => 'birth_certificate',
            'birth_certificate_number' => '19911234567890111',
            'date_of_birth' => '1991-02-02',
            'name_bn' => 'পরীক্ষা ডিলার',
            'father_name' => 'Father',
            'mother_name' => 'Mother',
            'place_code' => 'MNHC102',
            'reason' => 'Onboarding test',
            ...$overrides,
        ];
    }

    public function test_staff_can_update_dealer_map_coordinates(): void
    {
        $admin = User::factory()->create(['role' => 'super_admin']);
        $dealer = User::factory()->create([
            'role' => 'dealer',
            'latitude' => null,
            'longitude' => null,
        ]);

        $this->actingAs($admin)->onPortal('sadmin')->patch('/commands/accounts/'.$dealer->id, [
            'latitude' => '23.8103',
            'longitude' => '90.4125',
            'reason' => 'Verified shop pin from field visit.',
        ])->assertRedirect()->assertSessionHas('success');

        $dealer->refresh();
        $this->assertEqualsWithDelta(23.8103, (float) $dealer->latitude, 0.0001);
        $this->assertEqualsWithDelta(90.4125, (float) $dealer->longitude, 0.0001);

        $this->actingAs($admin)->onPortal('sadmin')->patch('/commands/accounts/'.$dealer->id, [
            'latitude' => '',
            'longitude' => '',
            'reason' => 'Clear unverified map pin until survey completes.',
        ])->assertRedirect()->assertSessionHas('success');

        $dealer->refresh();
        $this->assertNull($dealer->latitude);
        $this->assertNull($dealer->longitude);
    }
}
