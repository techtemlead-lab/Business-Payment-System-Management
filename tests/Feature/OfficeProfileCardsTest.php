<?php

namespace Tests\Feature;

use App\Domain\Accounts\OfficeProfile;
use App\Models\BranchStaffAssignment;
use App\Models\CompanyBranch;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\TestCase;

/** The office "My profile" page: each card saves on its own, with its own audit line and its own limits. */
class OfficeProfileCardsTest extends TestCase
{
    use RefreshDatabase;

    private const Nid = '1234567890123';

    protected function setUp(): void
    {
        parent::setUp();
        $this->withoutVite();
        Storage::fake('local');
    }

    private function staffMember(array $attributes = []): User
    {
        return User::factory()->staff('support')->create($attributes + ['password' => 'StaffPassword12345']);
    }

    private function asStaff(User $user)
    {
        return $this->actingAs($user)->onPortal('manage');
    }

    public function test_the_contact_card_saves_and_asks_staff_for_the_password_only_when_signing_in_changes(): void
    {
        $staff = $this->staffMember(['phone' => '01711111111']);

        $this->asStaff($staff)->post('/commands/office-profile/contact', ['name' => 'Rahim Uddin', 'name_bn' => 'রহিম উদ্দিন', 'email' => $staff->email, 'phone' => '01711111111', 'phone2' => '01722222222'])
            ->assertSessionHasNoErrors();
        $fresh = $staff->fresh();
        $this->assertSame('Rahim Uddin', $fresh->name);
        $this->assertSame('রহিম উদ্দিন', $fresh->profile['name_bn']);
        $this->assertSame('01722222222', $fresh->profile['phone2']);

        $this->asStaff($fresh)->post('/commands/office-profile/contact', ['name' => 'Rahim Uddin', 'email' => 'new-staff@example.test', 'phone' => '01711111111'])
            ->assertSessionHasErrors('current_password');
        $this->assertNotSame('new-staff@example.test', $fresh->fresh()->email);

        $this->asStaff($fresh)->post('/commands/office-profile/contact', ['name' => 'Rahim Uddin', 'email' => 'new-staff@example.test', 'phone' => '01711111111', 'current_password' => 'StaffPassword12345'])
            ->assertSessionHasNoErrors();
        $this->assertSame('new-staff@example.test', $fresh->fresh()->email);
    }

    public function test_the_contact_card_refuses_bad_input_in_plain_words(): void
    {
        $staff = $this->staffMember();

        $this->asStaff($staff)->post('/commands/office-profile/contact', ['name' => '', 'email' => 'not-an-email'])->assertSessionHasErrors(['name', 'email']);
        $this->asStaff($staff)->post('/commands/office-profile/contact', ['name' => 'Rahim', 'email' => $staff->email, 'phone2' => '12345'])->assertSessionHasErrors('phone2');
    }

    public function test_the_audit_line_names_the_changed_fields_and_never_their_values(): void
    {
        $staff = $this->staffMember();
        $this->asStaff($staff)->post('/commands/office-profile/personal', ['blood_group' => 'O+', 'present_address' => 'House 5, Pirganj', 'emergency_contact_phone' => '01733333333'])
            ->assertSessionHasNoErrors();
        $this->asStaff($staff)->post('/commands/office-profile/identity', ['nid' => self::Nid])->assertSessionHasNoErrors();

        $lines = DB::table('audit_events')->where('actor_id', $staff->id)->where('action', 'like', 'office_profile.%')->get();
        $this->assertCount(2, $lines);
        $everything = $lines->map(fn ($line) => json_encode($line))->implode(' ');
        foreach (['O+', 'House 5', '01733333333', self::Nid] as $secret) {
            $this->assertStringNotContainsString($secret, $everything);
        }
        $personal = json_decode((string) $lines->firstWhere('action', 'office_profile.personal_updated')->metadata, true);
        $this->assertEqualsCanonicalizing(['blood_group', 'present_address', 'emergency_contact_phone'], $personal['changed_fields']);
    }

    public function test_the_personal_card_saves_validates_and_stores_the_sensitive_parts_encrypted(): void
    {
        $staff = $this->staffMember();

        $this->asStaff($staff)->post('/commands/office-profile/personal', ['blood_group' => 'Z', 'gender' => 'robot', 'date_of_birth' => now()->addDay()->toDateString()])
            ->assertSessionHasErrors(['blood_group', 'gender', 'date_of_birth']);

        $this->asStaff($staff)->post('/commands/office-profile/personal', [
            'date_of_birth' => '1990-04-12', 'blood_group' => 'B+', 'gender' => 'male',
            'present_address' => 'Thana Road, Pirganj', 'same_as_present' => true,
            'emergency_contact_name' => 'Karim', 'emergency_contact_relation' => 'Brother', 'emergency_contact_phone' => '01744444444',
        ])->assertSessionHasNoErrors();

        $profile = $staff->fresh()->profile;
        $this->assertSame('Thana Road, Pirganj', $profile['permanent_address']);
        $this->assertSame('B+', $profile['blood_group']);
        $stored = (string) DB::table('users')->where('id', $staff->id)->value('profile');
        foreach (['Thana Road', '1990-04-12', 'Karim', '01744444444'] as $plain) {
            $this->assertStringNotContainsString($plain, $stored, 'Personal details are encrypted at rest.');
        }
    }

    public function test_the_nid_is_masked_after_saving_and_locks_for_staff_once_the_office_verified_it(): void
    {
        $staff = $this->staffMember();

        $this->asStaff($staff)->post('/commands/office-profile/identity', ['nid' => '12345'])->assertSessionHasErrors('nid');
        $this->asStaff($staff)->post('/commands/office-profile/identity', ['nid' => self::Nid, 'nid_photo' => UploadedFile::fake()->image('nid.jpg')])->assertSessionHasNoErrors();

        $this->asStaff($staff->fresh())->get('/profile')->assertOk()->assertInertia(function (Assert $page): void {
            $page->where('data.profile.office.identity.nid_masked', '•••••••••0123')->where('data.profile.office.identity.locked', false)->where('data.profile.office.identity.photo_sent', true);
            $this->assertStringNotContainsString(self::Nid, json_encode($page->toArray()['props']['data']['profile']));
        });
        $this->assertSame('pending', DB::table('kyc_documents')->where('user_id', $staff->id)->value('review_status'));

        // The office verifies it (the owner types it on the staff edit page, which stamps the verification).
        $owner = User::factory()->staff()->create();
        $this->actingAs($owner)->onPortal('sadmin')->patch('/commands/accounts/'.$staff->id, ['nid' => self::Nid, 'reason' => 'checked the card'])->assertSessionHasNoErrors();
        $this->assertTrue(OfficeProfile::nidLocked($staff->fresh()));

        $this->asStaff($staff->fresh())->post('/commands/office-profile/identity', ['nid' => '9876543210'])->assertSessionHasErrors('nid');
        $this->assertSame(self::Nid, $staff->fresh()->nid);
    }

    public function test_the_owner_edits_the_nid_freely_even_after_it_is_on_file(): void
    {
        $owner = User::factory()->staff()->create();
        $owner->forceFill(['nid' => self::Nid])->save();

        $this->actingAs($owner)->onPortal('sadmin')->post('/commands/office-profile/identity', ['nid' => '9876543210'])->assertSessionHasNoErrors();

        $this->assertSame('9876543210', $owner->fresh()->nid);
    }

    public function test_staff_cannot_change_their_own_role_branch_or_designation(): void
    {
        $staff = $this->staffMember(['profile' => ['designation' => 'Clerk']]);
        $branch = CompanyBranch::factory()->create();
        $other = CompanyBranch::factory()->create();
        BranchStaffAssignment::create(['user_id' => $staff->id, 'branch_id' => $branch->id]);
        $attack = ['role' => 'super_admin', 'designation' => 'Boss', 'branch_id' => $other->id, 'permissions' => ['finance'], 'display_title' => 'Owner'];

        $this->asStaff($staff)->post('/commands/office-profile/contact', ['name' => 'Same', 'email' => $staff->email, ...$attack])->assertSessionHasNoErrors();
        $this->asStaff($staff)->post('/commands/office-profile/personal', $attack)->assertSessionHasNoErrors();
        $this->asStaff($staff)->post('/commands/office-profile/title', $attack)->assertForbidden();

        $fresh = $staff->fresh();
        $this->assertSame('support', $fresh->role);
        $this->assertSame('Clerk', $fresh->profile['designation']);
        $this->assertArrayNotHasKey('display_title', $fresh->profile);
        $this->assertSame([$branch->id], BranchStaffAssignment::where('user_id', $staff->id)->pluck('branch_id')->all());

        $this->asStaff($fresh)->get('/profile')->assertInertia(fn (Assert $page) => $page
            ->where('data.profile.office.work.designation', 'Clerk')->where('data.profile.office.is_owner', false)->has('data.profile.office.work.branches', 1));
    }

    public function test_the_owner_sets_a_title_that_shows_under_the_name(): void
    {
        $owner = User::factory()->staff()->create();

        $this->actingAs($owner)->onPortal('sadmin')->post('/commands/office-profile/title', ['display_title' => 'ব্যবস্থাপনা পরিচালক ও প্রতিষ্ঠাতা'])->assertSessionHasNoErrors();

        $this->actingAs($owner->fresh())->onPortal('sadmin')->get('/profile')->assertInertia(fn (Assert $page) => $page
            ->where('data.profile.office.work.display_title', 'ব্যবস্থাপনা পরিচালক ও প্রতিষ্ঠাতা')->where('data.profile.office.is_owner', true));
        $this->assertDatabaseHas('audit_events', ['actor_id' => $owner->id, 'action' => 'office_profile.title_updated']);
    }

    public function test_the_owner_is_asked_to_confirm_only_when_the_switch_requires_it(): void
    {
        // The Super Admin is never blocked (CR-10); the switch still applies to the MD and the Chairman.
        $md = User::factory()->staff('md')->create(['password' => 'OwnerPassword12345', 'phone' => '01712345678']);
        $change = ['name' => 'MD Sahib', 'email' => $md->email, 'phone' => '01712345678'];

        $this->actingAs($md)->onPortal('sadmin')->post('/commands/office-profile/contact', $change)->assertSessionHasErrors('current_password');
        $this->actingAs($md)->onPortal('sadmin')->post('/commands/office-profile/contact', [...$change, 'current_password' => 'OwnerPassword12345'])->assertSessionHasNoErrors();

        $this->actingAs($md)->onPortal('sadmin')->post('/commands/owner/confirmation', ['mode' => 'off', 'version' => 0, 'current_password' => 'OwnerPassword12345'])->assertSessionHasNoErrors();
        $this->actingAs($md->fresh())->onPortal('sadmin')->post('/commands/office-profile/contact', [...$change, 'name' => 'MD Sahib Two'])->assertSessionHasNoErrors();
        $this->assertSame('MD Sahib Two', $md->fresh()->name);
        $this->actingAs($md->fresh())->onPortal('sadmin')->get('/profile')->assertInertia(fn (Assert $page) => $page->where('securityControls.owner_confirmation.method', 'none'));

        $owner = User::factory()->staff()->create(['phone' => '01755555555']);
        $this->actingAs($owner)->onPortal('sadmin')->post('/commands/office-profile/contact', ['name' => 'Owner Sahib', 'email' => 'owner-new@example.test', 'phone' => '01755555555'])->assertSessionHasNoErrors();
    }

    public function test_the_page_wording_has_no_stale_password_line_and_every_event_has_a_label(): void
    {
        foreach (['bn', 'en'] as $locale) {
            $office = require lang_path($locale.'/office_profile.php');
            $portal = require lang_path($locale.'/portal.php');
            $this->assertNotEmpty($office['save_contact'], $locale);
            foreach (['contact', 'personal', 'identity', 'title'] as $event) {
                $this->assertNotEmpty($portal['event_office_profile_'.$event.'_updated'] ?? null, "$locale $event");
            }
        }
        $this->assertSame(array_keys(require lang_path('bn/office_profile.php')), array_keys(require lang_path('en/office_profile.php')));
        $screen = (string) file_get_contents(resource_path('js/components/office/sections/OfficeProfile.tsx'));
        $this->assertStringNotContainsString('owner_contact_body', $screen);
        $this->assertStringNotContainsString('Nothing is asked', (string) file_get_contents(lang_path('en/office_profile.php')));
    }
}
